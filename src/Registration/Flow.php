<?php

declare(strict_types=1);

namespace AiTalents\Registration;

use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Enum\Step;
use AiTalents\Lang;
use AiTalents\Telegram\ApiException;
use AiTalents\Telegram\Keyboard;
use AiTalents\Telegram\Update;
use AiTalents\Text;
use AiTalents\Validator;

/**
 * The registration finite state machine.
 *
 * The current step lives in `users.state` ("reg:phone", see {@see Step::stateKey()})
 * and every answer collected so far lives in the `users.state_data` JSON column.
 * Nothing is written to `registrations` before the applicant confirms the summary,
 * so an abandoned registration leaves no trace beyond the state row.
 *
 * State data keys
 * ---------------
 *  full_name        string        answer of Step::FullName
 *  phone            string        answer of Step::Phone, always "+998XXXXXXXXX"
 *  birth_year       int           answer of Step::BirthYear
 *  district         string        catalogue key, answer of Step::District
 *  directions       string[]      catalogue keys, answer of Step::Direction
 *  direction_other  string        free text asked when "other" is selected
 *  portfolio        string        answer of Step::Portfolio
 *  portfolio_links  string[]      URLs extracted from the portfolio answer
 *  editing          bool          true while a single field is re-asked from Confirm
 *  awaiting_other   bool          true while the "other direction" free text is expected
 *  kb               bool          true while the phone reply keyboard is on screen
 *  dist_page        int           current page of the district keyboard
 *  dir_msg          int           message id of the direction keyboard (edited in place)
 *  mode             string        'new' | 'update' — how this run was started
 *  ref              string        deep link payload stored by the /start handler
 *
 * Callback data vocabulary (always far below Telegram's 64 byte limit)
 * -------------------------------------------------------------------
 *  r:<action>   registration control  — start, update, profile, back, cancel, skip,
 *                                       dok, dback, confirm, edit, restart, noop
 *  d:<key>      toggle a direction
 *  t:<key>      pick a district, "t:p:<n>" turns the district keyboard page
 *  e:<step>     re-ask one field from the confirmation screen, "e:back" returns to it
 *  l:<locale>   pick the interface language
 */
final class Flow
{
    /** Callback data prefix of the registration control buttons. */
    public const CB_REG = 'r:';

    /** Callback data prefix of a direction toggle. */
    public const CB_DIRECTION = 'd:';

    /** Callback data prefix of a district button. */
    public const CB_DISTRICT = 't:';

    /** Callback data prefix of the language buttons. */
    public const CB_LANGUAGE = 'l:';

    /** Callback data prefix of the "edit this field" buttons. */
    public const CB_EDIT = 'e:';

    /** Buttons per row in the direction and district keyboards. */
    private const PER_ROW = 2;

    /** District buttons shown on one page (18 districts => 2 pages). */
    private const DISTRICTS_PER_PAGE = 12;

    /** Length limits of the free-text "other direction" answer. */
    private const OTHER_MIN = 2;
    private const OTHER_MAX = 160;

    /** How much of the portfolio answer is repeated in the summary card. */
    private const PORTFOLIO_PREVIEW = 400;

    /** Decoration only — the wording itself always comes from the language files. */
    private const FIELD_EMOJI = [
        'full_name'  => '👤',
        'phone'      => '📱',
        'birth_year' => '🎂',
        'district'   => '🏙',
        'direction'  => '🎯',
        'portfolio'  => '🔗',
        'status'     => '📌',
        'created'    => '🗓',
    ];

    public function __construct(private App $app)
    {
    }

    /* =====================================================================
     | Entry points
     |=====================================================================*/

    /**
     * Begin (or resume) a registration.
     *
     * With `$restart = true` any half-finished answer is thrown away and the
     * questionnaire starts from its first step again.
     */
    public function start(array $user, bool $restart = false): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return;
        }

        $locale = $this->locale($user);

        if (!$this->isOpen()) {
            $this->send($telegramId, Lang::t('reg.closed', $locale));

            return;
        }

        $existing = $this->registration($telegramId);

        if ($existing !== null && !$restart) {
            $this->offerUpdate($user, $existing);

            return;
        }

        $data = $this->freshData($telegramId);

        if ($existing !== null) {
            // Restart over an existing application: the row is reused on save.
            $data['mode'] = 'update';
        }

        $sequence = $this->sequence();
        $first    = $sequence[0] ?? Step::Confirm;

        $this->app->users()->setState($telegramId, $first->stateKey(), $data);
        $this->send($telegramId, Lang::t('reg.intro', $locale));
        $this->render($user, $first);
    }

    /**
     * Handle a plain message while the user is inside the questionnaire.
     *
     * @return bool True when the message belonged to the flow and was consumed.
     */
    public function handleMessage(array $user, Update $u): bool
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return false;
        }

        $step = Step::fromState((string) ($user['state'] ?? ''));

        if ($step === null || $step === Step::Done) {
            return false;
        }

        // Slash commands are always the command handler's business.
        if ($u->command() !== null) {
            return false;
        }

        $locale = $this->locale($user);

        // The programme closed while this questionnaire was open.
        if (!$this->isOpen()) {
            $this->app->users()->clearState($telegramId);
            $this->send($telegramId, Lang::t('reg.closed', $locale), [
                'reply_markup' => Keyboard::remove(),
            ]);

            return true;
        }

        $text    = $u->text();
        $contact = $u->contact();

        // The phone step uses a reply keyboard, so "back" and "cancel" arrive as text.
        if (is_string($text) && $text !== '' && $contact === null) {
            if ($this->isButton($text, 'btn.cancel', $locale)) {
                $this->cancel($user);

                return true;
            }

            if ($this->isButton($text, 'btn.back', $locale)) {
                $this->goBack($user, $step);

                return true;
            }

            if ($step === Step::Portfolio && $this->isButton($text, 'btn.skip', $locale)) {
                $this->skipPortfolio($user);

                return true;
            }
        }

        // Anything that is neither text nor a shared contact cannot be an answer.
        if (($text === null || $text === '') && $contact === null) {
            $this->send($telegramId, Lang::t('error.not_text', $locale));

            return true;
        }

        $answer = is_string($text) ? $text : '';

        return match ($step) {
            Step::Language  => $this->onLanguageText($user),
            Step::FullName  => $this->onFullName($user, $answer),
            Step::Phone     => $this->onPhone($user, $u, $answer),
            Step::BirthYear => $this->onBirthYear($user, $answer),
            Step::District  => $this->onDistrictText($user, $answer),
            Step::Direction => $this->onDirectionText($user, $answer),
            Step::Portfolio => $this->onPortfolio($user, $answer),
            Step::Confirm   => $this->onConfirmText($user),
            default         => false,
        };
    }

    /**
     * Handle an inline button press that belongs to the registration flow.
     *
     * @return bool True when the callback was consumed.
     */
    public function handleCallback(array $user, Update $u): bool
    {
        $telegramId = $this->telegramId($user);
        $data       = $u->callbackData();

        if ($telegramId === 0 || $data === null) {
            return false;
        }

        $step = Step::fromState((string) ($user['state'] ?? ''));

        if (str_starts_with($data, self::CB_REG)) {
            return $this->onControl($user, $u, substr($data, strlen(self::CB_REG)), $step);
        }

        if (str_starts_with($data, self::CB_DIRECTION)) {
            return $this->onDirectionToggle($user, $u, substr($data, strlen(self::CB_DIRECTION)), $step);
        }

        if (str_starts_with($data, self::CB_DISTRICT)) {
            return $this->onDistrictCallback($user, $u, substr($data, strlen(self::CB_DISTRICT)), $step);
        }

        if (str_starts_with($data, self::CB_EDIT)) {
            return $this->onEditCallback($user, $u, substr($data, strlen(self::CB_EDIT)), $step);
        }

        // The language picker is shared with /start; only answer it while the
        // flow really is waiting for a language.
        if (str_starts_with($data, self::CB_LANGUAGE) && $step === Step::Language) {
            $code = Lang::normalize(substr($data, strlen(self::CB_LANGUAGE)));

            $this->app->users()->setLocale($telegramId, $code);
            $user['locale'] = $code;

            $this->answer($u, Lang::t('lang.changed', $code));
            $this->advance($user, Step::Language, $u->messageId());

            return true;
        }

        return false;
    }

    /* =====================================================================
     | Rendering
     |=====================================================================*/

    /**
     * Ask one step: store it as the current state and show its prompt.
     *
     * Passing `$editMessageId` rewrites that message instead of sending a new
     * one, which is what the inline keyboards (direction, district, confirm) do.
     */
    public function ask(array $user, Step $step, ?int $editMessageId = null): void
    {
        $this->render($user, $step, $editMessageId);
    }

    /**
     * Re-ask a single field and return to the confirmation screen afterwards.
     */
    public function editField(array $user, Step $step): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return;
        }

        if (!$this->isAskable($step)) {
            $this->send($telegramId, Lang::t('error.invalid_choice', $this->locale($user)));

            return;
        }

        $this->app->users()->mergeStateData($telegramId, ['editing' => true]);
        $this->render($user, $step);
    }

    /**
     * Abort the questionnaire and drop everything that was collected.
     */
    public function cancel(array $user): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return;
        }

        $locale       = $this->locale($user);
        $keyboardOpen = $this->truthy($this->data($telegramId)['kb'] ?? false);

        $this->app->users()->clearState($telegramId);

        $extra = $keyboardOpen
            ? ['reply_markup' => Keyboard::remove()]
            : ['reply_markup' => Keyboard::inline([[
                Keyboard::btn(Lang::t('btn.register', $locale), self::CB_REG . 'start'),
            ]])];

        $this->send($telegramId, Lang::t('reg.cancelled', $locale), $extra);
    }

    /**
     * The applicant's own card: everything that is stored, plus the review status.
     */
    public function showProfile(array $user): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return;
        }

        $locale       = $this->locale($user);
        $registration = $this->registration($telegramId);

        if ($registration === null) {
            $this->send($telegramId, $this->joinBlocks([
                Lang::t('profile.not_registered', $locale),
                Lang::t('profile.not_registered_hint', $locale),
            ]), [
                'reply_markup' => Keyboard::inline([[
                    Keyboard::btn(Lang::t('btn.register', $locale), self::CB_REG . 'start'),
                ]]),
            ]);

            return;
        }

        $rows = [];
        $rows[] = Lang::t('profile.title', $locale);
        $rows[] = Lang::t('profile.number', $locale, ['id' => (int) ($registration['id'] ?? 0)]);
        $rows[] = '';

        $rows[] = $this->line('full_name', 'profile.field_name', $locale, (string) ($registration['full_name'] ?? ''));
        $rows[] = $this->line(
            'phone',
            'profile.field_phone',
            $locale,
            Text::phoneDisplay((string) ($registration['phone'] ?? ''))
        );

        $birthYear = (int) ($registration['birth_year'] ?? 0);

        if ($birthYear > 0) {
            $rows[] = $this->line('birth_year', 'profile.field_birth_year', $locale, (string) $birthYear);
        }

        $district = (string) ($registration['district'] ?? '');

        if ($district !== '') {
            $rows[] = $this->line(
                'district',
                'profile.field_district',
                $locale,
                Catalog::districtLabel($district, $locale)
            );
        }

        $directions = Catalog::filterDirections($registration['directions'] ?? []);

        if ($directions !== []) {
            $rows[] = $this->emoji('direction') . ' ' . Text::esc(Lang::t('profile.field_direction', $locale)) . ':';

            foreach (Catalog::directionLabels($directions, $locale) as $label) {
                $rows[] = '   • ' . Text::esc($label);
            }

            $other = trim((string) ($registration['direction_other'] ?? ''));

            if ($other !== '') {
                $rows[] = '   • ' . Text::esc($other);
            }
        }

        $portfolio = trim((string) ($registration['portfolio'] ?? ''));

        if ($portfolio !== '') {
            $rows[] = $this->emoji('portfolio') . ' ' . Text::esc(Lang::t('profile.field_portfolio', $locale)) . ':';
            $rows[] = Text::multiline($portfolio, self::PORTFOLIO_PREVIEW);
        }

        $rows[] = '';
        $rows[] = $this->emoji('created') . ' ' . Text::esc(Lang::t('profile.field_created', $locale))
            . ': ' . Text::esc((string) ($registration['created_at'] ?? ''));

        $status = RegistrationStatus::tryOrNull($registration['status'] ?? null) ?? RegistrationStatus::default();
        $rows[] = $this->emoji('status') . ' '
            . Text::esc(Lang::t('reg.status_line', $locale, ['status' => $status->display($locale)]));
        $rows[] = '<i>' . Text::esc(Lang::t($status->hintKey(), $locale)) . '</i>';

        $buttons = [];

        if ($this->allowEdit() && $this->isOpen()) {
            $rows[]    = '';
            $rows[]    = '<i>' . Text::esc(Lang::t('profile.hint_edit', $locale)) . '</i>';
            $buttons[] = [Keyboard::btn(Lang::t('btn.edit_profile', $locale), self::CB_REG . 'update')];
        }

        $extra = [];

        if ($buttons !== []) {
            $extra['reply_markup'] = Keyboard::inline($buttons);
        }

        $this->send($telegramId, implode("\n", $rows), $extra);
    }

    /**
     * The HTML summary shown on the confirmation screen.
     *
     * @param array<string,mixed> $user The applicant's `users` row.
     * @param array<string,mixed> $data The collected answers (state data).
     */
    public function summaryText(array $user, array $data, string $locale): string
    {
        $rows   = [];
        $rows[] = Lang::t('reg.summary_title', $locale);
        $rows[] = '';

        $rows[] = $this->line(
            'full_name',
            'reg.field_full_name',
            $locale,
            (string) ($data['full_name'] ?? '')
        );

        $rows[] = $this->line(
            'phone',
            'reg.field_phone',
            $locale,
            Text::phoneDisplay((string) ($data['phone'] ?? ''))
        );

        if ($this->stepEnabled(Step::BirthYear)) {
            $year = (int) ($data['birth_year'] ?? 0);
            $rows[] = $this->line(
                'birth_year',
                'reg.field_birth_year',
                $locale,
                $year > 0 ? (string) $year : Lang::t('profile.empty_value', $locale)
            );
        }

        if ($this->stepEnabled(Step::District)) {
            $district = (string) ($data['district'] ?? '');
            $rows[] = $this->line(
                'district',
                'reg.field_district',
                $locale,
                $district !== ''
                    ? Catalog::districtLabel($district, $locale)
                    : Lang::t('profile.empty_value', $locale)
            );
        }

        $directions = $this->selectedDirections($data);
        $rows[] = $this->emoji('direction') . ' ' . Text::esc(Lang::t('reg.field_direction', $locale)) . ':';

        if ($directions === []) {
            $rows[] = '   • ' . Text::esc(Lang::t('profile.empty_value', $locale));
        } else {
            foreach (Catalog::directionLabels($directions, $locale) as $label) {
                $rows[] = '   • ' . Text::esc($label);
            }
        }

        $other = trim((string) ($data['direction_other'] ?? ''));

        if ($other !== '' && in_array(Catalog::OTHER, $directions, true)) {
            $rows[] = '   • ' . Text::esc($other);
        }

        if ($this->stepEnabled(Step::Portfolio)) {
            $portfolio = trim((string) ($data['portfolio'] ?? ''));
            $rows[] = $this->emoji('portfolio') . ' ' . Text::esc(Lang::t('reg.field_portfolio', $locale)) . ':';
            $rows[] = $portfolio !== ''
                ? Text::multiline($portfolio, self::PORTFOLIO_PREVIEW)
                : '   • ' . Text::esc(Lang::t('reg.portfolio_skipped', $locale));
        }

        $rows[] = '';
        $rows[] = Lang::t('reg.summary_hint', $locale);

        return implode("\n", $rows);
    }

    /* =====================================================================
     | Finishing
     |=====================================================================*/

    /**
     * Validate everything one last time, store the application and notify the admins.
     *
     * @param array<string,mixed> $data The collected answers (state data).
     */
    public function complete(array $user, array $data): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return;
        }

        $locale = $this->locale($user);

        if (!$this->isOpen()) {
            $this->app->users()->clearState($telegramId);
            $this->send($telegramId, Lang::t('reg.closed', $locale));

            return;
        }

        // Never trust state data: it may have been written by an older version
        // of the bot, or by a step that was disabled in the meantime.
        $checked = $this->sanitize($data);

        if ($checked['error'] !== null) {
            $errorKey = (string) $checked['error'];
            $this->send($telegramId, Lang::t($errorKey, $locale, $this->errorParams($errorKey)));

            $step = $checked['step'] instanceof Step ? $checked['step'] : ($this->sequence()[0] ?? Step::Confirm);

            if ($step === Step::Direction && $errorKey === 'reg.err_direction_other') {
                $this->askDirectionOther($user);

                return;
            }

            $this->render($user, $step);

            return;
        }

        /** @var array<string,mixed> $values */
        $values   = $checked['values'];
        $existing = $this->registration($telegramId);

        $values['source'] = 'bot';

        if ($existing === null) {
            $values['status'] = RegistrationStatus::default()->value;
        }

        try {
            $id = $this->app->registrations()->save(
                (int) ($user['id'] ?? 0),
                $telegramId,
                $values
            );
        } catch (\Throwable $e) {
            $this->app->logger()->error('Registration could not be saved', [
                'telegram_id' => $telegramId,
                'error'       => $e->getMessage(),
            ]);

            $this->send($telegramId, Lang::t('error.db', $locale));

            return;
        }

        $this->app->users()->clearState($telegramId);

        $updated = $existing !== null;

        $message = $updated
            ? Lang::t('reg.updated', $locale, ['id' => $id])
            : $this->joinBlocks([
                Lang::t('reg.saved', $locale, ['id' => $id]),
                Lang::t('reg.saved_hint', $locale),
            ]);

        $this->send($telegramId, $message, [
            'reply_markup' => Keyboard::inline([[
                Keyboard::btn(Lang::t('btn.my_profile', $locale), self::CB_REG . 'profile'),
            ]]),
        ]);

        $registration = $this->registration($telegramId) ?? array_merge($values, [
            'id'          => $id,
            'telegram_id' => $telegramId,
        ]);

        $this->app->audit()->log(
            'tg:' . $telegramId,
            $updated ? 'registration.updated' : 'registration.created',
            (string) $id,
            [
                'mode'       => (string) ($data['mode'] ?? 'new'),
                'directions' => $values['directions'] ?? [],
                'district'   => $values['district'] ?? null,
            ]
        );

        $this->notifyAdmins($registration, $user);
    }

    /* =====================================================================
     | The step sequence
     |=====================================================================*/

    /**
     * The data-collecting steps that are switched on in `app.steps`, in order.
     *
     * Confirm and Done are terminals of the machine rather than questions, so
     * they are not part of the sequence; {@see self::nextStep()} adds them.
     *
     * @return Step[]
     */
    public function sequence(): array
    {
        $steps = [];

        foreach (Step::questions() as $step) {
            if ($this->stepEnabled($step)) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * The step that follows `$current`, or null when the machine is finished.
     */
    public function nextStep(Step $current): ?Step
    {
        if ($current === Step::Done) {
            return null;
        }

        if ($current === Step::Confirm) {
            return Step::Done;
        }

        $sequence = $this->sequence();

        if ($current === Step::Language) {
            return $sequence[0] ?? Step::Confirm;
        }

        $index = array_search($current, $sequence, true);

        if ($index === false) {
            // A step that is switched off: continue with the next enabled one.
            return $this->neighbourOfDisabled($current, 1);
        }

        return $sequence[$index + 1] ?? Step::Confirm;
    }

    /**
     * The step before `$current`, or null when `$current` is the first one.
     */
    public function prevStep(Step $current): ?Step
    {
        if ($current === Step::Language) {
            return null;
        }

        $sequence = $this->sequence();

        if ($current === Step::Done || $current === Step::Confirm) {
            return $sequence === [] ? null : $sequence[count($sequence) - 1];
        }

        $index = array_search($current, $sequence, true);

        if ($index === false) {
            return $this->neighbourOfDisabled($current, -1);
        }

        return $index > 0 ? $sequence[$index - 1] : null;
    }

    /* =====================================================================
     | Message handlers, one per step
     |=====================================================================*/

    private function onLanguageText(array $user): bool
    {
        $this->send($this->telegramId($user), Lang::t('error.invalid_choice', $this->locale($user)));
        $this->render($user, Step::Language);

        return true;
    }

    private function onFullName(array $user, string $answer): bool
    {
        $result = Validator::fullName($answer);

        if ($result['ok'] !== true) {
            $this->rejectAnswer($user, Step::FullName, (string) $result['error']);

            return true;
        }

        $this->store($user, ['full_name' => (string) $result['value']]);
        $this->advance($user, Step::FullName);

        return true;
    }

    private function onPhone(array $user, Update $u, string $answer): bool
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);
        $contact    = $u->contact();

        if ($contact !== null) {
            $owner = $u->contactUserId();

            // A contact card of somebody else is not proof of this user's number.
            if ($owner !== null && $owner !== $telegramId) {
                $this->send($telegramId, Lang::t('reg.err_phone_foreign_contact', $locale));

                return true;
            }

            $answer = (string) ($u->contactPhone() ?? '');
        }

        $result = Validator::phone($answer);

        if ($result['ok'] !== true) {
            $this->rejectAnswer($user, Step::Phone, (string) $result['error']);

            return true;
        }

        $phone = (string) $result['value'];
        $this->store($user, ['phone' => $phone]);

        // Acknowledge the number and take the reply keyboard off the screen.
        $notice = '✅ ' . Text::esc(Lang::t('reg.field_phone', $locale))
            . ': <b>' . Text::esc(Text::phoneDisplay($phone)) . '</b>';

        $this->advance($user, Step::Phone, null, $notice);

        return true;
    }

    private function onBirthYear(array $user, string $answer): bool
    {
        $result = Validator::birthYear($answer);

        if ($result['ok'] !== true) {
            $this->rejectAnswer($user, Step::BirthYear, (string) $result['error']);

            return true;
        }

        $this->store($user, ['birth_year' => (int) $result['value']]);
        $this->advance($user, Step::BirthYear);

        return true;
    }

    /**
     * The district step is button driven, but a typed district name is accepted too.
     */
    private function onDistrictText(array $user, string $answer): bool
    {
        $key = $this->matchDistrict($answer, $this->locale($user));

        if ($key === null) {
            $this->rejectAnswer($user, Step::District, 'reg.err_district');

            return true;
        }

        $this->store($user, ['district' => $key]);
        $this->advance($user, Step::District);

        return true;
    }

    /**
     * Free text at the direction step is only expected for the "other" option.
     */
    private function onDirectionText(array $user, string $answer): bool
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);
        $data       = $this->data($telegramId);

        if (!$this->truthy($data['awaiting_other'] ?? false)) {
            $this->send($telegramId, Lang::t('reg.hint_direction', $locale));
            $this->render($user, Step::Direction);

            return true;
        }

        $value = Text::normalizeSpaces($answer);

        if (mb_strlen($value, 'UTF-8') < self::OTHER_MIN) {
            $this->send($telegramId, Lang::t('reg.err_direction_other', $locale));

            return true;
        }

        $value = Text::truncate($value, self::OTHER_MAX);

        $directions = $this->selectedDirections($data);

        if (!in_array(Catalog::OTHER, $directions, true)) {
            $directions[] = Catalog::OTHER;
            $directions   = Catalog::filterDirections($directions);
        }

        $this->store($user, [
            'direction_other' => $value,
            'directions'      => $directions,
            'awaiting_other'  => false,
        ]);

        $this->send($telegramId, Lang::t('reg.edit_saved', $locale));

        // Show the selection board again so more directions can be added.
        $this->render($user, Step::Direction);

        return true;
    }

    private function onPortfolio(array $user, string $answer): bool
    {
        $result = Validator::portfolio($answer);

        if ($result['ok'] !== true) {
            $this->rejectAnswer($user, Step::Portfolio, (string) $result['error']);

            return true;
        }

        $portfolio = (string) $result['value'];

        $this->store($user, [
            'portfolio'       => $portfolio,
            'portfolio_links' => Validator::extractUrls($portfolio),
        ]);

        $this->advance($user, Step::Portfolio);

        return true;
    }

    private function onConfirmText(array $user): bool
    {
        $this->send($this->telegramId($user), Lang::t('error.invalid_choice', $this->locale($user)));
        $this->render($user, Step::Confirm);

        return true;
    }

    /* =====================================================================
     | Callback handlers
     |=====================================================================*/

    /**
     * Buttons with the `r:` prefix — the navigation of the whole flow.
     */
    private function onControl(array $user, Update $u, string $action, ?Step $step): bool
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);

        switch ($action) {
            case 'start':
            case 'go':
            case 'reg':
            case 'new':
                $this->answer($u);
                $this->start($user);

                return true;

            case 'restart':
                $this->answer($u);
                $this->start($user, true);

                return true;

            case 'update':
            case 'again':
                $this->answer($u);
                $this->startUpdate($user);

                return true;

            case 'profile':
            case 'me':
                $this->answer($u);
                $this->showProfile($user);

                return true;

            case 'cancel':
                $this->answer($u, Lang::t('reg.cancelled', $locale));
                $this->stripButtons($u);
                $this->cancel($user);

                return true;

            case 'noop':
                $this->answer($u);

                return true;
        }

        // Everything below only makes sense inside a running questionnaire.
        if ($step === null || $step === Step::Done) {
            $this->answer($u, Lang::t('error.session_expired', $locale), true);

            return true;
        }

        switch ($action) {
            case 'back':
                $this->answer($u);
                $this->goBack($user, $step, $u->messageId());

                return true;

            case 'skip':
                if ($step !== Step::Portfolio) {
                    $this->answer($u, Lang::t('error.invalid_choice', $locale), true);

                    return true;
                }

                $this->answer($u, Lang::t('reg.portfolio_skipped', $locale));
                $this->stripButtons($u);
                $this->skipPortfolio($user);

                return true;

            case 'dok':
                $this->answer($u);
                $this->finishDirections($user, $u);

                return true;

            case 'dback':
                $this->answer($u);
                $this->app->users()->mergeStateData($telegramId, ['awaiting_other' => false]);
                $this->stripButtons($u);
                $this->render($user, Step::Direction);

                return true;

            case 'confirm':
                $this->answer($u, Lang::t('reg.saving', $locale));

                if ($step !== Step::Confirm) {
                    $this->render($user, $step);

                    return true;
                }

                $this->stripButtons($u);
                $this->complete($user, $this->data($telegramId));

                return true;

            case 'edit':
                $this->answer($u);
                $this->showEditPicker($user, $u->messageId());

                return true;
        }

        $this->answer($u, Lang::t('error.invalid_choice', $locale), true);

        return true;
    }

    /**
     * Buttons with the `d:` prefix — toggling one direction on or off.
     */
    private function onDirectionToggle(array $user, Update $u, string $key, ?Step $step): bool
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);

        if ($step !== Step::Direction) {
            $this->answer($u, Lang::t('error.session_expired', $locale), true);

            return true;
        }

        if (!Catalog::hasDirection($key)) {
            $this->answer($u, Lang::t('reg.err_direction', $locale), true);

            return true;
        }

        $data     = $this->data($telegramId);
        $selected = $this->selectedDirections($data);
        $isOn     = in_array($key, $selected, true);

        if ($isOn) {
            $selected = array_values(array_filter(
                $selected,
                static fn (string $item): bool => $item !== $key
            ));
        } else {
            $selected[] = $key;
        }

        $selected = Catalog::filterDirections($selected);

        $patch = ['directions' => $selected];

        // Switching "other" off also drops the free text that belonged to it.
        if ($key === Catalog::OTHER) {
            if ($isOn) {
                $patch['direction_other'] = '';
                $patch['awaiting_other']  = false;
            } else {
                $patch['awaiting_other'] = true;
            }
        }

        $data = $this->app->users()->mergeStateData($telegramId, $patch);

        $this->answer($u, Catalog::directionLabel($key, $locale, false));

        // Repaint the board in place so the ✅ marks follow the taps.
        $messageId = $u->messageId();

        if ($messageId !== null) {
            $this->edit(
                $telegramId,
                $messageId,
                $this->promptText($user, Step::Direction, $locale, $data),
                ['reply_markup' => $this->directionKeyboard($data, $locale)]
            );
        } else {
            $this->render($user, Step::Direction);
        }

        if ($key === Catalog::OTHER && !$isOn) {
            $this->askDirectionOther($user);
        }

        return true;
    }

    /**
     * Buttons with the `t:` prefix — a district or a page of the district board.
     */
    private function onDistrictCallback(array $user, Update $u, string $payload, ?Step $step): bool
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);

        if ($step !== Step::District) {
            $this->answer($u, Lang::t('error.session_expired', $locale), true);

            return true;
        }

        // Page turn: "p:<n>".
        if (str_starts_with($payload, 'p:')) {
            $page = (int) substr($payload, 2);
            $data = $this->app->users()->mergeStateData($telegramId, ['dist_page' => max(0, $page)]);

            $this->answer($u);

            $messageId = $u->messageId();

            if ($messageId !== null) {
                $this->edit(
                    $telegramId,
                    $messageId,
                    $this->promptText($user, Step::District, $locale, $data),
                    ['reply_markup' => $this->districtKeyboard($data, $locale)]
                );
            } else {
                $this->render($user, Step::District);
            }

            return true;
        }

        if (!Catalog::hasDistrict($payload)) {
            $this->answer($u, Lang::t('reg.err_district', $locale), true);

            return true;
        }

        $this->answer($u, Catalog::districtLabel($payload, $locale));
        $this->store($user, ['district' => $payload]);
        $this->stripButtons($u);
        $this->advance($user, Step::District);

        return true;
    }

    /**
     * Buttons with the `e:` prefix — the field picker of the confirmation screen.
     */
    private function onEditCallback(array $user, Update $u, string $payload, ?Step $step): bool
    {
        $locale = $this->locale($user);

        if ($step === null || $step === Step::Done) {
            $this->answer($u, Lang::t('error.session_expired', $locale), true);

            return true;
        }

        if ($payload === 'back') {
            $this->answer($u);
            $this->render($user, Step::Confirm, $u->messageId());

            return true;
        }

        $target = Step::tryFrom($payload);

        if ($target === null || !$this->isAskable($target)) {
            $this->answer($u, Lang::t('error.invalid_choice', $locale), true);

            return true;
        }

        $this->answer($u, Lang::t($target->labelKey(), $locale));
        $this->stripButtons($u);
        $this->editField($user, $target);

        return true;
    }

    /* =====================================================================
     | Flow mechanics
     |=====================================================================*/

    /**
     * Store the answer of a step and move on — or return to the confirmation
     * screen when a single field was being edited.
     *
     * `$keyboardNotice` is ready-made HTML shown while a reply keyboard is
     * removed (the phone step is the only one that shows one).
     */
    private function advance(array $user, Step $current, ?int $editMessageId = null, ?string $keyboardNotice = null): void
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);
        $data       = $this->data($telegramId);

        if ($this->truthy($data['editing'] ?? false)) {
            $this->app->users()->mergeStateData($telegramId, ['editing' => false]);

            // Acknowledge the change (and take down the phone keyboard) before
            // the summary card is drawn again.
            $this->dropReplyKeyboard(
                $telegramId,
                $keyboardNotice ?? Text::esc(Lang::t('common.done', $locale))
            );

            $this->send($telegramId, Lang::t('reg.edit_saved', $locale));
            $this->render($user, Step::Confirm);

            return;
        }

        $next = $this->nextStep($current);

        if ($next === null || $next === Step::Done) {
            $this->complete($user, $this->data($telegramId));

            return;
        }

        $this->render($user, $next, $editMessageId, $keyboardNotice);
    }

    /**
     * One step back; from the very first step this cancels the registration.
     */
    private function goBack(array $user, Step $current, ?int $editMessageId = null): void
    {
        $telegramId = $this->telegramId($user);
        $data       = $this->data($telegramId);

        // While the "other direction" text is expected, back returns to the board.
        if ($current === Step::Direction && $this->truthy($data['awaiting_other'] ?? false)) {
            $this->app->users()->mergeStateData($telegramId, ['awaiting_other' => false]);
            $this->render($user, Step::Direction);

            return;
        }

        // Editing a single field: back means "leave it as it was".
        if ($this->truthy($data['editing'] ?? false)) {
            $this->app->users()->mergeStateData($telegramId, ['editing' => false]);
            $this->render($user, Step::Confirm, $editMessageId);

            return;
        }

        $previous = $this->prevStep($current);

        if ($previous === null) {
            $this->cancel($user);

            return;
        }

        $notice = $current === Step::Phone
            ? Text::esc(Lang::t('btn.back', $this->locale($user)))
            : null;

        $this->render($user, $previous, $editMessageId, $notice);
    }

    /**
     * "Skip" on the portfolio step: clear the answer and continue.
     */
    private function skipPortfolio(array $user): void
    {
        $this->store($user, ['portfolio' => '', 'portfolio_links' => []]);
        $this->advance($user, Step::Portfolio);
    }

    /**
     * "Tayyor" on the direction board.
     */
    private function finishDirections(array $user, Update $u): void
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);
        $data       = $this->data($telegramId);
        $selected   = $this->selectedDirections($data);

        if ($selected === []) {
            $this->answer($u, Lang::t('reg.err_direction_none', $locale), true);

            return;
        }

        // "Other" without its free text would be stored as an empty wish.
        if (in_array(Catalog::OTHER, $selected, true) && trim((string) ($data['direction_other'] ?? '')) === '') {
            $this->answer($u, Lang::t('reg.err_direction_other', $locale), true);
            $this->askDirectionOther($user);

            return;
        }

        $this->stripButtons($u);
        $this->advance($user, Step::Direction);
    }

    /**
     * Ask for the free text that belongs to the "other" direction.
     */
    private function askDirectionOther(array $user): void
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);

        $this->app->users()->setState($telegramId, Step::Direction->stateKey());
        $this->app->users()->mergeStateData($telegramId, ['awaiting_other' => true]);

        $text = $this->joinBlocks([
            Lang::t('reg.ask_direction_other', $locale),
            $this->hintHtml(Lang::t('reg.hint_direction_other', $locale)),
        ]);

        $this->send($telegramId, $text, [
            'reply_markup' => Keyboard::inline([[
                Keyboard::btn(Lang::t('btn.back', $locale), self::CB_REG . 'dback'),
            ]]),
        ]);
    }

    /**
     * The field picker behind "✏️ Tahrirlash" on the confirmation screen.
     */
    private function showEditPicker(array $user, ?int $messageId): void
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);

        $buttons = [];

        foreach ($this->sequence() as $step) {
            $buttons[] = Keyboard::btn(
                Lang::t('btn.edit_' . $step->value, $locale),
                self::CB_EDIT . $step->value
            );
        }

        $rows   = Keyboard::rows($buttons, self::PER_ROW);
        $rows[] = [Keyboard::btn(Lang::t('btn.back', $locale), self::CB_EDIT . 'back')];

        $text = Lang::t('reg.edit_choose', $locale);

        if ($messageId !== null) {
            $this->edit($telegramId, $messageId, $text, ['reply_markup' => Keyboard::inline($rows)]);

            return;
        }

        $this->send($telegramId, $text, ['reply_markup' => Keyboard::inline($rows)]);
    }

    /**
     * An application already exists: offer to update it, or just show it.
     *
     * @param array<string,mixed> $registration
     */
    private function offerUpdate(array $user, array $registration): void
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);
        $id         = (int) ($registration['id'] ?? 0);

        if (!$this->allowEdit()) {
            $this->send($telegramId, Lang::t('reg.already_registered', $locale, ['id' => $id]));
            $this->showProfile($user);

            return;
        }

        $text = $this->joinBlocks([
            Lang::t('reg.already_registered', $locale, ['id' => $id]),
            Lang::t('reg.already_registered_hint', $locale),
        ]);

        $this->send($telegramId, $text, [
            'reply_markup' => Keyboard::inline([
                [Keyboard::btn(Lang::t('btn.register_again', $locale), self::CB_REG . 'update')],
                [Keyboard::btn(Lang::t('btn.my_profile', $locale), self::CB_REG . 'profile')],
            ]),
        ]);
    }

    /**
     * Start the questionnaire with the stored application pre-filled.
     */
    private function startUpdate(array $user): void
    {
        $telegramId = $this->telegramId($user);
        $locale     = $this->locale($user);

        if (!$this->isOpen()) {
            $this->send($telegramId, Lang::t('reg.closed', $locale));

            return;
        }

        $registration = $this->registration($telegramId);

        if ($registration === null) {
            $this->start($user);

            return;
        }

        if (!$this->allowEdit()) {
            $this->showProfile($user);

            return;
        }

        $data = $this->freshData($telegramId);

        $data['mode']            = 'update';
        $data['full_name']       = (string) ($registration['full_name'] ?? '');
        $data['phone']           = (string) ($registration['phone'] ?? '');
        $data['birth_year']      = (int) ($registration['birth_year'] ?? 0);
        $data['district']        = (string) ($registration['district'] ?? '');
        $data['directions']      = Catalog::filterDirections($registration['directions'] ?? []);
        $data['direction_other'] = (string) ($registration['direction_other'] ?? '');
        $data['portfolio']       = (string) ($registration['portfolio'] ?? '');
        $data['portfolio_links'] = $this->stringList($registration['portfolio_links'] ?? []);

        $first = $this->sequence()[0] ?? Step::Confirm;

        $this->app->users()->setState($telegramId, $first->stateKey(), $data);
        $this->send($telegramId, Lang::t('reg.intro', $locale));
        $this->render($user, $first);
    }

    /* =====================================================================
     | Prompt rendering
     |=====================================================================*/

    /**
     * Show one step: switch the state, print the question and its keyboard.
     */
    private function render(array $user, Step $step, ?int $editMessageId = null, ?string $keyboardNotice = null): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId === 0) {
            return;
        }

        $locale = $this->locale($user);

        // Leaving the phone step: the reply keyboard has to be taken down first,
        // and Telegram only accepts one reply markup per message.
        if ($step !== Step::Phone) {
            $dropped = $this->dropReplyKeyboard(
                $telegramId,
                $keyboardNotice ?? Text::esc(Lang::t($step->labelKey(), $locale))
            );

            if ($dropped) {
                // The inline message we were asked to rewrite is now out of context.
                $editMessageId = null;
            }
        }

        // A rendered prompt always replaces any sub-question of the step.
        $patch = ['awaiting_other' => false];

        if ($step === Step::Phone) {
            $patch['kb'] = true;
        }

        $this->app->users()->setState($telegramId, $step->stateKey());
        $data = $this->app->users()->mergeStateData($telegramId, $patch);

        $text  = $this->promptText($user, $step, $locale, $data);
        $extra = [];

        $markup = $this->markupFor($step, $locale, $data);

        if ($markup !== null) {
            $extra['reply_markup'] = $markup;
        }

        // The phone keyboard is a reply keyboard: it can never replace an inline one.
        if ($editMessageId !== null && $step !== Step::Phone) {
            $this->edit($telegramId, $editMessageId, $text, $extra);

            if ($step === Step::Direction) {
                $this->app->users()->mergeStateData($telegramId, ['dir_msg' => $editMessageId]);
            }

            return;
        }

        $sent = $this->send($telegramId, $text, $extra);

        if ($step !== Step::Direction) {
            return;
        }

        $newId      = is_array($sent) && isset($sent['message_id']) ? (int) $sent['message_id'] : 0;
        $previousId = (int) ($data['dir_msg'] ?? 0);

        // Two live selection boards would disagree with each other: retire the old one.
        if ($previousId > 0 && $previousId !== $newId) {
            $this->clearMarkup($telegramId, $previousId);
        }

        if ($newId > 0) {
            $this->app->users()->mergeStateData($telegramId, ['dir_msg' => $newId]);
        }
    }

    /**
     * The body of a prompt: progress line, question, hint (and the live
     * selection counter of the direction board).
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $data
     */
    private function promptText(array $user, Step $step, string $locale, array $data): string
    {
        if ($step === Step::Confirm) {
            return $this->joinBlocks([
                $this->summaryText($user, $data, $locale),
                $this->hintHtml(Lang::t('reg.hint_confirm', $locale)),
            ]);
        }

        $rows     = [];
        $progress = $this->progress($step, $locale);
        $label    = Lang::t($step->labelKey(), $locale);

        $rows[] = $progress === ''
            ? '<b>' . Text::esc($label) . '</b>'
            : '<b>' . Text::esc($progress) . '</b> · ' . Text::esc($label);

        $rows[] = '';
        $rows[] = Lang::t($step->promptKey(), $locale);

        $hint = Lang::t($step->hintKey(), $locale);

        if ($hint !== '' && $hint !== $step->hintKey()) {
            $rows[] = '';
            $rows[] = $this->hintHtml($hint);
        }

        if ($step === Step::Direction) {
            $rows[] = '';
            $rows[] = '<b>' . Text::esc(Lang::t('reg.direction_selected', $locale, [
                'count' => count($this->selectedDirections($data)),
            ])) . '</b>';
        }

        return implode("\n", $rows);
    }

    /**
     * The reply markup of a step, or null when the step needs none.
     *
     * @param array<string,mixed> $data
     */
    private function markupFor(Step $step, string $locale, array $data): ?string
    {
        return match ($step) {
            Step::Language  => $this->languageKeyboard($locale),
            Step::Phone     => $this->phoneKeyboard($locale),
            Step::District  => $this->districtKeyboard($data, $locale),
            Step::Direction => $this->directionKeyboard($data, $locale),
            Step::Portfolio => Keyboard::inline(array_values(array_filter([
                [Keyboard::btn(Lang::t('btn.skip', $locale), self::CB_REG . 'skip')],
                $this->navRow(Step::Portfolio, $locale),
            ], static fn (array $row): bool => $row !== []))),
            Step::Confirm   => $this->confirmKeyboard($locale),
            default         => $this->simpleKeyboard($step, $locale),
        };
    }

    /** Inline keyboard with nothing but the navigation row. */
    private function simpleKeyboard(Step $step, string $locale): ?string
    {
        $row = $this->navRow($step, $locale);

        return $row === [] ? null : Keyboard::inline([$row]);
    }

    /**
     * "Back" (hidden on the first step) plus "Cancel".
     *
     * @return array<int,array<string,mixed>>
     */
    private function navRow(Step $step, string $locale): array
    {
        $backText = $this->prevStep($step) === null ? null : Lang::t('btn.back', $locale);
        $backData = $backText === null ? '' : self::CB_REG . 'back';

        return Keyboard::backRow(
            $backText ?? '',
            $backData,
            Lang::t('btn.cancel', $locale),
            self::CB_REG . 'cancel'
        );
    }

    private function languageKeyboard(string $locale): string
    {
        $buttons = [];

        foreach ($this->app->locales() as $code) {
            $code = Lang::normalize($code);
            $buttons[] = Keyboard::btn(
                Lang::t('btn.lang_' . $code, $locale),
                self::CB_LANGUAGE . $code
            );
        }

        $rows = Keyboard::rows($buttons, self::PER_ROW);
        $nav  = $this->navRow(Step::Language, $locale);

        if ($nav !== []) {
            $rows[] = $nav;
        }

        return Keyboard::inline($rows);
    }

    /**
     * Reply keyboard of the phone step: share the contact, or navigate.
     */
    private function phoneKeyboard(string $locale): string
    {
        $rows = [[Keyboard::contact(Lang::t('btn.share_phone', $locale))]];

        $navigation = [];

        if ($this->prevStep(Step::Phone) !== null) {
            $navigation[] = Lang::t('btn.back', $locale);
        }

        $navigation[] = Lang::t('btn.cancel', $locale);
        $rows[]       = $navigation;

        return Keyboard::reply($rows, true, false, Lang::t('btn.type_phone', $locale));
    }

    /**
     * The multi-select direction board.
     *
     * @param array<string,mixed> $data
     */
    private function directionKeyboard(array $data, string $locale): string
    {
        $selected = $this->selectedDirections($data);
        $buttons  = [];

        foreach (Catalog::directionKeys() as $key) {
            $label = Catalog::directionLabel($key, $locale);

            if (in_array($key, $selected, true)) {
                $label = '✅ ' . $label;
            }

            $buttons[] = Keyboard::btn(Text::truncate($label, 40), self::CB_DIRECTION . $key);
        }

        $rows   = Keyboard::rows($buttons, self::PER_ROW);
        $rows[] = [Keyboard::btn(Lang::t('btn.done', $locale), self::CB_REG . 'dok')];

        $nav = $this->navRow(Step::Direction, $locale);

        if ($nav !== []) {
            $rows[] = $nav;
        }

        return Keyboard::inline($rows);
    }

    /**
     * The district board: cities first, then districts, paginated so the
     * keyboard always stays comfortably inside Telegram's limits.
     *
     * @param array<string,mixed> $data
     */
    private function districtKeyboard(array $data, string $locale): string
    {
        $keys  = Catalog::districtKeys();
        $pages = max(1, (int) ceil(count($keys) / self::DISTRICTS_PER_PAGE));
        $page  = (int) ($data['dist_page'] ?? 0);
        $page  = max(0, min($page, $pages - 1));

        $slice   = array_slice($keys, $page * self::DISTRICTS_PER_PAGE, self::DISTRICTS_PER_PAGE);
        $buttons = [];

        foreach ($slice as $key) {
            $selected = ((string) ($data['district'] ?? '')) === $key;
            $label    = Catalog::districtLabel($key, $locale);

            $buttons[] = Keyboard::btn(
                Text::truncate(($selected ? '✅ ' : '') . $label, 40),
                self::CB_DISTRICT . $key
            );
        }

        $rows = Keyboard::rows($buttons, self::PER_ROW);

        if ($pages > 1) {
            $pager = [];

            if ($page > 0) {
                $pager[] = Keyboard::btn(Lang::t('btn.prev', $locale), self::CB_DISTRICT . 'p:' . ($page - 1));
            }

            $pager[] = Keyboard::btn(
                Lang::t('common.page', $locale) . ' ' . ($page + 1) . '/' . $pages,
                self::CB_REG . 'noop'
            );

            if ($page < $pages - 1) {
                $pager[] = Keyboard::btn(Lang::t('btn.next', $locale), self::CB_DISTRICT . 'p:' . ($page + 1));
            }

            $rows[] = $pager;
        }

        $nav = $this->navRow(Step::District, $locale);

        if ($nav !== []) {
            $rows[] = $nav;
        }

        return Keyboard::inline($rows);
    }

    private function confirmKeyboard(string $locale): string
    {
        return Keyboard::inline([
            [Keyboard::btn(Lang::t('btn.confirm', $locale), self::CB_REG . 'confirm')],
            [
                Keyboard::btn(Lang::t('btn.edit', $locale), self::CB_REG . 'edit'),
                Keyboard::btn(Lang::t('btn.cancel', $locale), self::CB_REG . 'cancel'),
            ],
        ]);
    }

    /* =====================================================================
     | Validation helpers
     |=====================================================================*/

    /**
     * Re-validate the whole state data before it is written to the database.
     *
     * @param array<string,mixed> $data
     *
     * @return array{values:array<string,mixed>,step:?Step,error:?string}
     */
    private function sanitize(array $data): array
    {
        $values = [];

        $name = Validator::fullName((string) ($data['full_name'] ?? ''));

        if ($name['ok'] !== true) {
            return $this->invalid(Step::FullName, (string) $name['error']);
        }

        $values['full_name'] = (string) $name['value'];

        $phone = Validator::phone((string) ($data['phone'] ?? ''));

        if ($phone['ok'] !== true) {
            return $this->invalid(Step::Phone, (string) $phone['error']);
        }

        $values['phone'] = (string) $phone['value'];

        if ($this->stepEnabled(Step::BirthYear)) {
            $year = Validator::birthYear((string) ($data['birth_year'] ?? ''));

            if ($year['ok'] !== true) {
                return $this->invalid(Step::BirthYear, (string) $year['error']);
            }

            $values['birth_year'] = (int) $year['value'];
        } else {
            $values['birth_year'] = null;
        }

        if ($this->stepEnabled(Step::District)) {
            $district = (string) ($data['district'] ?? '');

            if (!Catalog::hasDistrict($district)) {
                return $this->invalid(Step::District, 'reg.err_district');
            }

            $values['district'] = $district;
        } else {
            $values['district'] = null;
        }

        $directions = Catalog::filterDirections($data['directions'] ?? []);

        if ($directions === []) {
            return $this->invalid(Step::Direction, 'reg.err_direction_none');
        }

        $values['directions'] = $directions;

        $other = Text::normalizeSpaces((string) ($data['direction_other'] ?? ''));

        if (in_array(Catalog::OTHER, $directions, true)) {
            if (mb_strlen($other, 'UTF-8') < self::OTHER_MIN) {
                return $this->invalid(Step::Direction, 'reg.err_direction_other');
            }

            $values['direction_other'] = Text::truncate($other, self::OTHER_MAX);
        } else {
            $values['direction_other'] = null;
        }

        if ($this->stepEnabled(Step::Portfolio)) {
            $portfolio = Text::clean((string) ($data['portfolio'] ?? ''), Validator::PORTFOLIO_MAX);

            if ($portfolio === '') {
                $values['portfolio']       = null;
                $values['portfolio_links'] = [];
            } else {
                $values['portfolio']       = $portfolio;
                $values['portfolio_links'] = Validator::extractUrls($portfolio);
            }
        } else {
            $values['portfolio']       = null;
            $values['portfolio_links'] = [];
        }

        return ['values' => $values, 'step' => null, 'error' => null];
    }

    /**
     * @return array{values:array<string,mixed>,step:Step,error:string}
     */
    private function invalid(Step $step, string $error): array
    {
        return ['values' => [], 'step' => $step, 'error' => $error === '' ? 'error.generic' : $error];
    }

    /**
     * Placeholders of the validation error messages that carry limits.
     *
     * @return array<string,int|string>
     */
    private function errorParams(string $key): array
    {
        return match ($key) {
            'reg.err_full_name_long'   => ['max' => Validator::NAME_MAX],
            'reg.err_portfolio_long'   => ['max' => Validator::PORTFOLIO_MAX],
            'reg.err_birth_year_range' => ['min' => Validator::AGE_MIN, 'max' => Validator::AGE_MAX],
            default                    => [],
        };
    }

    /**
     * Tell the user what was wrong and ask the very same question again.
     */
    private function rejectAnswer(array $user, Step $step, string $errorKey): void
    {
        $locale = $this->locale($user);
        $key    = $errorKey === '' ? 'error.generic' : $errorKey;

        $this->send($this->telegramId($user), Lang::t($key, $locale, $this->errorParams($key)));
        $this->render($user, $step);
    }

    /* =====================================================================
     | Small helpers
     |=====================================================================*/

    /**
     * Take the phone reply keyboard off the screen.
     *
     * Telegram only removes a reply keyboard together with a message, so a short
     * acknowledgement (`$html`, already escaped) carries the removal.
     *
     * @return bool True when a keyboard was actually showing and got removed.
     */
    private function dropReplyKeyboard(int $telegramId, string $html): bool
    {
        if (!$this->truthy($this->data($telegramId)['kb'] ?? false)) {
            return false;
        }

        $this->send($telegramId, $html, ['reply_markup' => Keyboard::remove()]);
        $this->app->users()->mergeStateData($telegramId, ['kb' => false]);

        return true;
    }

    /**
     * Merge answers into the state data.
     *
     * @param array<string,mixed> $patch
     */
    private function store(array $user, array $patch): void
    {
        $telegramId = $this->telegramId($user);

        if ($telegramId !== 0) {
            $this->app->users()->mergeStateData($telegramId, $patch);
        }
    }

    /**
     * The state data of a user.
     *
     * @return array<string,mixed>
     */
    private function data(int $telegramId): array
    {
        return $this->app->users()->stateData($telegramId);
    }

    /**
     * A clean state data array that keeps the values which survive a restart.
     *
     * @return array<string,mixed>
     */
    private function freshData(int $telegramId): array
    {
        $old  = $this->data($telegramId);
        $data = [
            'mode'            => 'new',
            'directions'      => [],
            'portfolio_links' => [],
            'editing'         => false,
            'awaiting_other'  => false,
            'kb'              => $this->truthy($old['kb'] ?? false),
            'dist_page'       => 0,
        ];

        // The deep link payload belongs to the visit, not to the questionnaire.
        $ref = (string) ($old['ref'] ?? '');

        if ($ref !== '') {
            $data['ref'] = $ref;
        }

        return $data;
    }

    /**
     * The direction keys that are currently selected.
     *
     * @param array<string,mixed> $data
     *
     * @return string[]
     */
    private function selectedDirections(array $data): array
    {
        return Catalog::filterDirections($data['directions'] ?? []);
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $list = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /** "2/6" for the steps that are part of the questionnaire, "" for the rest. */
    private function progress(Step $step, string $locale): string
    {
        $sequence = $this->sequence();
        $index    = array_search($step, $sequence, true);

        if ($index === false) {
            return '';
        }

        return Lang::t('reg.progress', $locale, [
            'current' => $index + 1,
            'total'   => count($sequence),
        ]);
    }

    /** True when a step can be asked at all (it is enabled and collects data). */
    private function isAskable(Step $step): bool
    {
        return $step->isQuestion() && $this->stepEnabled($step);
    }

    /** Optional steps follow `app.steps`; the rest are always part of the flow. */
    private function stepEnabled(Step $step): bool
    {
        if (!$step->isOptional()) {
            return true;
        }

        return $this->truthy($this->app->config('app.steps.' . $step->value, true));
    }

    /**
     * Walk the canonical question order to find the enabled neighbour of a step
     * that is switched off.
     *
     * @param int $direction 1 = forwards, -1 = backwards
     */
    private function neighbourOfDisabled(Step $step, int $direction): ?Step
    {
        $all   = Step::questions();
        $index = array_search($step, $all, true);

        if ($index === false) {
            return $direction > 0 ? Step::Confirm : null;
        }

        for ($i = $index + $direction; $i >= 0 && $i < count($all); $i += $direction) {
            if ($this->stepEnabled($all[$i])) {
                return $all[$i];
            }
        }

        return $direction > 0 ? Step::Confirm : null;
    }

    /** The stored application of a user, or null. */
    private function registration(int $telegramId): ?array
    {
        try {
            return $this->app->registrations()->findByTelegramId($telegramId);
        } catch (\Throwable $e) {
            $this->app->logger()->error('Registration lookup failed', [
                'telegram_id' => $telegramId,
                'error'       => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Hand the finished application to the admin notifier.
     *
     * The notifier is owned by the handler layer; a problem there must never
     * make the applicant think their registration failed.
     *
     * @param array<string,mixed> $registration
     * @param array<string,mixed> $user
     */
    private function notifyAdmins(array $registration, array $user): void
    {
        $class = '\\AiTalents\\Handler\\AdminNotifier';

        if (!class_exists($class)) {
            $this->app->logger()->warning('AdminNotifier is not available, skipping the admin card.');

            return;
        }

        try {
            /** @var object $notifier */
            $notifier = new $class($this->app);

            if (method_exists($notifier, 'newRegistration')) {
                $notifier->newRegistration($registration, $user);
            }
        } catch (\Throwable $e) {
            $this->app->logger()->error('Admins could not be notified about a registration', [
                'registration' => $registration['id'] ?? null,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /** "👤 Ism-familiya: <b>Ali Valiyev</b>" */
    private function line(string $field, string $labelKey, string $locale, string $value): string
    {
        $value = $value === '' ? Lang::t('profile.empty_value', $locale) : $value;

        return $this->emoji($field) . ' ' . Text::esc(Lang::t($labelKey, $locale))
            . ': <b>' . Text::esc($value) . '</b>';
    }

    private function emoji(string $field): string
    {
        return self::FIELD_EMOJI[$field] ?? '•';
    }

    /**
     * Render a hint in italics — unless the translation already carries markup,
     * which must not be nested blindly.
     */
    private function hintHtml(string $hint): string
    {
        if ($hint === '' || str_contains($hint, '<')) {
            return $hint;
        }

        return '<i>' . $hint . '</i>';
    }

    /**
     * Glue non-empty blocks with a blank line between them.
     *
     * @param string[] $blocks
     */
    private function joinBlocks(array $blocks): string
    {
        $clean = [];

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block !== '') {
                $clean[] = $block;
            }
        }

        return implode("\n\n", $clean);
    }

    /**
     * Compare a typed message with a translated button caption, ignoring emoji,
     * punctuation, case and the apostrophe zoo of Uzbek Latin.
     */
    private function isButton(string $text, string $key, string $locale): bool
    {
        $needle = $this->normalizeLabel($text);

        if ($needle === '') {
            return false;
        }

        foreach (array_unique([$locale, Lang::FALLBACK, 'ru']) as $code) {
            if ($this->normalizeLabel(Lang::t($key, $code)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** Letters and digits only, lower case — a robust key for label comparison. */
    private function normalizeLabel(string $s): string
    {
        $s = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', Text::normalizeSpaces($s));

        return mb_strtolower($s, 'UTF-8');
    }

    /**
     * Resolve a typed district name (or key) into a catalogue key.
     */
    private function matchDistrict(string $input, string $locale): ?string
    {
        $needle = $this->normalizeLabel($input);

        if ($needle === '') {
            return null;
        }

        foreach (Catalog::districtKeys() as $key) {
            if ($this->normalizeLabel($key) === $needle) {
                return $key;
            }

            foreach (array_unique([$locale, 'uz', 'ru']) as $code) {
                if ($this->normalizeLabel(Catalog::districtLabel($key, $code)) === $needle) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** Registration open? The DB setting wins over config.php. */
    private function isOpen(): bool
    {
        return $this->truthy($this->app->setting('registration_open', true));
    }

    /** May an applicant change an application that was already submitted? */
    private function allowEdit(): bool
    {
        return $this->truthy($this->app->config('app.allow_edit', true));
    }

    /**
     * Tolerant boolean reading of a config/setting value ("0", "off", "false").
     */
    private function truthy(mixed $value): bool
    {
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

    private function telegramId(array $user): int
    {
        $id = $user['telegram_id'] ?? 0;

        return is_numeric($id) ? (int) $id : 0;
    }

    private function locale(array $user): string
    {
        $locale = $user['locale'] ?? null;

        return Lang::normalize(is_string($locale) && $locale !== '' ? $locale : $this->app->defaultLocale());
    }

    /* =====================================================================
     | Telegram plumbing — a failing API call must never break the FSM
     |=====================================================================*/

    /**
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>|null
     */
    private function send(int $chatId, string $text, array $extra = []): ?array
    {
        if ($chatId === 0 || $text === '') {
            return null;
        }

        try {
            return $this->app->api()->sendMessage($chatId, $text, $extra);
        } catch (ApiException $e) {
            $this->app->logger()->warning('Registration message could not be delivered', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            $this->app->logger()->error('Unexpected Telegram failure', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function edit(int $chatId, int $messageId, string $text, array $extra = []): void
    {
        try {
            $this->app->api()->editMessageText($chatId, $messageId, $text, $extra);
        } catch (\Throwable $e) {
            // The message may be too old to edit — fall back to a new one.
            $this->app->logger()->debug('Prompt could not be edited in place', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);

            $this->send($chatId, $text, $extra);
        }
    }

    /** Answer a callback query so the client stops showing its spinner. */
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

    /**
     * Remove the buttons of the message a callback came from, so a card cannot
     * be submitted twice.
     */
    private function stripButtons(Update $u): void
    {
        $chatId    = $u->chatId();
        $messageId = $u->messageId();

        if ($chatId === null || $messageId === null) {
            return;
        }

        $this->clearMarkup($chatId, $messageId);
    }

    /** Take the inline keyboard off a message that is no longer current. */
    private function clearMarkup(int $chatId, int $messageId): void
    {
        if ($chatId === 0 || $messageId <= 0) {
            return;
        }

        try {
            $this->app->api()->editMessageReplyMarkup($chatId, $messageId, null);
        } catch (\Throwable $e) {
            $this->app->logger()->debug('Buttons could not be removed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
