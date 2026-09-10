<?php

declare(strict_types=1);

namespace AiTalents;

use AiTalents\Handler\AdminBotHandler;
use AiTalents\Handler\AdminNotifier;
use AiTalents\Handler\CommandHandler;
use AiTalents\Registration\Flow;
use AiTalents\Telegram\ApiException;
use AiTalents\Telegram\Keyboard;
use AiTalents\Telegram\Update;

/**
 * The single entry point every Telegram update goes through.
 *
 * The router owns the order in which the guards and the handlers run:
 *
 *   1. `my_chat_member` — the user blocked or unblocked the bot; the flag is
 *      written to `users.is_blocked` and nothing else happens;
 *   2. non private chats are ignored without a word (the bot is a 1:1 bot);
 *   3. `users.touch()` refreshes the profile row and gives us the locale;
 *   4. the rate limiter drops floods (silently for messages, with a toast for
 *      button taps so the client stops spinning);
 *   5. blocked users are ignored;
 *   6. the optional "subscribe to our channel first" gate;
 *   7. CommandHandler -> AdminBotHandler -> AdminNotifier -> Flow -> fallback.
 *
 * Everything runs inside one try/catch: a webhook must never see a stack trace
 * and the user must never be left without an answer.
 */
final class Router
{
    /** Callback data of the "check my subscription" button (9 bytes, well under the 64 byte budget). */
    public const CHECK_SUBSCRIPTION = 'sub:check';

    /** Chat member statuses that count as "subscribed". */
    private const SUBSCRIBED_STATUSES = ['member', 'administrator', 'creator'];

    /** Callback data prefix owned by the admin handlers. */
    private const ADMIN_PREFIX = 'a:';

    /** Separate rate limit buckets: taps on an inline keyboard must not eat the message budget. */
    private const BUCKET_MESSAGE = 'msg';
    private const BUCKET_CALLBACK = 'cb';

    /** Update kinds this bot reacts to (everything else is ignored). */
    private const HANDLED_TYPES = ['message', 'edited_message', 'callback_query'];

    private ?CommandHandler $commands = null;
    private ?AdminBotHandler $adminBot = null;
    private ?AdminNotifier $notifier = null;
    private ?Flow $flow = null;

    /** The user row of the update being dispatched (used by the error path for the locale). */
    private ?array $current = null;

    public function __construct(private App $app)
    {
    }

    /**
     * Handle one update. Never throws.
     */
    public function dispatch(Update $update): void
    {
        $this->current = null;

        try {
            $this->route($update);
        } catch (\Throwable $e) {
            $this->fail($update, $e);
        }
    }

    /* --------------------------------------------------------------------
     | The pipeline
     */

    /**
     * The dispatch pipeline described in the class docblock.
     */
    private function route(Update $update): void
    {
        $type = $update->type();

        // 1. Block / unblock notifications are bookkeeping, not conversation.
        if ($type === 'my_chat_member') {
            $this->membership($update);

            return;
        }

        // 2. Groups, supergroups and channels are silently ignored.
        if (!$update->isPrivate()) {
            return;
        }

        // 3. Anything exotic (inline queries, polls, ...) is not our business.
        if (!in_array($type, self::HANDLED_TYPES, true)) {
            return;
        }

        $from = $update->from();
        $telegramId = $update->userId();

        if ($from === null || $telegramId === null || $telegramId <= 0) {
            return;
        }

        $isCallback = $type === 'callback_query';

        // 4. Create or refresh the user row — every handler below needs it.
        $user = $this->app->users()->touch($from, $update->chatType());
        $this->current = $user;

        // 5. Flood protection.
        $bucket = $isCallback ? self::BUCKET_CALLBACK : self::BUCKET_MESSAGE;

        if (!$this->app->rateLimiter()->allow($telegramId, $bucket)) {
            $this->app->logger()->info('Rate limit reached', [
                'telegram_id' => $telegramId,
                'bucket'      => $bucket,
            ]);

            // A message is dropped without a word (answering a flood feeds it);
            // a button tap gets a toast, otherwise the client spins forever.
            if ($isCallback) {
                $this->answer($update, Lang::t('error.rate_limit', $this->locale($user)), true);
            }

            return;
        }

        // 6. Users blocked by an administrator.
        if ((int) ($user['is_blocked'] ?? 0) === 1) {
            if ($isCallback) {
                $this->answer($update, Lang::t('error.blocked', $this->locale($user)), true);
            }

            return;
        }

        // 7. "Subscribe to the channel first" gate (disabled when no channel is configured).
        if (!$this->passesChannelGate($user, $update)) {
            return;
        }

        // 8. Handlers, most specific first.
        if ($this->commands()->handle($user, $update)) {
            return;
        }

        if ($isCallback) {
            if ($this->routeAdminCallback($user, $update, $telegramId)) {
                return;
            }
        } elseif ($this->app->isAdmin($telegramId) && $this->adminBot()->handleMessage($user, $update)) {
            return;
        }

        $consumed = $isCallback
            ? $this->flow()->handleCallback($user, $update)
            : $this->flow()->handleMessage($user, $update);

        if ($consumed) {
            return;
        }

        $this->fallback($user, $update);
    }

    /**
     * Callback queries carrying the admin prefix: the menu handler gets the
     * first look, the moderation cards (`a:ok:<id>` / `a:no:<id>`) the second.
     *
     * @param array<string,mixed> $user
     *
     * @return bool True when the update was consumed here.
     */
    private function routeAdminCallback(array $user, Update $update, int $telegramId): bool
    {
        $data = (string) $update->callbackData();

        if (!str_starts_with($data, self::ADMIN_PREFIX)) {
            return false;
        }

        // A demoted (or curious) user must not reach the admin handlers at all.
        if (!$this->app->isAdmin($telegramId)) {
            $this->answer($update, Lang::t('error.no_permission', $this->locale($user)), true);

            return true;
        }

        if ($this->adminBot()->handleCallback($user, $update)) {
            return true;
        }

        return $this->notifier()->handleCallback($user, $update);
    }

    /**
     * The user blocked or unblocked the bot (private chat `my_chat_member`).
     */
    private function membership(Update $update): void
    {
        $blocked = $update->isBotBlocked();

        if (!$blocked && !$update->isBotUnblocked()) {
            // Group/channel membership changes are not interesting for this bot.
            return;
        }

        $telegramId = $update->userId();

        if ($telegramId === null || $telegramId <= 0) {
            return;
        }

        $from = $update->from();

        if ($from !== null) {
            // Make sure the row exists before flipping the flag on it.
            $this->app->users()->touch($from, $update->chatType());
        }

        $this->app->users()->setBlocked($telegramId, $blocked);

        $this->app->audit()->log(
            'tg:' . $telegramId,
            $blocked ? 'bot_blocked' : 'bot_unblocked',
            'user:' . $telegramId
        );

        $this->app->logger()->info($blocked ? 'User blocked the bot' : 'User unblocked the bot', [
            'telegram_id' => $telegramId,
        ]);
    }

    /* --------------------------------------------------------------------
     | Required channel gate
     */

    /**
     * @param array<string,mixed> $user
     *
     * @return bool True when the update may continue down the pipeline.
     */
    private function passesChannelGate(array $user, Update $update): bool
    {
        $channel = $this->requiredChannel();

        if ($channel === '') {
            return true;
        }

        $telegramId = (int) ($user['telegram_id'] ?? 0);

        // Administrators are never locked out — they have to be able to fix a
        // mistyped channel from inside the bot.
        if ($this->app->isAdmin($telegramId)) {
            return true;
        }

        $isCheck = $update->callbackData() === self::CHECK_SUBSCRIPTION;
        $locale = $this->locale($user);
        $member = $this->app->api()->getChatMember($this->channelChatId($channel), $telegramId);

        // Telegram refused to answer: the bot is not in the channel, the name is
        // wrong, ... Log it loudly, the gate stays closed for regular users.
        if ($member === null) {
            $this->app->logger()->warning('Subscription check failed', [
                'channel'     => $channel,
                'telegram_id' => $telegramId,
            ]);

            if ($isCheck) {
                $this->answer($update, Lang::t('error.subscribe_check_failed', $locale), true);

                return false;
            }

            $this->promptSubscription($user, $update, $channel, $locale);

            return false;
        }

        $status = (string) ($member['status'] ?? '');

        if (in_array($status, self::SUBSCRIBED_STATUSES, true)) {
            if (!$isCheck) {
                return true;
            }

            // The user just joined and pressed "check": clear the gate message
            // and drop them straight into the main menu.
            $this->answer($update, '');
            $this->removePrompt($update);
            $this->commands()->start($user, $update);

            return false;
        }

        if ($isCheck) {
            $this->answer($update, Lang::t('error.subscribe_not_yet', $locale), true);

            return false;
        }

        $this->promptSubscription($user, $update, $channel, $locale);

        return false;
    }

    /**
     * Ask the user to join the required channel.
     *
     * @param array<string,mixed> $user
     */
    private function promptSubscription(array $user, Update $update, string $channel, string $locale): void
    {
        // Stop the spinner of whatever button led here before sending anything.
        if ($update->type() === 'callback_query') {
            $this->answer($update, Lang::t('error.subscribe_not_yet', $locale));
        }

        $rows = [];
        $url = $this->channelUrl($channel);

        if ($url !== null) {
            $rows[] = [Keyboard::url(Lang::t('btn.channel_open', $locale), $url)];
        }

        $rows[] = [Keyboard::btn(Lang::t('btn.channel_check', $locale), self::CHECK_SUBSCRIPTION)];

        $this->app->api()->sendMessage(
            (int) ($user['telegram_id'] ?? 0),
            Lang::t('error.subscribe_required', $locale, ['channel' => Text::esc($this->channelDisplay($channel))]),
            ['reply_markup' => Keyboard::inline($rows)]
        );
    }

    /**
     * The configured channel, normalised to a non empty string ('' disables the gate).
     */
    private function requiredChannel(): string
    {
        $channel = $this->app->setting('required_channel', '');

        if (!is_string($channel)) {
            return '';
        }

        return trim($channel);
    }

    /**
     * The value passed to getChatMember: `@name` for public channels, the raw
     * numeric id for private ones.
     */
    private function channelChatId(string $channel): int|string
    {
        if (preg_match('/^-?\d+$/', $channel) === 1) {
            return (int) $channel;
        }

        $name = $this->channelUsername($channel);

        return $name !== null ? '@' . $name : $channel;
    }

    /**
     * A `https://t.me/...` link for the join button, or null when the channel is
     * only known by its numeric id (then the button is simply omitted).
     */
    private function channelUrl(string $channel): ?string
    {
        if (preg_match('~^https?://~i', $channel) === 1) {
            return $channel;
        }

        $name = $this->channelUsername($channel);

        return $name !== null ? 'https://t.me/' . $name : null;
    }

    /**
     * The `@name` (or link) shown inside the message body.
     */
    private function channelDisplay(string $channel): string
    {
        $name = $this->channelUsername($channel);

        if ($name !== null) {
            return '@' . $name;
        }

        return $channel;
    }

    /**
     * Extract the public username from `@name`, `name` or `https://t.me/name`.
     */
    private function channelUsername(string $channel): ?string
    {
        $candidate = $channel;

        if (preg_match('~^https?://(?:www\.)?t\.me/(.+)$~i', $candidate, $matches) === 1) {
            $candidate = $matches[1];
        }

        $candidate = ltrim($candidate, '@');
        $candidate = rtrim($candidate, '/');

        // Private invite links (t.me/+abc, t.me/joinchat/abc) have no username.
        return preg_match('/^[A-Za-z0-9_]{4,32}$/', $candidate) === 1 ? $candidate : null;
    }

    /**
     * Remove the "please subscribe" message once the gate opened.
     */
    private function removePrompt(Update $update): void
    {
        $chatId = $update->chatId();
        $messageId = $update->messageId();

        if ($chatId !== null && $messageId !== null) {
            $this->app->api()->deleteMessage($chatId, $messageId);
        }
    }

    /* --------------------------------------------------------------------
     | Fallback and error handling
     */

    /**
     * Nobody wanted this update: answer with a friendly hint.
     *
     * @param array<string,mixed> $user
     */
    private function fallback(array $user, Update $update): void
    {
        $locale = $this->locale($user);

        if ($update->type() === 'callback_query') {
            // Usually a button from a message that belongs to a finished flow.
            $this->answer($update, Lang::t('error.invalid_choice', $locale), true);

            return;
        }

        $key = $update->command() !== null ? 'error.unknown_command' : 'error.unknown_input';

        $this->app->logger()->debug('Unhandled update', [
            'telegram_id' => (int) ($user['telegram_id'] ?? 0),
            'update_id'   => $update->id(),
            'state'       => (string) ($user['state'] ?? ''),
        ]);

        $this->app->api()->sendMessage((int) ($user['telegram_id'] ?? 0), Lang::t($key, $locale));
    }

    /**
     * Log the throwable and tell the user that something went wrong.
     *
     * This method is the last line of defence: it must not throw either, so
     * every call it makes is wrapped.
     */
    private function fail(Update $update, \Throwable $e): void
    {
        $telegramId = $update->userId() ?? 0;

        try {
            $this->app->logger()->error('Update dispatch failed: ' . $e->getMessage(), [
                'exception'   => get_class($e),
                'where'       => $e->getFile() . ':' . $e->getLine(),
                'update_id'   => $update->id(),
                'type'        => $update->type(),
                'telegram_id' => $telegramId,
                'data'        => $update->callbackData() ?? $update->command(),
            ]);
        } catch (\Throwable $ignored) {
            // The logger already swallows filesystem errors; nothing left to do.
        }

        if ($telegramId <= 0 || !$update->isPrivate()) {
            return;
        }

        // The user blocked the bot mid-conversation: remember it, stay quiet.
        if ($e instanceof ApiException && $e->isBlockedByUser()) {
            try {
                $this->app->users()->setBlocked($telegramId, true);
            } catch (\Throwable $ignored) {
                // The database is unreachable as well — the flag can wait.
            }

            return;
        }

        $locale = $this->current !== null ? $this->locale($this->current) : $this->app->defaultLocale();

        try {
            $this->answer($update, Lang::t('error.generic', $locale), true);
            $this->app->api()->sendMessage($telegramId, Lang::t('error.generic', $locale));
        } catch (\Throwable $ignored) {
            // Telegram is unreachable too — the error is already in the log.
        }
    }

    /* --------------------------------------------------------------------
     | Small helpers
     */

    /**
     * Answer a callback query when there is one (no-op for messages).
     */
    private function answer(Update $update, string $text, bool $alert = false): void
    {
        $id = $update->callbackId();

        if ($id !== null) {
            $this->app->api()->answerCallbackQuery($id, $text, $alert);
        }
    }

    /**
     * @param array<string,mixed> $user
     */
    private function locale(array $user): string
    {
        $locale = $user['locale'] ?? null;

        return Lang::normalize(is_string($locale) && $locale !== '' ? $locale : $this->app->defaultLocale());
    }

    private function commands(): CommandHandler
    {
        return $this->commands ??= new CommandHandler($this->app);
    }

    private function adminBot(): AdminBotHandler
    {
        return $this->adminBot ??= new AdminBotHandler($this->app);
    }

    private function notifier(): AdminNotifier
    {
        return $this->notifier ??= new AdminNotifier($this->app);
    }

    private function flow(): Flow
    {
        return $this->flow ??= new Flow($this->app);
    }
}
