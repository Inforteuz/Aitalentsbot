<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Lang;
use AiTalents\Text;

/**
 * Runtime settings, bot health and maintenance actions.
 *
 * Four values can be changed while the bot is running — they live in the
 * `settings` table and are read through {@see App::setting()}, which falls back
 * to config.php whenever a row is missing:
 *
 *   registration_open   accept new applications, yes or no;
 *   required_channel    the channel a user must join first ('' disables it);
 *   ask_language        ask for the interface language on /start;
 *   welcome_extra       an extra paragraph appended to the welcome message.
 *
 * Everything else on this screen is read-only diagnostics (webhook, getMe,
 * database, PHP) plus three maintenance buttons: re-set the webhook, delete it
 * and clear the log files.
 */
final class SettingsController
{
    /** Longest extra welcome paragraph. */
    private const WELCOME_MAX = 1000;

    /** Update types the webhook subscribes to. */
    private const ALLOWED_UPDATES = ['message', 'edited_message', 'callback_query', 'my_chat_member'];

    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        switch ($action) {
            case 'save':
                $this->save();
                break;

            case 'webhook_set':
                $this->webhookSet();
                break;

            case 'webhook_delete':
                $this->webhookDelete();
                break;

            case 'clear_logs':
                $this->clearLogs();
                break;

            case 'test_bot':
                $this->testBot();
                break;

            default:
                $this->index();
                break;
        }
    }

    /* --------------------------------------------------------------------
     | Screen
     */

    /**
     * Render the settings form together with the diagnostics blocks.
     */
    public function index(): void
    {
        $this->view->render('settings', [
            'title'      => $this->t('panel.settings_title'),
            'subtitle'   => $this->t('panel.settings_subtitle'),
            'settings'   => $this->currentSettings(),
            'webhook'    => $this->webhookInfo(),
            'me'         => $this->botInfo(),
            'dbInfo'     => $this->databaseInfo(),
            'adminIds'   => $this->configAdminIds(),
            'botAdminIds' => $this->app->adminIds(),
            'locales'    => $this->localeOptions(),
            'locale'     => $this->locale(),
        ]);
    }

    /* --------------------------------------------------------------------
     | Actions
     */

    /**
     * Persist the four runtime settings.
     */
    public function save(): void
    {
        $this->requirePost();

        $repository = $this->app->settings();

        $registrationOpen = $this->checkbox('registration_open');
        $askLanguage = $this->checkbox('ask_language');
        $channel = $this->normalizeChannel(Request::str('required_channel'));

        if ($channel === null) {
            $this->flash('error', $this->t('panel.set_required_channel_hint'));
            $this->rememberOld();
            $this->back();

            return; // unreachable: back() always redirects
        }

        $welcome = Text::clean((string) Request::post('welcome_extra', ''), self::WELCOME_MAX);

        $repository->set('registration_open', $registrationOpen);
        $repository->set('ask_language', $askLanguage);
        // An empty string is stored on purpose: it switches the gate off, while
        // deleting the row would let the config.php value take over again.
        $repository->set('required_channel', $channel);
        $repository->set('welcome_extra', $welcome);

        $this->audit('settings.save', null, [
            'registration_open' => $registrationOpen,
            'ask_language'      => $askLanguage,
            'required_channel'  => $channel,
            'welcome_extra'     => mb_strlen($welcome, 'UTF-8'),
        ]);

        $this->forgetOld();
        $this->flash('success', $this->t('panel.settings_saved'));
        $this->back();
    }

    /**
     * Point Telegram at this installation's webhook endpoint.
     */
    public function webhookSet(): void
    {
        $this->requirePost();

        $url = Request::str('url');

        if ($url === '') {
            $url = $this->suggestedWebhookUrl();
        }

        if (!$this->isWebhookUrl($url)) {
            $this->flash('error', $this->t('panel.set_webhook_failed', ['error' => $this->t('error.invalid_choice')]));
            $this->back();

            return; // unreachable: back() always redirects
        }

        $params = [
            'url'                  => $url,
            'allowed_updates'      => self::ALLOWED_UPDATES,
            'drop_pending_updates' => $this->checkbox('drop_pending'),
            'max_connections'      => 40,
        ];

        $secret = trim((string) $this->app->config('telegram.webhook_secret', ''));

        if ($secret !== '') {
            $params['secret_token'] = $secret;
        }

        $response = $this->app->api()->tryCall('setWebhook', $params);

        if ($this->responseOk($response)) {
            $this->audit('settings.webhook_set', null, ['url' => $url]);
            $this->flash('success', $this->t('panel.set_webhook_ok'));
        } else {
            $this->flash('error', $this->t('panel.set_webhook_failed', ['error' => $this->responseError($response)]));
        }

        $this->back();
    }

    /**
     * Remove the webhook (the bot goes silent until it is set again).
     */
    public function webhookDelete(): void
    {
        $this->requirePost();

        $response = $this->app->api()->tryCall('deleteWebhook', [
            'drop_pending_updates' => $this->checkbox('drop_pending'),
        ]);

        if ($this->responseOk($response)) {
            $this->audit('settings.webhook_delete');
            $this->flash('success', $this->t('panel.flash_deleted'));
        } else {
            $this->flash('error', $this->t('panel.set_webhook_failed', ['error' => $this->responseError($response)]));
        }

        $this->back();
    }

    /**
     * Verify that the token still talks to Telegram.
     */
    public function testBot(): void
    {
        $this->requirePost();

        $response = $this->app->api()->tryCall('getMe');

        if ($this->responseOk($response)) {
            $result = is_array($response['result'] ?? null) ? $response['result'] : [];
            $username = trim((string) ($result['username'] ?? ''));

            $this->audit('settings.test_bot', null, ['username' => $username]);
            $this->flash('success', $this->t('panel.set_getme_ok', ['username' => $username]));
        } else {
            $this->flash('error', $this->t('panel.set_getme_failed', ['error' => $this->responseError($response)]));
        }

        $this->back();
    }

    /**
     * Delete every rotated log file.
     */
    public function clearLogs(): void
    {
        $this->requirePost();

        $logger = $this->app->logger();
        $directory = $logger->dir();
        $removed = 0;

        if ($directory !== '' && is_dir($directory)) {
            $files = glob(rtrim($directory, '/\\') . '/bot-*.log');

            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file) && @unlink($file)) {
                        $removed++;
                    }
                }
            }
        }

        $this->audit('settings.clear_logs', null, ['files' => $removed]);
        $this->flash('success', $this->t('panel.set_clear_logs_done'));

        $this->back();
    }

    /* --------------------------------------------------------------------
     | View data
     */

    /**
     * The four runtime settings as the form needs them.
     *
     * @return array{registration_open:bool,required_channel:string,ask_language:bool,welcome_extra:string}
     */
    private function currentSettings(): array
    {
        return [
            'registration_open' => (bool) $this->app->setting('registration_open', true),
            'required_channel'  => trim((string) $this->app->setting('required_channel', '')),
            'ask_language'      => (bool) $this->app->setting('ask_language', true),
            'welcome_extra'     => trim((string) $this->app->setting('welcome_extra', '')),
        ];
    }

    /**
     * getWebhookInfo(), never throwing.
     *
     * @return array{ok:bool,info:array<string,mixed>,url:string,error:?string,suggested:string,secret:bool}
     */
    private function webhookInfo(): array
    {
        $suggested = $this->suggestedWebhookUrl();
        $secret = trim((string) $this->app->config('telegram.webhook_secret', '')) !== '';

        if (!$this->app->api()->hasToken()) {
            return [
                'ok'        => false,
                'info'      => [],
                'url'       => '',
                'error'     => $this->t('panel.not_available'),
                'suggested' => $suggested,
                'secret'    => $secret,
            ];
        }

        $response = $this->app->api()->tryCall('getWebhookInfo');
        $ok = $this->responseOk($response);
        $info = $ok && is_array($response['result'] ?? null) ? $response['result'] : [];

        return [
            'ok'        => $ok,
            'info'      => $info,
            'url'       => trim((string) ($info['url'] ?? '')),
            'error'     => $ok ? null : $this->responseError($response),
            'suggested' => $suggested,
            'secret'    => $secret,
        ];
    }

    /**
     * getMe(), never throwing.
     *
     * @return array{ok:bool,result:array<string,mixed>,username:string,error:?string,configured:bool}
     */
    private function botInfo(): array
    {
        if (!$this->app->api()->hasToken()) {
            return [
                'ok'         => false,
                'result'     => [],
                'username'   => '',
                'error'      => $this->t('panel.not_available'),
                'configured' => false,
            ];
        }

        $response = $this->app->api()->tryCall('getMe');
        $ok = $this->responseOk($response);
        $result = $ok && is_array($response['result'] ?? null) ? $response['result'] : [];

        return [
            'ok'         => $ok,
            'result'     => $result,
            'username'   => trim((string) ($result['username'] ?? '')),
            'error'      => $ok ? null : $this->responseError($response),
            'configured' => true,
        ];
    }

    /**
     * Driver, size and runtime facts shown in the diagnostics card.
     *
     * @return array<string,mixed>
     */
    private function databaseInfo(): array
    {
        $db = $this->app->db();

        $driver = 'unknown';
        $path = null;
        $size = 0;

        try {
            $driver = $db->driver();
            $path = $db->sqlitePath();

            if ($path !== null && is_file($path)) {
                clearstatcache(true, $path);
                $size = (int) filesize($path);
            } elseif ($driver === 'mysql') {
                $size = (int) $db->fetchColumn(
                    'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables'
                    . ' WHERE table_schema = DATABASE()'
                );
            }
        } catch (\Throwable $e) {
            $this->app->logger()->warning('panel.db_info_failed', ['error' => $e->getMessage()]);
        }

        $logger = $this->app->logger();

        return [
            'driver'     => $driver,
            'database'   => (string) $this->app->config('database.database', ''),
            'prefix'     => (string) $this->app->config('database.prefix', ''),
            'path'       => $path,
            'size'       => $size,
            'size_human' => $size > 0 ? Text::bytes($size) : $this->t('panel.not_available'),
            'php'        => PHP_VERSION,
            'version'    => $this->app->version(),
            'timezone'   => date_default_timezone_get(),
            'log_dir'    => $logger->dir(),
            'log_level'  => $logger->level(),
            'log_files'  => count($logger->files()),
        ];
    }

    /**
     * Administrators compiled into config.php (read-only on this screen).
     *
     * @return int[]
     */
    private function configAdminIds(): array
    {
        $raw = $this->app->config('telegram.admin_ids', []);
        $ids = [];

        if (is_array($raw)) {
            foreach ($raw as $id) {
                if (is_int($id) || (is_string($id) && preg_match('/^-?\d+$/', trim($id)) === 1)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function localeOptions(): array
    {
        $available = Lang::available();
        $options = [];

        foreach ($this->app->locales() as $locale) {
            $options[] = [
                'value' => $locale,
                'label' => (string) ($available[$locale] ?? strtoupper($locale)),
            ];
        }

        return $options;
    }

    /* --------------------------------------------------------------------
     | Input
     */

    /**
     * A checkbox: present and truthy means on, absent means off.
     */
    private function checkbox(string $name): bool
    {
        $value = Request::post($name);

        if ($value === null || is_array($value)) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'on', 'true', 'yes'], true);
    }

    /**
     * Normalise the required-channel value.
     *
     * Accepts `@name`, `name`, `t.me/name`, `https://t.me/name` and a numeric
     * chat id; returns '' for "disabled" and null when the value makes no sense.
     */
    private function normalizeChannel(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // A private supergroup/channel is addressed by its numeric id.
        if (preg_match('/^-?\d{5,20}$/', $value) === 1) {
            return $value;
        }

        // Strip a t.me link down to the username it points at.
        if (preg_match('#^(?:https?://)?(?:www\.)?t(?:elegram)?\.me/(?:s/)?([A-Za-z0-9_]+)/?$#i', $value, $m) === 1) {
            $value = $m[1];
        }

        $value = ltrim($value, '@');

        // Telegram usernames: 5-32 characters, starting with a letter.
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $value) !== 1) {
            return null;
        }

        return '@' . $value;
    }

    /**
     * The webhook URL derived from `app.base_url`.
     */
    private function suggestedWebhookUrl(): string
    {
        $base = rtrim(trim((string) $this->app->config('app.base_url', '')), '/');

        return $base === '' ? '' : $base . '/index.php';
    }

    /**
     * Telegram only delivers to https endpoints.
     */
    private function isWebhookUrl(string $url): bool
    {
        if ($url === '' || mb_strlen($url, 'UTF-8') > 500) {
            return false;
        }

        if (stripos($url, 'https://') !== 0) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /* --------------------------------------------------------------------
     | Telegram answers
     */

    /**
     * @param ?array<string,mixed> $response the Api::tryCall() envelope
     */
    private function responseOk(?array $response): bool
    {
        return is_array($response) && ($response['ok'] ?? false) === true;
    }

    /**
     * A one line explanation of a failed call, ready to be shown.
     *
     * @param ?array<string,mixed> $response
     */
    private function responseError(?array $response): string
    {
        $description = is_array($response) ? trim((string) ($response['description'] ?? '')) : '';

        return $description === '' ? $this->t('error.telegram') : Text::truncate($description, 200);
    }

    /* --------------------------------------------------------------------
     | Request plumbing
     */

    private function requirePost(): void
    {
        if (Request::isPost()) {
            return;
        }

        $this->flash('error', $this->t('panel.flash_error'));

        Request::redirect(View::link(['p' => 'settings']));
    }

    private function back(): void
    {
        Request::redirect(View::link(['p' => 'settings']));
    }

    private function rememberOld(): void
    {
        if (function_exists('remember_old')) {
            remember_old();
        }
    }

    private function forgetOld(): void
    {
        if (function_exists('forget_old')) {
            forget_old();
        }
    }

    private function actor(): string
    {
        $user = function_exists('panel_auth') ? panel_auth()->user() : null;

        return 'panel:' . ($user !== null && $user !== '' ? $user : 'unknown');
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function audit(string $action, ?string $target = null, array $meta = []): void
    {
        $this->app->audit()->log($this->actor(), $action, $target, $meta, Request::ip());
    }

    private function flash(string $type, string $message): void
    {
        if (function_exists('flash') && $message !== '') {
            flash($type, $message);
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function t(string $key, array $params = []): string
    {
        return Lang::t($key, $this->locale(), $params);
    }

    private function locale(): string
    {
        if (function_exists('panel_locale')) {
            return panel_locale();
        }

        return $this->app->defaultLocale();
    }
}
