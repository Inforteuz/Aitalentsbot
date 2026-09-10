<?php

declare(strict_types=1);

namespace AiTalents\Handler;

use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Export\XlsxExporter;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Service\BroadcastService;
use AiTalents\Telegram\ApiException;
use AiTalents\Telegram\Keyboard;
use AiTalents\Telegram\Update;
use AiTalents\Text;

/**
 * The admin console that lives inside the bot.
 *
 * One inline menu with six screens — statistics, the newest applications, a
 * search box, the XLSX export, the broadcast composer and the "registration
 * open/closed" switch — plus a link to the web panel when one is configured.
 *
 * Two rules shape this class:
 *
 *  1. **Never trust a callback.** Buttons survive in a chat forever; an admin
 *     may be demoted long after a menu was drawn. Every entry point therefore
 *     asks {@see App::isAdmin()} again before it does anything.
 *  2. **Never make the webhook wait.** Broadcasting is done in bounded slices
 *     (at most {@see self::MAX_BATCHES} batches per button press) and offers a
 *     "continue" button when work is left, so Telegram never times the request
 *     out and no message is sent twice.
 */
final class AdminBotHandler
{
    /** Callback prefix owned by the admin screens. */
    public const PREFIX = 'a:';

    /** Repaint the menu. */
    private const CB_MENU = 'a:menu';

    /** Screens. */
    private const CB_STATS = 'a:stats';
    private const CB_LATEST = 'a:latest';
    private const CB_SEARCH = 'a:search';
    private const CB_EXPORT = 'a:export';

    /** Broadcast composer: ask, send, abort, continue. */
    private const CB_BROADCAST = 'a:bc';
    private const CB_BROADCAST_SEND = 'a:bcs';
    private const CB_BROADCAST_CANCEL = 'a:bcx';
    private const CB_BROADCAST_RESUME = 'a:bcr:';

    /** "a:reg:1" opens the registration, "a:reg:0" closes it. */
    private const CB_REG_TOGGLE = 'a:reg:';

    /** Conversation states owned by this handler. */
    private const STATE_BROADCAST = 'admin:broadcast';
    private const STATE_SEARCH = 'admin:search';

    /** How many applications the "latest" and the search screens show. */
    private const LATEST_LIMIT = 8;
    private const SEARCH_LIMIT = 8;

    /** How many rows the statistics top lists show. */
    private const TOP_LIMIT = 6;

    /** Width of the block-character bars in the statistics report. */
    private const BAR_WIDTH = 10;

    /** Characters the bars are drawn with. */
    private const BAR_FULL = '█';
    private const BAR_EMPTY = '░';

    /** Recipients served by one broadcast batch. */
    private const BATCH_SIZE = 20;

    /** Batches per button press — the hard stop that keeps the webhook alive. */
    private const MAX_BATCHES = 10;

    /** Longest broadcast text accepted from the composer. */
    private const BROADCAST_TEXT_MAX = 3500;

    /** Longest search term accepted. */
    private const SEARCH_TERM_MAX = 64;

    private ?BroadcastService $broadcaster = null;

    public function __construct(private App $app)
    {
    }

    /* --------------------------------------------------------------------
     | The menu
     */

    /**
     * Draw (or repaint) the admin menu.
     *
     * @param array<string,mixed> $user
     * @param ?int                $editMessageId Repaint this message instead of sending a new one.
     */
    public function menu(array $user, ?int $editMessageId = null): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        if (!$this->app->isAdmin($chatId)) {
            $this->send($chatId, Lang::t('admin.no_access', $locale));

            return;
        }

        $open = $this->registrationOpen();

        $lines = [
            Lang::t('admin.menu_title', $locale),
            '',
            '📝 ' . Text::esc(Lang::t('admin.stat_registrations', $locale))
                . ': <b>' . $this->registrationCount() . '</b>',
            ($open ? '🔓 ' : '🔒 ') . Text::esc(Lang::t('common.status', $locale))
                . ': <b>' . Text::esc(Lang::t($open ? 'common.on' : 'common.off', $locale)) . '</b>',
        ];

        $rows = [
            [
                Keyboard::btn(Lang::t('btn.stats', $locale), self::CB_STATS),
                Keyboard::btn(Lang::t('btn.latest', $locale), self::CB_LATEST),
            ],
            [
                Keyboard::btn(Lang::t('btn.search', $locale), self::CB_SEARCH),
                Keyboard::btn(Lang::t('btn.export_xlsx', $locale), self::CB_EXPORT),
            ],
            [Keyboard::btn(Lang::t('btn.broadcast', $locale), self::CB_BROADCAST)],
            [Keyboard::btn(
                Lang::t($open ? 'btn.reg_close' : 'btn.reg_open', $locale),
                self::CB_REG_TOGGLE . ($open ? '0' : '1')
            )],
        ];

        $url = $this->panelUrl();

        if ($url !== null) {
            $rows[] = [Keyboard::url(Lang::t('btn.web_panel', $locale), $url)];
        }

        $this->editOrSend($chatId, $editMessageId, implode("\n", $lines), Keyboard::inline($rows));
    }

    /* --------------------------------------------------------------------
     | Callbacks
     */

    /**
     * Route a button press of the admin screens (prefix `a:`).
     *
     * The two moderation buttons of a registration card (`a:ok:` / `a:no:`)
     * belong to {@see AdminNotifier}; they are handed back to the router.
     *
     * @param array<string,mixed> $user
     *
     * @return bool True when the callback was consumed here.
     */
    public function handleCallback(array $user, Update $u): bool
    {
        $data = (string) $u->callbackData();

        if ($data === '' || !str_starts_with($data, self::PREFIX)) {
            return false;
        }

        if (
            str_starts_with($data, AdminNotifier::CB_APPROVE)
            || str_starts_with($data, AdminNotifier::CB_REJECT)
        ) {
            return false;
        }

        $locale = $this->locale($user);
        $chatId = $this->chatId($user);

        // A button is not a permission: check the caller every single time.
        if (!$this->app->isAdmin($chatId)) {
            $this->answer($u, Lang::t('error.no_permission', $locale), true);

            return true;
        }

        if ($data === self::CB_MENU) {
            $this->answer($u);
            $this->menu($user, $u->messageId());

            return true;
        }

        if ($data === self::CB_STATS) {
            $this->answer($u);
            $this->statsScreen($user, $u);

            return true;
        }

        if ($data === self::CB_LATEST) {
            $this->answer($u);
            $this->latestScreen($user, $u);

            return true;
        }

        if ($data === self::CB_SEARCH) {
            $this->answer($u);
            $this->searchPrompt($user, $u);

            return true;
        }

        if ($data === self::CB_EXPORT) {
            $this->exportDocument($user, $u);

            return true;
        }

        if ($data === self::CB_BROADCAST) {
            $this->answer($u);
            $this->broadcastPrompt($user);

            return true;
        }

        if ($data === self::CB_BROADCAST_SEND) {
            $this->broadcastStart($user, $u);

            return true;
        }

        if ($data === self::CB_BROADCAST_CANCEL) {
            $this->broadcastAbort($user, $u);

            return true;
        }

        if (str_starts_with($data, self::CB_BROADCAST_RESUME)) {
            $this->broadcastContinue($user, $u, (int) substr($data, strlen(self::CB_BROADCAST_RESUME)));

            return true;
        }

        if (str_starts_with($data, self::CB_REG_TOGGLE)) {
            $this->toggleRegistration($user, $u, substr($data, strlen(self::CB_REG_TOGGLE)) === '1');

            return true;
        }

        $this->answer($u, Lang::t('error.invalid_choice', $locale), true);

        return true;
    }

    /* --------------------------------------------------------------------
     | Text input
     */

    /**
     * Serve the two states this handler puts the admin into.
     *
     * @param array<string,mixed> $user
     *
     * @return bool True when the message was consumed.
     */
    public function handleMessage(array $user, Update $u): bool
    {
        $state = (string) ($user['state'] ?? '');

        if ($state !== self::STATE_BROADCAST && $state !== self::STATE_SEARCH) {
            return false;
        }

        $chatId = $this->chatId($user);

        // Demoted while typing: drop the state and let the normal handlers answer.
        if (!$this->app->isAdmin($chatId)) {
            $this->app->users()->clearState($chatId);

            return false;
        }

        $text = (string) ($u->text() ?? '');

        if (trim($text) === '') {
            $this->send($chatId, Lang::t('error.empty', $this->locale($user)));

            return true;
        }

        if ($state === self::STATE_SEARCH) {
            $this->runSearch($user, $text);

            return true;
        }

        $this->composeBroadcast($user, $text);

        return true;
    }

    /* --------------------------------------------------------------------
     | Screens
     */

    /**
     * The compact statistics report, with a block-character bar per row.
     *
     * @param array<string,mixed> $user
     */
    private function statsScreen(array $user, Update $u): void
    {
        $locale = $this->locale($user);
        $stats = $this->app->stats();
        $overview = $stats->overview();

        $lines = [Lang::t('admin.stats_title', $locale), ''];

        $cards = [
            ['👥', 'admin.stat_users', (string) $overview['users']],
            ['📝', 'admin.stat_registrations', (string) $overview['registrations']],
            ['📅', 'admin.stat_today', (string) $overview['today']],
            ['📆', 'admin.stat_yesterday', (string) $overview['yesterday']],
            ['🗓', 'admin.stat_week', (string) $overview['week']],
            ['📈', 'admin.stat_month', (string) $overview['month']],
            ['⏳', 'admin.stat_pending', (string) $overview['pending']],
            ['✅', 'admin.stat_approved', (string) $overview['approved']],
            ['❌', 'admin.stat_rejected', (string) $overview['rejected']],
            ['🚫', 'admin.stat_blocked', (string) $overview['blocked']],
            ['🎯', 'admin.stat_conversion', number_format((float) $overview['conversion'], 1, '.', ' ') . '%'],
        ];

        foreach ($cards as $card) {
            $lines[] = $card[0] . ' ' . Text::esc(Lang::t($card[1], $locale))
                . ': <b>' . Text::esc($card[2]) . '</b>';
        }

        $tops = [
            'admin.top_directions' => $stats->byDirection(),
            'admin.top_districts'  => $stats->byDistrict(),
        ];

        foreach ($tops as $titleKey => $rows) {
            if ($rows === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = Lang::t($titleKey, $locale);

            $max = 0;

            foreach ($rows as $row) {
                $max = max($max, (int) $row['count']);
            }

            foreach (array_slice($rows, 0, self::TOP_LIMIT) as $row) {
                $label = $locale === 'ru' ? (string) $row['label_ru'] : (string) $row['label_uz'];
                $count = (int) $row['count'];

                $lines[] = '<code>' . $this->bar($count, $max) . '</code> <b>' . $count . '</b> · '
                    . Text::esc(Text::truncate($label, 24));
            }
        }

        $this->screen($user, $u, implode("\n", $lines), self::CB_STATS);
    }

    /**
     * The newest applications.
     *
     * @param array<string,mixed> $user
     */
    private function latestScreen(array $user, Update $u): void
    {
        $locale = $this->locale($user);
        $rows = $this->app->registrations()->latest(self::LATEST_LIMIT);

        $blocks = [Lang::t('admin.latest_title', $locale)];

        if ($rows === []) {
            $blocks[] = Lang::t('admin.latest_empty', $locale);
        } else {
            foreach ($rows as $row) {
                $blocks[] = $this->registrationCard($row, $locale);
            }
        }

        $this->screen($user, $u, implode("\n\n", $blocks), self::CB_LATEST);
    }

    /**
     * Ask for a search term and park the admin in the search state.
     *
     * @param array<string,mixed> $user
     */
    private function searchPrompt(array $user, Update $u): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        $this->app->users()->setState($chatId, self::STATE_SEARCH, []);

        $text = Lang::t('admin.search_prompt', $locale) . "\n\n" . Lang::t('admin.cancel_hint', $locale);

        // The prompt is a new message so the reply box stays in focus; its
        // "cancel" button turns that very message back into the menu.
        $this->send($chatId, $text, [
            'reply_markup' => Keyboard::inline([[
                Keyboard::btn(Lang::t('btn.cancel', $locale), self::CB_MENU),
            ]]),
        ]);
    }

    /**
     * Answer a search term with the matching application cards.
     *
     * @param array<string,mixed> $user
     */
    private function runSearch(array $user, string $term): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);
        $query = Text::clean($term, self::SEARCH_TERM_MAX);

        $this->app->users()->clearState($chatId);

        if ($query === '') {
            $this->send($chatId, Lang::t('admin.search_empty', $locale), $this->backExtra($locale));

            return;
        }

        $repository = $this->app->registrations();
        $total = $repository->countAll(['q' => $query]);
        $rows = $total > 0 ? $repository->search($query, self::SEARCH_LIMIT) : [];

        if ($rows === []) {
            $this->send($chatId, Lang::t('admin.search_empty', $locale), $this->backExtra($locale));

            return;
        }

        $blocks = [Lang::t('admin.search_results', $locale, ['count' => $total])];

        foreach ($rows as $row) {
            $blocks[] = $this->registrationCard($row, $locale);
        }

        $this->send($chatId, implode("\n\n", $blocks), $this->backExtra($locale));
    }

    /**
     * Build the XLSX workbook and upload it as a document.
     *
     * The file is written into a private directory under `data/` so Telegram
     * receives a real file name, and removed again afterwards.
     *
     * @param array<string,mixed> $user
     */
    private function exportDocument(array $user, Update $u): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        // Callback answers are plain text — the markup of the phrase has to go.
        $this->answer($u, strip_tags(Lang::t('admin.export_preparing', $locale)));
        $this->app->api()->sendChatAction($chatId, 'upload_document');

        $directory = null;
        $path = null;

        try {
            $exporter = new XlsxExporter($this->app->registrations());
            $directory = $this->temporaryDirectory();
            $path = $directory . '/' . $exporter->filename($locale);
            $rows = $exporter->toFile($path, [], $locale);

            if ($rows === 0) {
                $this->send($chatId, Lang::t('admin.export_empty', $locale), $this->backExtra($locale));

                return;
            }

            $this->app->api()->sendDocument($chatId, $path, [
                'caption' => Lang::t('admin.export_ready', $locale, ['count' => $rows]),
            ]);

            $this->app->audit()->log('tg:' . $chatId, 'export', 'registrations', ['rows' => $rows]);
        } catch (\Throwable $e) {
            $this->app->logger()->error('XLSX export failed: ' . $e->getMessage(), [
                'exception'   => get_class($e),
                'telegram_id' => $chatId,
            ]);

            $this->send($chatId, Lang::t('error.file', $locale), $this->backExtra($locale));
        } finally {
            $this->cleanTemporary($directory, $path);
        }
    }

    /**
     * Open or close the registration and repaint the menu.
     *
     * @param array<string,mixed> $user
     */
    private function toggleRegistration(array $user, Update $u, bool $open): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        try {
            $this->app->settings()->set('registration_open', $open);
        } catch (\Throwable $e) {
            $this->app->logger()->error('Registration switch could not be stored', [
                'error' => $e->getMessage(),
            ]);

            $this->answer($u, Lang::t('error.db', $locale), true);

            return;
        }

        $this->app->audit()->log(
            'tg:' . $chatId,
            $open ? 'registration_opened' : 'registration_closed',
            'settings:registration_open',
            ['value' => $open]
        );

        $this->answer($u, strip_tags(Lang::t($open ? 'admin.reg_opened' : 'admin.reg_closed', $locale)));
        $this->menu($user, $u->messageId());
    }

    /* --------------------------------------------------------------------
     | Broadcast composer
     */

    /**
     * Ask for the message text.
     *
     * @param array<string,mixed> $user
     */
    private function broadcastPrompt(array $user): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        $this->app->users()->setState($chatId, self::STATE_BROADCAST, []);

        $this->send(
            $chatId,
            Lang::t('admin.broadcast_prompt', $locale) . "\n\n" . Lang::t('admin.cancel_hint', $locale),
            [
                'reply_markup' => Keyboard::inline([[
                    Keyboard::btn(Lang::t('btn.cancel', $locale), self::CB_BROADCAST_CANCEL),
                ]]),
            ]
        );
    }

    /**
     * Show the preview of a typed broadcast together with the recipient count.
     *
     * The text is sent exactly as the administrator wrote it — HTML formatting
     * is the documented feature of this screen — so it is deliberately NOT
     * escaped. Broken markup is caught here: Telegram refuses the preview and
     * the composer stays open instead of failing later, in the middle of a run.
     *
     * @param array<string,mixed> $user
     */
    private function composeBroadcast(array $user, string $text): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);
        $message = Text::clean($text, self::BROADCAST_TEXT_MAX);

        if ($message === '') {
            $this->send($chatId, Lang::t('admin.broadcast_empty_text', $locale));

            return;
        }

        $recipients = $this->broadcaster()->audienceCount([]);

        if ($recipients === 0) {
            $this->app->users()->clearState($chatId);
            $this->send($chatId, Lang::t('admin.broadcast_empty_audience', $locale), $this->backExtra($locale));

            return;
        }

        $preview = Lang::t('admin.broadcast_preview', $locale) . "\n\n"
            . $message . "\n\n"
            . Lang::t('admin.broadcast_confirm', $locale, ['count' => $recipients]);

        $markup = Keyboard::inline([[
            Keyboard::btn(Lang::t('btn.send', $locale), self::CB_BROADCAST_SEND),
            Keyboard::btn(Lang::t('btn.cancel', $locale), self::CB_BROADCAST_CANCEL),
        ]]);

        try {
            $this->app->api()->sendMessage($chatId, $preview, ['reply_markup' => $markup]);
        } catch (ApiException $e) {
            // Almost always an unbalanced tag in the admin's own HTML.
            $this->app->logger()->info('Broadcast preview refused by Telegram', [
                'error' => $e->description(),
            ]);

            $this->app->users()->setState($chatId, self::STATE_BROADCAST, []);
            $this->send(
                $chatId,
                Lang::t('error.telegram', $locale) . "\n\n" . Lang::t('admin.broadcast_prompt', $locale)
            );

            return;
        }

        // Only a text Telegram accepted is worth remembering.
        $this->app->users()->setState($chatId, self::STATE_BROADCAST, ['text' => $message]);
    }

    /**
     * Queue the composed broadcast and deliver the first slices.
     *
     * @param array<string,mixed> $user
     */
    private function broadcastStart(array $user, Update $u): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        $data = $this->app->users()->stateData($chatId);
        $message = trim((string) ($data['text'] ?? ''));

        if ($message === '') {
            $this->answer($u, Lang::t('admin.broadcast_empty_text', $locale), true);

            return;
        }

        $filters = isset($data['filters']) && is_array($data['filters']) ? $data['filters'] : [];
        $service = $this->broadcaster();
        $recipients = $service->audienceCount($filters);

        if ($recipients === 0) {
            $this->app->users()->clearState($chatId);
            $this->answer($u, Lang::t('admin.broadcast_empty_audience', $locale), true);

            return;
        }

        $this->answer($u);
        $this->app->users()->clearState($chatId);

        try {
            $broadcastId = $service->prepare($chatId, $message, $filters);
        } catch (\Throwable $e) {
            $this->app->logger()->error('Broadcast could not be prepared', ['error' => $e->getMessage()]);
            $this->editOrSend($chatId, $u->messageId(), Lang::t('error.generic', $locale), null);

            return;
        }

        // The preview turns into the progress message.
        $messageId = $this->editOrSend(
            $chatId,
            $u->messageId(),
            Lang::t('admin.broadcast_started', $locale, ['total' => $recipients]),
            null
        );

        $this->pump($chatId, $messageId, $broadcastId, $locale);
    }

    /**
     * Leave the composer without sending anything.
     *
     * @param array<string,mixed> $user
     */
    private function broadcastAbort(array $user, Update $u): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        $this->app->users()->clearState($chatId);
        $this->answer($u, strip_tags(Lang::t('admin.broadcast_cancelled', $locale)));

        $this->editOrSend(
            $chatId,
            $u->messageId(),
            Lang::t('admin.broadcast_cancelled', $locale),
            $this->backMarkup($locale)
        );
    }

    /**
     * Deliver the next slices of a broadcast that still has recipients left.
     *
     * @param array<string,mixed> $user
     */
    private function broadcastContinue(array $user, Update $u, int $broadcastId): void
    {
        $chatId = $this->chatId($user);
        $locale = $this->locale($user);

        if ($broadcastId <= 0) {
            $this->answer($u, Lang::t('error.invalid_choice', $locale), true);

            return;
        }

        $this->answer($u);
        $this->broadcaster()->resume($broadcastId);
        $this->pump($chatId, $u->messageId(), $broadcastId, $locale);
    }

    /**
     * Run at most {@see self::MAX_BATCHES} batches, refreshing a progress
     * message in between, then report the outcome.
     *
     * Whatever is left gets a "continue" button: pressing it lands right back
     * here, which keeps every single webhook request short.
     */
    private function pump(int $chatId, ?int $messageId, int $broadcastId, string $locale): void
    {
        $service = $this->broadcaster();
        $batches = 0;

        while ($batches < self::MAX_BATCHES) {
            $result = $service->runBatch($broadcastId, self::BATCH_SIZE);
            $batches++;

            if ($result['done']) {
                break;
            }

            $progress = $service->progress($broadcastId);

            $messageId = $this->editOrSend(
                $chatId,
                $messageId,
                Lang::t('admin.broadcast_progress', $locale, [
                    'sent'  => $progress['sent'],
                    'total' => $progress['total'],
                ]),
                null
            );
        }

        $progress = $service->progress($broadcastId);

        if ($progress['remaining'] > 0) {
            $text = Lang::t('admin.broadcast_progress', $locale, [
                'sent'  => $progress['sent'],
                'total' => $progress['total'],
            ]);

            $markup = Keyboard::inline([
                [Keyboard::btn(
                    Lang::t('panel.broadcast_resume', $locale),
                    self::CB_BROADCAST_RESUME . $broadcastId
                )],
                [Keyboard::btn(Lang::t('btn.admin_menu', $locale), self::CB_MENU)],
            ]);
        } else {
            $text = Lang::t('admin.broadcast_done', $locale, [
                'sent'   => $progress['sent'],
                'failed' => $progress['failed'],
            ]);

            $markup = $this->backMarkup($locale);
        }

        $this->editOrSend($chatId, $messageId, $text, $markup);
    }

    /* --------------------------------------------------------------------
     | Rendering helpers
     */

    /**
     * One application as a compact card for the list screens.
     *
     * @param array<string,mixed> $row
     */
    private function registrationCard(array $row, string $locale): string
    {
        $status = RegistrationStatus::tryOrNull($row['status'] ?? null) ?? RegistrationStatus::default();
        $name = trim((string) ($row['full_name'] ?? ''));

        $lines = [];
        $lines[] = $status->emoji() . ' <b>#' . (int) ($row['id'] ?? 0) . '</b> · '
            . '<b>' . Text::esc(Text::truncate($name, 48)) . '</b>';

        $meta = [];
        $phone = trim((string) ($row['phone'] ?? ''));

        if ($phone !== '') {
            $meta[] = '📱 ' . Text::esc(Text::phoneDisplay($phone));
        }

        $district = trim((string) ($row['district'] ?? ''));

        if ($district !== '') {
            $meta[] = '🏙 ' . Text::esc(Catalog::districtLabel($district, $locale));
        }

        $birthYear = (int) ($row['birth_year'] ?? 0);

        if ($birthYear > 0) {
            $meta[] = '🎂 ' . $birthYear;
        }

        if ($meta !== []) {
            $lines[] = implode(' · ', $meta);
        }

        $directions = Catalog::directionLabels(
            Catalog::filterDirections($row['directions'] ?? []),
            $locale,
            false
        );

        if ($directions !== []) {
            $lines[] = '🎯 ' . Text::esc(Text::truncate(implode(', ', $directions), 90));
        }

        $tail = [$this->profileLink($row)];
        $created = trim((string) ($row['created_at'] ?? ''));

        if ($created !== '') {
            $tail[] = '🕒 ' . Text::esc($created);
        }

        $lines[] = implode(' · ', $tail);

        return implode("\n", $lines);
    }

    /**
     * A tappable link to the applicant's Telegram profile.
     *
     * @param array<string,mixed> $row
     */
    private function profileLink(array $row): string
    {
        $telegramId = (int) ($row['telegram_id'] ?? 0);
        $username = $row['username'] ?? null;

        if (is_string($username)) {
            $username = ltrim(trim($username), '@');

            if (preg_match('/^[A-Za-z0-9_]{4,32}$/', $username) === 1) {
                return '💬 <a href="https://t.me/' . $username . '">@' . $username . '</a>';
            }
        }

        if ($telegramId !== 0) {
            return '💬 <a href="tg://user?id=' . $telegramId . '"><code>' . $telegramId . '</code></a>';
        }

        return '💬 —';
    }

    /**
     * A horizontal bar built from block characters, ten cells wide.
     */
    private function bar(int $value, int $max): string
    {
        if ($max <= 0 || $value <= 0) {
            return str_repeat(self::BAR_EMPTY, self::BAR_WIDTH);
        }

        // Anything above zero deserves at least one visible cell.
        $filled = (int) round($value / $max * self::BAR_WIDTH);
        $filled = max(1, min(self::BAR_WIDTH, $filled));

        return str_repeat(self::BAR_FULL, $filled) . str_repeat(self::BAR_EMPTY, self::BAR_WIDTH - $filled);
    }

    /**
     * Show a screen in place of the menu, with "refresh" and "back" buttons.
     *
     * @param array<string,mixed> $user
     */
    private function screen(array $user, Update $u, string $text, string $refreshData): void
    {
        $locale = $this->locale($user);

        $markup = Keyboard::inline([[
            Keyboard::btn(Lang::t('btn.refresh', $locale), $refreshData),
            Keyboard::btn(Lang::t('btn.back', $locale), self::CB_MENU),
        ]]);

        $this->editOrSend($this->chatId($user), $u->messageId(), $text, $markup);
    }

    /** A single "back to the admin menu" button. */
    private function backMarkup(string $locale): string
    {
        return Keyboard::inline([[Keyboard::btn(Lang::t('btn.admin_menu', $locale), self::CB_MENU)]]);
    }

    /**
     * The same button as an `extra` array for {@see self::send()}.
     *
     * @return array<string,mixed>
     */
    private function backExtra(string $locale): array
    {
        return ['reply_markup' => $this->backMarkup($locale)];
    }

    /* --------------------------------------------------------------------
     | Telegram plumbing — a failing API call must never break a screen
     */

    /**
     * Repaint `$messageId` when there is one, otherwise send a new message.
     *
     * @return ?int The id of the message that now carries the text.
     */
    private function editOrSend(int $chatId, ?int $messageId, string $text, ?string $markup): ?int
    {
        if ($chatId === 0 || $text === '') {
            return $messageId;
        }

        $extra = [];

        if ($markup !== null && $markup !== '') {
            $extra['reply_markup'] = $markup;
        }

        if ($messageId !== null && $messageId > 0) {
            try {
                $this->app->api()->editMessageText($chatId, $messageId, $text, $extra);

                return $messageId;
            } catch (\Throwable $e) {
                // Too old to edit, or the text grew past the edit limit.
                $this->app->logger()->debug('Admin screen could not be edited in place', [
                    'chat_id' => $chatId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return $this->send($chatId, $text, $extra);
    }

    /**
     * @param array<string,mixed> $extra
     *
     * @return ?int The id of the sent message.
     */
    private function send(int $chatId, string $text, array $extra = []): ?int
    {
        if ($chatId === 0 || $text === '') {
            return null;
        }

        try {
            $result = $this->app->api()->sendMessage($chatId, $text, $extra);
            $messageId = $result['message_id'] ?? null;

            return is_numeric($messageId) ? (int) $messageId : null;
        } catch (ApiException $e) {
            $this->app->logger()->warning('Admin message could not be delivered', [
                'chat_id' => $chatId,
                'error'   => $e->description(),
            ]);
        } catch (\Throwable $e) {
            $this->app->logger()->error('Unexpected Telegram failure', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    /** Answer a callback query so the client stops spinning. */
    private function answer(Update $u, string $text = '', bool $alert = false): void
    {
        $id = $u->callbackId();

        if ($id === null) {
            return;
        }

        try {
            $this->app->api()->answerCallbackQuery($id, $text, $alert);
        } catch (\Throwable $e) {
            $this->app->logger()->debug('Callback query could not be answered', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /* --------------------------------------------------------------------
     | Small helpers
     */

    private function broadcaster(): BroadcastService
    {
        return $this->broadcaster ??= new BroadcastService($this->app);
    }

    /**
     * How many applications exist (0 when the table is not reachable).
     */
    private function registrationCount(): int
    {
        try {
            return $this->app->registrations()->total();
        } catch (\Throwable $e) {
            $this->app->logger()->warning('Could not count the registrations', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * `registration_open` from the settings table, falling back to config.php.
     */
    private function registrationOpen(): bool
    {
        $value = $this->app->setting('registration_open', true);

        if (is_bool($value)) {
            return $value;
        }

        if ($value === null) {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'off', 'no', 'null'], true);
        }

        return (bool) $value;
    }

    /**
     * The admin panel URL derived from `app.base_url`, or null when it is not
     * configured (Telegram only accepts http(s) URL buttons).
     */
    private function panelUrl(): ?string
    {
        $base = rtrim(trim((string) $this->app->config('app.base_url', '')), '/');

        if ($base === '' || preg_match('~^https?://~i', $base) !== 1) {
            return null;
        }

        return $base . '/admin/';
    }

    /**
     * A private directory under data/ for one export file.
     */
    private function temporaryDirectory(): string
    {
        $root = defined('AITALENTS_ROOT') ? (string) AITALENTS_ROOT : dirname(__DIR__, 2);
        $directory = $root . '/data/exports/' . bin2hex(random_bytes(6));

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the export directory ' . $directory . '.');
        }

        return $directory;
    }

    /**
     * Remove the temporary export file and its directory.
     */
    private function cleanTemporary(?string $directory, ?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        if ($directory !== null && is_dir($directory)) {
            @rmdir($directory);
        }
    }

    /**
     * The private chat id of a user row (equal to the Telegram user id).
     *
     * @param array<string,mixed> $user
     */
    private function chatId(array $user): int
    {
        $id = $user['telegram_id'] ?? 0;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * @param array<string,mixed> $user
     */
    private function locale(array $user): string
    {
        $locale = $user['locale'] ?? null;

        return Lang::normalize(is_string($locale) && $locale !== '' ? $locale : $this->app->defaultLocale());
    }
}
