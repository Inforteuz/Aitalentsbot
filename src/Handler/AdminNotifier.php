<?php

declare(strict_types=1);

namespace AiTalents\Handler;

use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Telegram\ApiException;
use AiTalents\Telegram\Keyboard;
use AiTalents\Telegram\Update;
use AiTalents\Text;

/**
 * The moderation card of a submitted application.
 *
 * As soon as the registration flow stores an application every administrator
 * (and the optional `telegram.admin_chat_id` group) receives the same card with
 * an "approve" and a "reject" button. Pressing one of them writes the decision,
 * leaves an audit entry, rewrites the card so everybody sees who decided what
 * and finally tells the applicant.
 *
 * Delivery is best effort per recipient: an administrator who never pressed
 * "start" in the bot cannot be written to, and that must not stop the card from
 * reaching the others.
 */
final class AdminNotifier
{
    /** Callback data of the approve button ("a:ok:<registration id>"). */
    public const CB_APPROVE = 'a:ok:';

    /** Callback data of the reject button ("a:no:<registration id>"). */
    public const CB_REJECT = 'a:no:';

    /** Emoji used in front of the card rows. */
    private const FIELD_EMOJI = [
        'full_name'  => '👤',
        'phone'      => '📱',
        'birth_year' => '🎂',
        'district'   => '🏙',
        'direction'  => '🎯',
        'portfolio'  => '🔗',
        'user'       => '💬',
        'id'         => '🆔',
        'created'    => '🕒',
        'status'     => '🏷',
    ];

    /** How much of a portfolio text the card shows. */
    private const PORTFOLIO_PREVIEW = 500;

    public function __construct(private App $app)
    {
    }

    /* --------------------------------------------------------------------
     | Outgoing card
     */

    /**
     * Push a fresh application to everybody who may moderate it.
     *
     * @param array<string,mixed> $registration The stored application row.
     * @param array<string,mixed> $user         The applicant's `users` row.
     */
    public function newRegistration(array $registration, array $user): void
    {
        $id = (int) ($registration['id'] ?? 0);

        if ($id <= 0) {
            $this->app->logger()->warning('AdminNotifier received a registration without an id.');

            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            $this->app->logger()->warning('A registration was submitted but no admin is configured', [
                'registration' => $id,
            ]);

            return;
        }

        $status = $this->statusOf($registration);

        foreach ($recipients as $chatId) {
            $locale = $this->recipientLocale($chatId);
            $extra = [];

            // Only an application that is still waiting gets decision buttons.
            if ($status === RegistrationStatus::Pending) {
                $extra['reply_markup'] = $this->decisionKeyboard($id, $locale);
            }

            try {
                $this->app->api()->sendMessage($chatId, $this->card($registration, $user, $locale), $extra);
            } catch (ApiException $e) {
                // Typical case: the administrator never started a chat with the
                // bot, so Telegram refuses with 403. Log it and serve the rest.
                $this->app->logger()->warning('Admin card could not be delivered', [
                    'chat_id'      => $chatId,
                    'registration' => $id,
                    'error'        => $e->description(),
                ]);
            } catch (\Throwable $e) {
                $this->app->logger()->error('Unexpected failure while notifying an admin', [
                    'chat_id'      => $chatId,
                    'registration' => $id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Render the application card.
     *
     * Every dynamic value is escaped; the only markup comes from this method
     * and from the language file.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $user The applicant's `users` row (may be empty).
     */
    public function card(array $registration, array $user, string $locale = 'uz'): string
    {
        $locale = Lang::normalize($locale);
        $id = (int) ($registration['id'] ?? 0);

        $rows = [];
        $rows[] = Lang::t('admin.new_registration_title', $locale, ['id' => $id]);
        $rows[] = '';

        $rows[] = $this->line(
            'full_name',
            'profile.field_name',
            $locale,
            '<b>' . Text::esc($this->value($registration['full_name'] ?? null, $locale)) . '</b>'
        );

        $rows[] = $this->line('phone', 'profile.field_phone', $locale, $this->phoneHtml($registration, $locale));

        $birthYear = (int) ($registration['birth_year'] ?? 0);

        if ($birthYear > 0) {
            $rows[] = $this->line(
                'birth_year',
                'profile.field_birth_year',
                $locale,
                '<b>' . Text::esc((string) $birthYear) . '</b>'
            );
        }

        $district = trim((string) ($registration['district'] ?? ''));

        if ($district !== '') {
            $rows[] = $this->line(
                'district',
                'profile.field_district',
                $locale,
                '<b>' . Text::esc(Catalog::districtLabel($district, $locale)) . '</b>'
            );
        }

        foreach ($this->directionRows($registration, $locale) as $row) {
            $rows[] = $row;
        }

        foreach ($this->portfolioRows($registration, $locale) as $row) {
            $rows[] = $row;
        }

        $rows[] = '';
        $rows[] = $this->line('user', 'admin.card_user', $locale, $this->userHtml($registration, $user));
        $rows[] = $this->line('id', 'profile.field_id', $locale, '<b>#' . $id . '</b>');
        $rows[] = $this->line(
            'created',
            'profile.field_created',
            $locale,
            Text::esc($this->value($registration['created_at'] ?? null, $locale))
        );

        $status = $this->statusOf($registration);
        $rows[] = $this->line(
            'status',
            'profile.field_status',
            $locale,
            '<b>' . Text::esc($status->display($locale)) . '</b>'
        );

        return implode("\n", $rows);
    }

    /* --------------------------------------------------------------------
     | Incoming decision
     */

    /**
     * Handle the "approve"/"reject" buttons of a card.
     *
     * @param array<string,mixed> $user The `users` row of the admin who tapped.
     *
     * @return bool True when the callback belonged to this handler.
     */
    public function handleCallback(array $user, Update $u): bool
    {
        $data = (string) $u->callbackData();

        $approve = str_starts_with($data, self::CB_APPROVE);
        $reject = str_starts_with($data, self::CB_REJECT);

        if (!$approve && !$reject) {
            return false;
        }

        $locale = $this->locale($user);
        $adminId = (int) ($user['telegram_id'] ?? 0);

        // The button alone proves nothing: an admin may have been demoted since
        // the card was sent, or the card may sit in a group somebody else reads.
        if (!$this->app->isAdmin($adminId)) {
            $this->answer($u, Lang::t('error.no_permission', $locale), true);

            return true;
        }

        $prefix = $approve ? self::CB_APPROVE : self::CB_REJECT;
        $id = (int) substr($data, strlen($prefix));
        $registration = $id > 0 ? $this->app->registrations()->findById($id) : null;

        if ($registration === null) {
            $this->answer($u, Lang::t('error.not_found', $locale), true);
            $this->stripButtons($u);

            return true;
        }

        $current = $this->statusOf($registration);

        // Two administrators tapped at the same time — the first one wins.
        if ($current !== RegistrationStatus::Pending) {
            $this->answer(
                $u,
                Lang::t('admin.already_reviewed', $locale, ['status' => $current->label($locale)]),
                true
            );
            $this->repaint($u, $registration, $locale, null, null);

            return true;
        }

        $target = $approve ? RegistrationStatus::Approved : RegistrationStatus::Rejected;
        $actor = 'tg:' . $adminId;

        if (!$this->app->registrations()->setStatus($id, $target->value, $actor)) {
            $this->answer($u, Lang::t('error.generic', $locale), true);

            return true;
        }

        $this->app->audit()->log(
            $actor,
            $approve ? 'registration_approved' : 'registration_rejected',
            'registration:' . $id,
            ['status' => $target->value]
        );

        $registration['status'] = $target->value;
        $registration['reviewed_by'] = $adminId;
        $registration['reviewed_at'] = App::now();

        $notified = $this->notifyApplicant($registration, $target);

        $this->repaint($u, $registration, $locale, $this->actorLabel($user), $notified);

        // A callback answer is plain text: the markup of the phrase has to go.
        $this->answer($u, strip_tags(Lang::t('status.changed', $locale, [
            'id'     => $id,
            'status' => $target->label($locale),
        ])));

        return true;
    }

    /* --------------------------------------------------------------------
     | Rendering helpers
     */

    /**
     * The direction rows of the card (empty when nothing was selected).
     *
     * @param array<string,mixed> $registration
     *
     * @return string[]
     */
    private function directionRows(array $registration, string $locale): array
    {
        $directions = Catalog::filterDirections($registration['directions'] ?? []);
        $other = trim((string) ($registration['direction_other'] ?? ''));

        if ($directions === [] && $other === '') {
            return [];
        }

        $rows = [];
        $rows[] = $this->emoji('direction') . ' ' . Text::esc(Lang::t('profile.field_direction', $locale)) . ':';

        foreach (Catalog::directionLabels($directions, $locale) as $label) {
            $rows[] = '   • ' . Text::esc($label);
        }

        if ($other !== '') {
            $rows[] = '   • ' . Text::esc(Text::truncate($other, 160));
        }

        return $rows;
    }

    /**
     * The portfolio rows of the card (empty when the step was skipped).
     *
     * @param array<string,mixed> $registration
     *
     * @return string[]
     */
    private function portfolioRows(array $registration, string $locale): array
    {
        $portfolio = trim((string) ($registration['portfolio'] ?? ''));

        if ($portfolio === '') {
            return [];
        }

        return [
            $this->emoji('portfolio') . ' ' . Text::esc(Lang::t('profile.field_portfolio', $locale)) . ':',
            Text::multiline($portfolio, self::PORTFOLIO_PREVIEW),
        ];
    }

    /**
     * The phone number as a `tel:` link, so an organiser can call with one tap.
     *
     * @param array<string,mixed> $registration
     */
    private function phoneHtml(array $registration, string $locale): string
    {
        $phone = trim((string) ($registration['phone'] ?? ''));

        if ($phone === '') {
            return '<b>' . Text::esc(Lang::t('profile.empty_value', $locale)) . '</b>';
        }

        $href = (string) preg_replace('/[^0-9+]/', '', $phone);
        $label = Text::phoneDisplay($phone);

        if ($href === '') {
            return '<b>' . Text::esc($label) . '</b>';
        }

        return '<a href="tel:' . Text::esc($href) . '"><b>' . Text::esc($label) . '</b></a>';
    }

    /**
     * A link to the applicant's Telegram profile plus their numeric id.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $user
     */
    private function userHtml(array $registration, array $user): string
    {
        $telegramId = (int) ($registration['telegram_id'] ?? ($user['telegram_id'] ?? 0));
        $username = $this->username($user['username'] ?? ($registration['username'] ?? null));

        $name = trim(
            trim((string) ($user['first_name'] ?? ($registration['user_first_name'] ?? '')))
            . ' '
            . trim((string) ($user['last_name'] ?? ($registration['user_last_name'] ?? '')))
        );

        if ($username !== null) {
            $label = '@' . $username;
            $href = 'https://t.me/' . $username;
        } else {
            $label = $name !== '' ? Text::truncate($name, 40) : (string) $telegramId;
            $href = $telegramId !== 0 ? 'tg://user?id=' . $telegramId : null;
        }

        $html = $href !== null
            ? '<a href="' . Text::esc($href) . '">' . Text::esc($label) . '</a>'
            : '<b>' . Text::esc($label) . '</b>';

        if ($telegramId !== 0) {
            $html .= ' · <code>' . $telegramId . '</code>';
        }

        return $html;
    }

    /**
     * Rewrite a card after a decision: the buttons go, the outcome stays.
     *
     * @param array<string,mixed> $registration
     * @param ?string             $reviewer Label of the deciding admin, null to skip the line.
     * @param ?bool               $notified Whether the applicant could be reached.
     */
    private function repaint(
        Update $u,
        array $registration,
        string $locale,
        ?string $reviewer,
        ?bool $notified
    ): void {
        $chatId = $u->chatId();
        $messageId = $u->messageId();

        if ($chatId === null || $messageId === null) {
            return;
        }

        $applicant = $this->applicantRow($registration);
        $text = $this->card($registration, $applicant, $locale);

        if ($reviewer !== null) {
            $text .= "\n" . Text::esc(Lang::t('admin.review_by', $locale, ['actor' => $reviewer]));
        }

        if ($notified !== null) {
            $text .= "\n<i>"
                . Text::esc(Lang::t($notified ? 'admin.user_notified' : 'admin.user_notify_failed', $locale))
                . '</i>';
        }

        try {
            // Passing no reply_markup drops the decision buttons for good.
            $this->app->api()->editMessageText($chatId, $messageId, $text);
        } catch (\Throwable $e) {
            $this->app->logger()->debug('Moderation card could not be updated', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tell the applicant about the decision.
     *
     * @param array<string,mixed> $registration
     *
     * @return bool True when the message was delivered.
     */
    private function notifyApplicant(array $registration, RegistrationStatus $status): bool
    {
        $chatId = (int) ($registration['telegram_id'] ?? 0);

        if ($chatId === 0) {
            return false;
        }

        $locale = $this->applicantLocale($registration);
        $key = $status === RegistrationStatus::Approved ? 'admin.approved_notice' : 'admin.rejected_notice';

        $text = Lang::t($key, $locale, ['id' => (int) ($registration['id'] ?? 0)])
            . "\n\n" . '<i>' . Text::esc(Lang::t($status->hintKey(), $locale)) . '</i>';

        try {
            $this->app->api()->sendMessage($chatId, $text);

            return true;
        } catch (ApiException $e) {
            if ($e->isBlockedByUser()) {
                try {
                    $this->app->users()->setBlocked($chatId, true);
                } catch (\Throwable $ignored) {
                    // The flag can wait; the decision itself is already stored.
                }
            }

            $this->app->logger()->warning('Applicant could not be notified', [
                'chat_id' => $chatId,
                'error'   => $e->description(),
            ]);

            return false;
        } catch (\Throwable $e) {
            $this->app->logger()->error('Unexpected failure while notifying an applicant', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /* --------------------------------------------------------------------
     | Small helpers
     */

    /**
     * Everybody who receives a moderation card: the bot administrators plus the
     * optional moderation group.
     *
     * @return int[]
     */
    private function recipients(): array
    {
        $ids = [];

        foreach ($this->app->adminIds() as $id) {
            $id = (int) $id;

            if ($id !== 0) {
                $ids[$id] = $id;
            }
        }

        $chatId = $this->app->config('telegram.admin_chat_id');

        if (is_numeric($chatId) && (int) $chatId !== 0) {
            $ids[(int) $chatId] = (int) $chatId;
        }

        return array_values($ids);
    }

    /**
     * The interface language of a card recipient (groups fall back to the
     * default locale, they have no user row).
     */
    private function recipientLocale(int $chatId): string
    {
        if ($chatId <= 0) {
            return $this->app->defaultLocale();
        }

        try {
            $row = $this->app->users()->findByTelegramId($chatId);
        } catch (\Throwable $e) {
            return $this->app->defaultLocale();
        }

        return $row === null ? $this->app->defaultLocale() : $this->locale($row);
    }

    /**
     * The locale of the applicant, taken from the joined user columns.
     *
     * @param array<string,mixed> $registration
     */
    private function applicantLocale(array $registration): string
    {
        $locale = $registration['user_locale'] ?? null;

        if (is_string($locale) && $locale !== '') {
            return Lang::normalize($locale);
        }

        $row = $this->applicantRow($registration);

        return $row === [] ? $this->app->defaultLocale() : $this->locale($row);
    }

    /**
     * The `users` row of an applicant, or an empty array when it is gone.
     *
     * @param array<string,mixed> $registration
     *
     * @return array<string,mixed>
     */
    private function applicantRow(array $registration): array
    {
        $telegramId = (int) ($registration['telegram_id'] ?? 0);

        if ($telegramId === 0) {
            return [];
        }

        try {
            return $this->app->users()->findByTelegramId($telegramId) ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * How the deciding administrator is named on the card.
     *
     * @param array<string,mixed> $user
     */
    private function actorLabel(array $user): string
    {
        $username = $this->username($user['username'] ?? null);

        if ($username !== null) {
            return '@' . $username;
        }

        $name = trim(
            trim((string) ($user['first_name'] ?? '')) . ' ' . trim((string) ($user['last_name'] ?? ''))
        );

        if ($name !== '') {
            return Text::truncate($name, 40);
        }

        return 'tg:' . (int) ($user['telegram_id'] ?? 0);
    }

    /**
     * The approve/reject keyboard of one application.
     */
    private function decisionKeyboard(int $registrationId, string $locale): string
    {
        return Keyboard::inline([[
            Keyboard::btn(Lang::t('btn.approve', $locale), self::CB_APPROVE . $registrationId),
            Keyboard::btn(Lang::t('btn.reject', $locale), self::CB_REJECT . $registrationId),
        ]]);
    }

    /** "👤 Ism-familiya: <html>" */
    private function line(string $field, string $labelKey, string $locale, string $html): string
    {
        return $this->emoji($field) . ' ' . Text::esc(Lang::t($labelKey, $locale)) . ': ' . $html;
    }

    private function emoji(string $field): string
    {
        return self::FIELD_EMOJI[$field] ?? '•';
    }

    /**
     * A displayable value, replaced by the "not provided" phrase when empty.
     */
    private function value(mixed $value, string $locale): string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return Lang::t('profile.empty_value', $locale);
        }

        $value = trim((string) $value);

        return $value === '' ? Lang::t('profile.empty_value', $locale) : $value;
    }

    /**
     * A usable Telegram username, or null.
     */
    private function username(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = ltrim(trim($value), '@');

        return preg_match('/^[A-Za-z0-9_]{4,32}$/', $value) === 1 ? $value : null;
    }

    /**
     * @param array<string,mixed> $registration
     */
    private function statusOf(array $registration): RegistrationStatus
    {
        $status = $registration['status'] ?? null;

        return RegistrationStatus::tryOrNull(is_scalar($status) ? (string) $status : null)
            ?? RegistrationStatus::default();
    }

    /**
     * @param array<string,mixed> $user
     */
    private function locale(array $user): string
    {
        $locale = $user['locale'] ?? null;

        return Lang::normalize(is_string($locale) && $locale !== '' ? $locale : $this->app->defaultLocale());
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

    /** Take the buttons off a card that must not be pressed again. */
    private function stripButtons(Update $u): void
    {
        $chatId = $u->chatId();
        $messageId = $u->messageId();

        if ($chatId === null || $messageId === null) {
            return;
        }

        try {
            $this->app->api()->editMessageReplyMarkup($chatId, $messageId, null);
        } catch (\Throwable $e) {
            $this->app->logger()->debug('Card buttons could not be removed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
