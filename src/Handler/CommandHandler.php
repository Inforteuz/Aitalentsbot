<?php

declare(strict_types=1);

namespace AiTalents\Handler;

use AiTalents\App;
use AiTalents\Enum\Step;
use AiTalents\Export\XlsxExporter;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Registration\Flow;
use AiTalents\Telegram\Keyboard;
use AiTalents\Telegram\Update;
use AiTalents\Text;

/**
 * Slash commands and the buttons of the main (reply) keyboard.
 *
 * The main menu is deliberately a *reply* keyboard: its buttons come back as
 * ordinary text messages, so they are matched here against the `btn.*` labels
 * of every enabled locale. Inline keyboards stay reserved for the registration
 * flow and the admin screens, which own their own callback prefixes.
 *
 * Every reply is rendered in the locale stored on the user row.
 */
final class CommandHandler
{
    /**
     * Command (without the slash) => internal action.
     *
     * Uzbek and English spellings are equivalent; the Uzbek ones are what the
     * BotFather command list advertises.
     */
    private const COMMANDS = [
        'start'     => 'start',
        'help'      => 'help',
        'yordam'    => 'help',
        'register'  => 'register',
        'royxat'    => 'register',
        'profile'   => 'profile',
        'profil'    => 'profile',
        'language'  => 'language',
        'til'       => 'language',
        'about'     => 'about',
        'loyiha'    => 'about',
        'cancel'    => 'cancel',
        'bekor'     => 'cancel',
        'admin'     => 'admin',
        'stats'     => 'stats',
        'export'    => 'export',
        'broadcast' => 'broadcast',
        'panel'     => 'panel',
    ];

    /** Main menu button (language key) => internal action. */
    private const MENU_BUTTONS = [
        'btn.register'       => 'register',
        'btn.register_again' => 'register',
        'btn.edit_profile'   => 'register',
        'btn.my_profile'     => 'profile',
        'btn.language'       => 'language',
        'btn.about'          => 'about',
        'btn.help'           => 'help',
        'btn.main_menu'      => 'start',
        'btn.admin_menu'     => 'admin',
    ];

    /** How many rows the /stats top lists show. */
    private const TOP_LIMIT = 5;

    /** Prefix of the input states owned by AdminBotHandler. */
    private const STATE_ADMIN_PREFIX = 'admin:';

    /** State the bot waits in for the text of a broadcast (owned by AdminBotHandler). */
    private const STATE_BROADCAST = self::STATE_ADMIN_PREFIX . 'broadcast';

    private ?Flow $flow = null;
    private ?AdminBotHandler $adminBot = null;

    public function __construct(private App $app)
    {
    }

    /**
     * Dispatch a command or a main-menu button.
     *
     * @param array<string,mixed> $user
     *
     * @return bool True when the update was consumed.
     */
    public function handle(array $user, Update $u): bool
    {
        // The only button press that belongs here is the language picker of
        // /start and /til; inside the wizard the flow answers it itself.
        if ($u->type() === 'callback_query') {
            $data = (string) $u->callbackData();

            if (str_starts_with($data, Flow::CB_LANGUAGE) && !$this->inRegistration($user)) {
                $this->switchLocale($user, $u, substr($data, strlen(Flow::CB_LANGUAGE)));

                return true;
            }

            return false;
        }

        $command = $u->command();

        if ($command !== null) {
            $action = self::COMMANDS[$command] ?? null;

            if ($action === null) {
                $this->reply($user, Lang::t('error.unknown_command', $this->locale($user)), $this->menuMarkup($user));

                return true;
            }
        } else {
            $text = trim((string) $u->text());

            if ($text === '') {
                return false;
            }

            $action = $this->menuAction($text);

            if ($action === null) {
                return false;
            }
        }

        match ($action) {
            'start'     => $this->start($user, $u),
            'help'      => $this->help($user),
            'register'  => $this->register($user),
            'profile'   => $this->profile($user),
            'language'  => $this->language($user),
            'about'     => $this->about($user),
            'cancel'    => $this->cancel($user),
            'admin'     => $this->adminMenu($user),
            'stats'     => $this->stats($user),
            'export'    => $this->export($user),
            'broadcast' => $this->broadcast($user),
            'panel'     => $this->panelLink($user),
        };

        return true;
    }

    /* --------------------------------------------------------------------
     | Public commands
     */

    /**
     * `/start` — the welcome screen and the main menu.
     *
     * A deep-link payload (`/start ref_school12`) is remembered in
     * `users.state_data['ref']` so the campaign that brought the candidate in
     * survives the whole registration.
     *
     * @param array<string,mixed> $user
     */
    public function start(array $user, Update $u): void
    {
        $locale = $this->locale($user);
        $payload = $this->capturePayload($user, $u);
        $registration = $this->registrationOf($user);
        $open = $this->isRegistrationOpen();

        $blocks = [];
        $blocks[] = Lang::t(
            $registration !== null ? 'start.welcome_back' : 'start.welcome',
            $locale,
            ['name' => Text::esc($this->displayName($user, $locale))]
        );

        // Optional extra paragraph maintained from the admin panel.
        $extra = $this->app->setting('welcome_extra', '');

        if (is_string($extra) && trim($extra) !== '') {
            $blocks[] = Text::multiline($extra, 900);
        }

        if (!$open) {
            $blocks[] = Lang::t('start.registration_closed', $locale);
        } elseif ($registration === null) {
            $blocks[] = Lang::t('start.cta', $locale);
        }

        if ($payload !== null) {
            $blocks[] = Lang::t('start.deep_link_saved', $locale);
        }

        $this->reply($user, implode("\n\n", $blocks), $this->menuMarkup($user, $registration !== null));
    }

    /**
     * `/help`, `/yordam` — what the bot does plus the command list.
     *
     * @param array<string,mixed> $user
     */
    public function help(array $user): void
    {
        $locale = $this->locale($user);

        $lines = [
            Lang::t('help.title', $locale),
            '',
            Lang::t('help.body', $locale),
            '',
            Lang::t('help.commands', $locale),
            Lang::t('help.cmd_start', $locale),
            Lang::t('help.cmd_register', $locale),
            Lang::t('help.cmd_profile', $locale),
            Lang::t('help.cmd_language', $locale),
            Lang::t('help.cmd_about', $locale),
            Lang::t('help.cmd_help', $locale),
            Lang::t('help.cmd_cancel', $locale),
        ];

        // Administrators additionally see the commands that are not in the
        // public BotFather menu.
        if ($this->isAdmin($user)) {
            $lines[] = '';
            $lines[] = Lang::t('admin.menu_title', $locale);

            foreach ($this->adminCommands($locale) as $command => $label) {
                $lines[] = '<code>/' . $command . '</code> — ' . $label;
            }
        }

        $lines[] = '';
        $lines[] = Lang::t('help.contact', $locale);
        $lines[] = Lang::t('help.footer', $locale);

        $this->reply($user, implode("\n", $lines), $this->menuMarkup($user));
    }

    /**
     * `/language`, `/til` — the language picker.
     *
     * The buttons carry the `l:` callback prefix owned by the registration
     * flow, so the very same screen works inside and outside a registration.
     *
     * @param array<string,mixed> $user
     */
    public function language(array $user, ?int $editMessageId = null): void
    {
        $locale = $this->locale($user);

        $text = implode("\n\n", [
            Lang::t('lang.title', $locale),
            Lang::t('lang.choose', $locale),
            Lang::t('lang.current', $locale, ['language' => Text::esc(Lang::t('lang.native_' . $locale, $locale))]),
            Lang::t('lang.hint', $locale),
        ]);

        $buttons = [];

        foreach ($this->app->locales() as $code) {
            $label = Lang::t('btn.lang_' . $code, $locale);
            $buttons[] = Keyboard::btn($code === $locale ? '✅ ' . $label : $label, 'l:' . $code);
        }

        $markup = Keyboard::inline(Keyboard::rows($buttons, 2));
        $chatId = $this->chatId($user);

        if ($chatId === 0) {
            return;
        }

        if ($editMessageId !== null) {
            $this->app->api()->editMessageText($chatId, $editMessageId, $text, ['reply_markup' => $markup]);

            return;
        }

        $this->app->api()->sendMessage($chatId, $text, ['reply_markup' => $markup]);
    }

    /**
     * `/profile`, `/profil` — the application card of the current user.
     *
     * The card itself belongs to the registration flow: it is the same screen
     * the wizard shows after a successful submission, including the "edit my
     * data" button, so it is rendered there instead of a second time here.
     *
     * @param array<string,mixed> $user
     */
    public function profile(array $user): void
    {
        $this->flow()->showProfile($user);
    }

    /**
     * `/about`, `/loyiha` — what the programme is about.
     *
     * @param array<string,mixed> $user
     */
    public function about(array $user): void
    {
        $locale = $this->locale($user);

        $blocks = [
            Lang::t('about.title', $locale),
            Lang::t('about.body', $locale),
            Lang::t('about.goal', $locale),
            Lang::t('about.who', $locale),
            Lang::t('about.stages', $locale),
        ];

        // The catalogue is the single source of truth for the direction list.
        $directions = [Lang::t('about.directions_title', $locale)];

        foreach (Catalog::directionKeys() as $key) {
            $directions[] = '• ' . Text::esc(Catalog::directionLabel($key, $locale));
        }

        $blocks[] = implode("\n", $directions);
        $blocks[] = Lang::t('about.organizer', $locale);

        $contact = $this->contactHandle();

        if ($contact !== null) {
            $blocks[] = Lang::t('about.contact', $locale, ['contact' => Text::esc($contact)]);
        }

        $blocks[] = Lang::t('about.footer', $locale);

        $this->reply($user, implode("\n\n", $blocks), $this->menuMarkup($user));
    }

    /**
     * `/cancel`, `/bekor` — leave whatever the user is in the middle of.
     *
     * @param array<string,mixed> $user
     */
    public function cancel(array $user): void
    {
        $locale = $this->locale($user);
        $state = (string) ($user['state'] ?? 'idle');
        $telegramId = $this->chatId($user);

        // Inside the registration wizard the flow owns the goodbye message.
        if (str_starts_with($state, Step::STATE_PREFIX)) {
            $this->flow()->cancel($user);

            return;
        }

        if ($state === self::STATE_BROADCAST) {
            $this->app->users()->clearState($telegramId);
            $this->reply($user, Lang::t('admin.broadcast_cancelled', $locale), $this->menuMarkup($user));

            return;
        }

        // Any other admin input state (search, ...): drop it and show the menu again.
        if (str_starts_with($state, self::STATE_ADMIN_PREFIX) && $this->isAdmin($user)) {
            $this->app->users()->clearState($telegramId);
            $this->adminBot()->menu($user);

            return;
        }

        if ($state !== '' && $state !== 'idle') {
            $this->app->users()->clearState($telegramId);
            $this->reply($user, Lang::t('reg.cancelled', $locale), $this->menuMarkup($user));

            return;
        }

        $this->reply($user, Lang::t('reg.not_started', $locale), $this->menuMarkup($user));
    }

    /* --------------------------------------------------------------------
     | Admin commands
     */

    /**
     * `/stats` — the headline numbers straight in the chat.
     *
     * @param array<string,mixed> $user
     */
    public function stats(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $locale = $this->locale($user);
        $stats = $this->app->stats();
        $overview = $stats->overview();

        $lines = [Lang::t('admin.stats_title', $locale), ''];

        $cards = [
            'admin.stat_users'         => (string) $overview['users'],
            'admin.stat_registrations' => (string) $overview['registrations'],
            'admin.stat_today'         => (string) $overview['today'],
            'admin.stat_yesterday'     => (string) $overview['yesterday'],
            'admin.stat_week'          => (string) $overview['week'],
            'admin.stat_month'         => (string) $overview['month'],
            'admin.stat_pending'       => (string) $overview['pending'],
            'admin.stat_approved'      => (string) $overview['approved'],
            'admin.stat_rejected'      => (string) $overview['rejected'],
            'admin.stat_blocked'       => (string) $overview['blocked'],
            'admin.stat_conversion'    => number_format($overview['conversion'], 1, '.', ' ') . '%',
        ];

        foreach ($cards as $key => $value) {
            $lines[] = Lang::t($key, $locale) . ': <b>' . Text::esc($value) . '</b>';
        }

        foreach (
            [
                'admin.top_directions' => $stats->byDirection(),
                'admin.top_districts'  => $stats->byDistrict(),
            ] as $titleKey => $rows
        ) {
            if ($rows === []) {
                continue;
            }

            $lines[] = '';
            $lines[] = Lang::t($titleKey, $locale);

            foreach (array_slice($rows, 0, self::TOP_LIMIT) as $row) {
                $label = $locale === 'ru' ? (string) $row['label_ru'] : (string) $row['label_uz'];
                $lines[] = '• ' . Text::esc($label) . ' — <b>' . (int) $row['count'] . '</b>';
            }
        }

        $this->reply($user, implode("\n", $lines));
    }

    /**
     * `/export` — build the XLSX workbook and upload it as a document.
     *
     * The workbook is written to a private temporary directory under `data/`
     * (so the real file name reaches Telegram) and removed again afterwards.
     *
     * @param array<string,mixed> $user
     */
    public function export(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $locale = $this->locale($user);
        $chatId = $this->chatId($user);

        if ($chatId === 0) {
            return;
        }

        $this->reply($user, Lang::t('admin.export_preparing', $locale));
        $this->app->api()->sendChatAction($chatId, 'upload_document');

        $directory = null;
        $path = null;

        try {
            $exporter = new XlsxExporter($this->app->registrations());
            $directory = $this->temporaryDirectory();
            $path = $directory . '/' . $exporter->filename($locale);
            $rows = $exporter->toFile($path, [], $locale);

            if ($rows === 0) {
                $this->reply($user, Lang::t('admin.export_empty', $locale));

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

            $this->reply($user, Lang::t('error.file', $locale));
        } finally {
            $this->cleanTemporary($directory, $path);
        }
    }

    /**
     * `/panel` — the address of the web admin panel.
     *
     * @param array<string,mixed> $user
     */
    public function panelLink(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $locale = $this->locale($user);
        $url = $this->panelUrl();

        if ($url === null) {
            $this->reply($user, Lang::t('admin.panel_link_missing', $locale));

            return;
        }

        $markup = null;

        // Telegram only accepts http(s) URL buttons.
        if (preg_match('~^https?://~i', $url) === 1) {
            $markup = Keyboard::inline([[Keyboard::url(Lang::t('btn.web_panel', $locale), $url)]]);
        }

        $this->reply($user, Lang::t('admin.panel_link', $locale, ['url' => Text::esc($url)]), $markup);
    }

    /* --------------------------------------------------------------------
     | Internal actions
     */

    /**
     * Apply a language chosen on the `/til` (or `/start`) picker.
     *
     * @param array<string,mixed> $user
     */
    private function switchLocale(array $user, Update $u, string $code): void
    {
        $telegramId = $this->chatId($user);
        $code = Lang::normalize($code);

        if ($telegramId === 0 || !in_array($code, $this->app->locales(), true)) {
            $this->answer($u, Lang::t('error.invalid_choice', $this->locale($user)), true);

            return;
        }

        $this->app->users()->setLocale($telegramId, $code);
        $user['locale'] = $code;

        // Callback answers are plain text — the HTML of the phrase must go.
        $this->answer($u, strip_tags(Lang::t('lang.changed', $code, [
            'language' => Lang::t('lang.native_' . $code, $code),
        ])));

        // Repaint the picker in the new language, then refresh the menu that is
        // still labelled in the old one.
        $messageId = $u->messageId();

        if ($messageId !== null) {
            $this->language($user, $messageId);
        }

        $this->reply($user, Lang::t('start.menu', $code), $this->menuMarkup($user));
    }

    /**
     * Start (or resume) the registration wizard.
     *
     * @param array<string,mixed> $user
     */
    private function register(array $user): void
    {
        if (!$this->isRegistrationOpen()) {
            $this->reply($user, Lang::t('reg.closed', $this->locale($user)), $this->menuMarkup($user));

            return;
        }

        $this->flow()->start($user);
    }

    /**
     * `/admin` — open the inline admin menu.
     *
     * @param array<string,mixed> $user
     */
    private function adminMenu(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $this->adminBot()->menu($user);
    }

    /**
     * `/broadcast` — ask for the message text; AdminBotHandler picks the answer
     * up through the `admin:broadcast` state.
     *
     * @param array<string,mixed> $user
     */
    private function broadcast(array $user): void
    {
        if (!$this->requireAdmin($user)) {
            return;
        }

        $locale = $this->locale($user);
        $this->app->users()->setState($this->chatId($user), self::STATE_BROADCAST, []);

        $this->reply(
            $user,
            Lang::t('admin.broadcast_prompt', $locale) . "\n\n" . Lang::t('admin.cancel_hint', $locale)
        );
    }

    /* --------------------------------------------------------------------
     | Rendering helpers
     */

    /**
     * The persistent main menu.
     *
     * @param array<string,mixed> $user
     * @param ?bool               $registered Pass it in when it is already known.
     */
    private function menuMarkup(array $user, ?bool $registered = null): string
    {
        $locale = $this->locale($user);

        if ($registered === null) {
            $registered = $this->registrationOf($user) !== null;
        }

        $rows = [];

        if ($this->isRegistrationOpen()) {
            if (!$registered) {
                $rows[] = [Lang::t('btn.register', $locale)];
            } elseif ($this->allowsEdit()) {
                $rows[] = [Lang::t('btn.register_again', $locale)];
            }
        }

        $rows[] = [Lang::t('btn.my_profile', $locale), Lang::t('btn.language', $locale)];
        $rows[] = [Lang::t('btn.about', $locale), Lang::t('btn.help', $locale)];

        if ($this->isAdmin($user)) {
            $rows[] = [Lang::t('btn.admin_menu', $locale)];
        }

        return Keyboard::reply($rows, true, false, Lang::t('common.select', $locale));
    }

    /**
     * Which action a plain text message maps to, if any.
     *
     * Both locales are checked: a user who switches the language mid-session
     * still has the old keyboard on screen.
     */
    private function menuAction(string $text): ?string
    {
        foreach ($this->app->locales() as $locale) {
            foreach (self::MENU_BUTTONS as $key => $action) {
                if (Lang::t($key, $locale) === $text) {
                    return $action;
                }
            }
        }

        return null;
    }

    /**
     * The admin-only commands with a human readable label.
     *
     * @return array<string,string>
     */
    private function adminCommands(string $locale): array
    {
        return [
            'admin'     => Lang::t('btn.admin_menu', $locale),
            'stats'     => Lang::t('btn.stats', $locale),
            'export'    => Lang::t('btn.export_xlsx', $locale),
            'broadcast' => Lang::t('btn.broadcast', $locale),
            'panel'     => Lang::t('btn.web_panel', $locale),
        ];
    }

    /* --------------------------------------------------------------------
     | Small helpers
     */

    /**
     * Remember a `/start <payload>` deep link on the user row.
     *
     * @param array<string,mixed> $user
     *
     * @return ?string The stored payload, or null when there was none.
     */
    private function capturePayload(array $user, Update $u): ?string
    {
        if ($u->command() !== 'start') {
            return null;
        }

        $payload = Text::normalizeSpaces($u->commandArgs());

        // Telegram itself only allows A-Z a-z 0-9 _ - (64 characters) in a
        // deep link, so anything else is somebody experimenting.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $payload) !== 1) {
            return null;
        }

        $telegramId = $this->chatId($user);

        if ($telegramId === 0) {
            return null;
        }

        $this->app->users()->mergeStateData($telegramId, ['ref' => $payload]);
        $this->app->audit()->log('tg:' . $telegramId, 'deep_link', $payload);

        return $payload;
    }

    /**
     * The name used in the greeting.
     *
     * @param array<string,mixed> $user
     */
    private function displayName(array $user, string $locale): string
    {
        foreach (['first_name', 'last_name', 'username'] as $key) {
            $value = trim((string) ($user[$key] ?? ''));

            if ($value !== '') {
                return Text::truncate($value, 40);
            }
        }

        return Lang::t('common.unknown', $locale);
    }

    /**
     * The `@username` of the bot, used as the contact line of /about.
     */
    private function contactHandle(): ?string
    {
        $username = trim((string) $this->app->config('telegram.bot_username', ''));

        return $username === '' ? null : '@' . ltrim($username, '@');
    }

    /**
     * The admin panel URL derived from `app.base_url`.
     */
    private function panelUrl(): ?string
    {
        $base = rtrim(trim((string) $this->app->config('app.base_url', '')), '/');

        return $base === '' ? null : $base . '/admin/';
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
     * Send a message to the user, optionally with a keyboard.
     *
     * @param array<string,mixed> $user
     */
    private function reply(array $user, string $text, ?string $markup = null): void
    {
        $chatId = $this->chatId($user);

        if ($chatId === 0) {
            return;
        }

        $extra = [];

        if ($markup !== null && $markup !== '') {
            $extra['reply_markup'] = $markup;
        }

        $this->app->api()->sendMessage($chatId, $text, $extra);
    }

    /**
     * Answer with "admins only" when the user is not one.
     *
     * @param array<string,mixed> $user
     *
     * @return bool True when the caller may continue.
     */
    private function requireAdmin(array $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        $this->reply($user, Lang::t('admin.no_access', $this->locale($user)));

        return false;
    }

    /**
     * Answer a callback query when there is one.
     */
    private function answer(Update $u, string $text, bool $alert = false): void
    {
        $id = $u->callbackId();

        if ($id !== null) {
            $this->app->api()->answerCallbackQuery($id, $text, $alert);
        }
    }

    /**
     * True while the user is somewhere inside the registration wizard.
     *
     * @param array<string,mixed> $user
     */
    private function inRegistration(array $user): bool
    {
        return str_starts_with((string) ($user['state'] ?? ''), Step::STATE_PREFIX);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function isAdmin(array $user): bool
    {
        return $this->app->isAdmin($this->chatId($user));
    }

    /**
     * The private chat id of a user row (equal to the Telegram user id).
     *
     * @param array<string,mixed> $user
     */
    private function chatId(array $user): int
    {
        return (int) ($user['telegram_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $user
     */
    private function locale(array $user): string
    {
        $locale = $user['locale'] ?? null;

        return Lang::normalize(is_string($locale) && $locale !== '' ? $locale : $this->app->defaultLocale());
    }

    /**
     * The application of this user, or null.
     *
     * @param array<string,mixed> $user
     *
     * @return ?array<string,mixed>
     */
    private function registrationOf(array $user): ?array
    {
        $telegramId = $this->chatId($user);

        return $telegramId === 0 ? null : $this->app->registrations()->findByTelegramId($telegramId);
    }

    /**
     * `registration_open` from the settings table, falling back to config.
     */
    private function isRegistrationOpen(): bool
    {
        return $this->toBool($this->app->setting('registration_open', true), true);
    }

    /**
     * Whether a candidate may change an application that was already sent.
     */
    private function allowsEdit(): bool
    {
        return $this->toBool($this->app->config('app.allow_edit', true), true);
    }

    /**
     * Settings arrive as booleans, integers or strings depending on where they
     * were written; normalise all of them the same way.
     */
    private function toBool(mixed $value, bool $default): bool
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
            $normalized = strtolower(trim($value));

            if ($normalized === '') {
                return $default;
            }

            return !in_array($normalized, ['0', 'false', 'no', 'off', 'null'], true);
        }

        return $default;
    }

    private function flow(): Flow
    {
        return $this->flow ??= new Flow($this->app);
    }

    private function adminBot(): AdminBotHandler
    {
        return $this->adminBot ??= new AdminBotHandler($this->app);
    }
}
