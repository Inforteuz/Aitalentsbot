<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  Andijon AI Talents — command line tool
 * =============================================================================
 *
 *      php cli.php <command> [arguments] [options]
 *      php cli.php help
 *
 *  Commands:
 *      migrate                 create / update the database schema
 *      webhook:set [url]       point Telegram at this installation
 *      webhook:delete          remove the webhook
 *      webhook:info            show the webhook Telegram currently knows about
 *      poll                    long polling loop (local development only)
 *      broadcast:run [id]      drain the queued broadcast batches (cron entry point)
 *      export [path]           write the registrations into an .xlsx workbook
 *      admin:hash <password>   generate the admin panel password hash
 *      stats                   print the registration statistics
 *      cleanup                 purge old logs, audit rows and stale rate limits
 *      help                    usage (the default when no command is given)
 *
 *  Design notes:
 *   - the file refuses to run over HTTP, the same guard tools/seed.php uses;
 *   - `help` and `admin:hash` work BEFORE config.php exists — you need the hash
 *     to write config.php in the first place — so the application is booted
 *     lazily, only for the commands that actually need it;
 *   - every command is wrapped in try/catch and returns an exit code: 0 on
 *     success, 1 on failure, so cron jobs and CI can react to it;
 *   - colours are switched off automatically when the output is redirected to a
 *     file, when NO_COLOR is set or when --no-color is passed.
 *
 *  PHP 8.1 compatible. No Composer, no external libraries.
 * =============================================================================
 */

use AiTalents\App;
use AiTalents\Export\XlsxExporter;
use AiTalents\Export\XlsxWriter;
use AiTalents\Migrator;
use AiTalents\Registration\Catalog;
use AiTalents\Router;
use AiTalents\Service\BroadcastService;
use AiTalents\Telegram\ApiException;
use AiTalents\Telegram\Update;
use AiTalents\Text;

/* =========================================================================
 | 1. CLI guard — this file must never be reachable over the web
 ========================================================================= */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
    }

    echo "cli.php is a command line tool and cannot be run over HTTP.\n"
        . "Run it from a shell instead:  php cli.php help\n\n"
        . "cli.php faqat buyruqlar qatoridan ishlaydi.\n";

    exit(1);
}

/* =========================================================================
 | 2. Output helpers
 ========================================================================= */

/** ANSI sequences, keyed by the style names used below. */
const CLI_STYLES = [
    'reset'   => "\033[0m",
    'bold'    => "\033[1m",
    'dim'     => "\033[2m",
    'red'     => "\033[31m",
    'green'   => "\033[32m",
    'yellow'  => "\033[33m",
    'blue'    => "\033[34m",
    'magenta' => "\033[35m",
    'cyan'    => "\033[36m",
];

/** Width of the label column in the aligned key/value output. */
const CLI_LABEL_WIDTH = 26;

/** Update kinds the bot subscribes to (kept in sync with the admin panel). */
const CLI_ALLOWED_UPDATES = ['message', 'edited_message', 'callback_query', 'my_chat_member'];

/**
 * Remember (and answer) whether colours may be used.
 */
function cli_colors(?bool $set = null): bool
{
    static $enabled = null;

    if ($set !== null) {
        $enabled = $set;
    }

    if ($enabled !== null) {
        return $enabled;
    }

    // Explicit opt-out / opt-in through the environment.
    if (getenv('NO_COLOR') !== false) {
        return $enabled = false;
    }

    if (getenv('FORCE_COLOR') !== false) {
        return $enabled = true;
    }

    // Windows terminals only understand ANSI in newer hosts.
    if (DIRECTORY_SEPARATOR === '\\') {
        return $enabled = getenv('ANSICON') !== false
            || getenv('WT_SESSION') !== false
            || getenv('ConEmuANSI') === 'ON'
            || getenv('TERM_PROGRAM') === 'vscode';
    }

    if (function_exists('stream_isatty')) {
        return $enabled = @stream_isatty(STDOUT);
    }

    if (function_exists('posix_isatty')) {
        return $enabled = @posix_isatty(STDOUT);
    }

    return $enabled = false;
}

/**
 * Wrap a string in an ANSI style (a no-op when colours are off).
 */
function cli_style(string $text, string ...$styles): string
{
    if ($text === '' || !cli_colors()) {
        return $text;
    }

    $prefix = '';

    foreach ($styles as $style) {
        $prefix .= CLI_STYLES[$style] ?? '';
    }

    return $prefix === '' ? $text : $prefix . $text . CLI_STYLES['reset'];
}

/** True when the terminal can be trusted with box drawing / check marks. */
function cli_unicode(): bool
{
    return DIRECTORY_SEPARATOR !== '\\';
}

/** True when standard input is an interactive terminal (safe to prompt). */
function cli_stdin_is_tty(): bool
{
    if (function_exists('stream_isatty')) {
        return @stream_isatty(STDIN);
    }

    if (function_exists('posix_isatty')) {
        return @posix_isatty(STDIN);
    }

    return false;
}

/** Write one line to STDOUT. */
function cli_out(string $line = ''): void
{
    fwrite(STDOUT, $line . PHP_EOL);
}

/** Write one line to STDERR. */
function cli_err(string $line = ''): void
{
    fwrite(STDERR, $line . PHP_EOL);
}

/** A section heading with an underline. */
function cli_heading(string $title): void
{
    cli_out();
    cli_out(cli_style($title, 'bold', 'cyan'));
    cli_out(cli_style(str_repeat(cli_unicode() ? '─' : '-', max(8, mb_strlen($title, 'UTF-8'))), 'dim'));
}

/**
 * An aligned "label   value" line (mb_str_pad is PHP 8.3+, so pad by hand).
 */
function cli_kv(string $label, string $value, string $style = ''): void
{
    $pad = max(1, CLI_LABEL_WIDTH - mb_strlen($label, 'UTF-8'));
    $value = $style === '' ? $value : cli_style($value, $style);

    cli_out('  ' . cli_style($label, 'dim') . str_repeat(' ', $pad) . $value);
}

/** A green success line. */
function cli_ok(string $message): void
{
    cli_out('  ' . cli_style(cli_unicode() ? '✔' : '+', 'green') . ' ' . $message);
}

/** A red failure line (STDERR, so `| grep` pipelines stay clean). */
function cli_fail(string $message): void
{
    cli_err('  ' . cli_style(cli_unicode() ? '✖' : 'x', 'red') . ' ' . $message);
}

/** A yellow warning line. */
function cli_warn(string $message): void
{
    cli_out('  ' . cli_style('!', 'yellow') . ' ' . $message);
}

/** A neutral information line. */
function cli_info(string $message): void
{
    cli_out('  ' . cli_style(cli_unicode() ? '·' : '-', 'dim') . ' ' . $message);
}

/**
 * Never print the bot token, not even by accident (log pastes, screenshots).
 */
function cli_mask(string $text): string
{
    if ($text === '') {
        return $text;
    }

    // Read the token straight from the booted instance: masking must never be
    // the thing that boots the application (or that fails while reporting an
    // error which happened before the configuration was even loaded).
    $app = $GLOBALS['AITALENTS_APP'] ?? null;

    if (!$app instanceof App) {
        return $text;
    }

    try {
        $token = trim((string) $app->config('telegram.token', ''));
    } catch (\Throwable $e) {
        return $text;
    }

    return $token === '' ? $text : str_replace($token, '***TOKEN***', $text);
}

/**
 * Turn any scalar into something printable.
 */
function cli_value(mixed $value): string
{
    if ($value === null) {
        return cli_style('—', 'dim');
    }

    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }

    if (is_array($value)) {
        return $value === [] ? cli_style('—', 'dim') : implode(', ', array_map(
            static fn (mixed $item): string => is_scalar($item) ? (string) $item : gettype($item),
            $value
        ));
    }

    if (is_scalar($value)) {
        $text = (string) $value;

        return $text === '' ? cli_style('—', 'dim') : cli_mask($text);
    }

    return gettype($value);
}

/**
 * A tiny horizontal bar for the stats command.
 */
function cli_bar(int $value, int $max, int $width = 24): string
{
    if ($max <= 0 || $value <= 0) {
        return '';
    }

    $filled = (int) max(1, round($value / $max * $width));
    $filled = min($width, $filled);

    return cli_style(str_repeat(cli_unicode() ? '█' : '#', $filled), 'cyan');
}

/* =========================================================================
 | 3. Argument parsing
 ========================================================================= */

/**
 * Split the argv tail into positional arguments and --options.
 *
 * @param array<int,string> $argv everything after the command name
 *
 * @return array{args:array<int,string>,options:array<string,string|bool>}
 */
function cli_parse(array $argv): array
{
    $args = [];
    $options = [];

    foreach ($argv as $argument) {
        if (str_starts_with($argument, '--')) {
            $body = substr($argument, 2);

            if ($body === '') {
                continue;
            }

            $position = strpos($body, '=');

            if ($position === false) {
                $options[$body] = true;
            } else {
                $options[substr($body, 0, $position)] = substr($body, $position + 1);
            }

            continue;
        }

        $args[] = $argument;
    }

    return ['args' => $args, 'options' => $options];
}

/**
 * Read an option as a string.
 *
 * @param array<string,string|bool> $options
 */
function cli_option(array $options, string $name, string $default = ''): string
{
    $value = $options[$name] ?? null;

    if ($value === null || $value === true) {
        return $value === true ? '1' : $default;
    }

    return (string) $value;
}

/**
 * Read an option as an integer, clamped into a sane range.
 *
 * @param array<string,string|bool> $options
 */
function cli_option_int(array $options, string $name, int $default, int $min, int $max): int
{
    $raw = cli_option($options, $name, '');

    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }

    return max($min, min($max, (int) $raw));
}

/**
 * Read a boolean flag (`--flag` or `--flag=1`).
 *
 * @param array<string,string|bool> $options
 */
function cli_flag(array $options, string $name): bool
{
    if (!array_key_exists($name, $options)) {
        return false;
    }

    $value = $options[$name];

    if ($value === true) {
        return true;
    }

    return !in_array(strtolower((string) $value), ['0', 'false', 'no', 'off', ''], true);
}

/* =========================================================================
 | 4. Lazy application boot
 ========================================================================= */

/**
 * The booted application (config.php is only required from here on).
 */
function cli_app(): App
{
    static $app = null;

    if ($app instanceof App) {
        return $app;
    }

    /** @var App $booted */
    $booted = require __DIR__ . '/bootstrap.php';

    return $app = $booted;
}

/**
 * Cooperative stop flag for the long running loops (poll, broadcast:run).
 */
function cli_stopping(?bool $set = null): bool
{
    static $stop = false;

    if ($set !== null) {
        $stop = $set;
    }

    return $stop;
}

/**
 * Install SIGINT/SIGTERM handlers when ext-pcntl is available, so Ctrl+C stops
 * the loop after the current update instead of killing it mid-write.
 */
function cli_install_signals(): void
{
    if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
        return;
    }

    pcntl_async_signals(true);

    $handler = static function (): void {
        cli_stopping(true);
        cli_out();
        cli_warn('Stop requested — finishing the current item…');
    };

    if (defined('SIGINT')) {
        pcntl_signal(SIGINT, $handler);
    }

    if (defined('SIGTERM')) {
        pcntl_signal(SIGTERM, $handler);
    }
}

/* =========================================================================
 | 5. Commands
 ========================================================================= */

/**
 * Usage text.
 */
function cli_usage(): string
{
    return <<<TXT

    Usage:
      php cli.php <command> [arguments] [options]

    Commands:
      migrate                    Create or update the database schema. Safe to re-run.
      webhook:set [url]          Point Telegram at this installation. Without a url the
                                 address is built from app.base_url + /index.php.
                                 Options: --drop-pending
      webhook:delete             Remove the webhook; the bot stops receiving updates.
                                 Options: --drop-pending
      webhook:info               Show the webhook Telegram currently knows about.
      poll                       Long polling loop for local development (no webhook!).
                                 Options: --timeout=25 --limit=100 --once --drop-webhook
      broadcast:run [id]         Send the pending broadcast batches. This is the cron
                                 entry point; without an id every running campaign is
                                 drained. Options: --batch=20 --max-batches=0 (0 = all)
      export [path]              Write the registrations into an .xlsx workbook.
                                 Options: --status= --district= --direction=
                                          --from=YYYY-MM-DD --to=YYYY-MM-DD --locale=uz
      admin:hash <password>      Generate the hash for security.admin_panel.password_hash.
                                 Options: --stdin (read the password from standard input)
      stats                      Print the registration statistics.
      cleanup                    Purge old log files, old audit rows and stale rate limits.
                                 Options: --days=90 --keep-logs
      help                       Show this text.

    Global options:
      --no-color                 Disable ANSI colours (NO_COLOR is honoured as well).

    Examples:
      php cli.php migrate
      php cli.php webhook:set https://domen.uz/bot/index.php
      php cli.php webhook:info
      php cli.php export data/exports/arizalar.xlsx --status=approved
      php cli.php admin:hash 'JudaKuchliParol123!'
      php cli.php broadcast:run --batch=25

    Cron (every minute, drains the broadcast queue):
      cd /home/USER/public_html/bot && /usr/local/bin/php cli.php broadcast:run >/dev/null 2>&1

    O'zbekcha: buyruqlar ro'yxati yuqorida. Batafsil qo'llanma — docs/ papkasida.
    TXT;
}

/**
 * help — the default command.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_help(array $args, array $options): int
{
    // AITALENTS_VERSION only exists once bootstrap.php ran; help must also work
    // before config.php is written, so fall back to the shipped version number.
    $version = defined('AITALENTS_VERSION') ? (string) AITALENTS_VERSION : '1.0.0';

    cli_out(
        cli_style('Andijon AI Talents', 'bold', 'cyan')
        . cli_style(' — konsol vositasi / command line tool  v' . $version, 'dim')
    );
    cli_out(cli_usage());
    cli_out();

    return 0;
}

/**
 * migrate — install or update the schema.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_migrate(array $args, array $options): int
{
    $app = cli_app();
    $db = $app->db();
    $migrator = new Migrator($db);

    cli_heading('Migratsiya / migrations');

    cli_kv('Driver', $db->driver());
    cli_kv(
        'Target',
        $db->driver() === 'sqlite'
            ? (string) $db->sqlitePath()
            : (string) $app->config('database.database', '')
    );

    // Touching pdo() here turns a connection problem into a clean error message
    // before any DDL is attempted.
    $db->pdo();

    $pending = $migrator->pending();

    cli_kv('Pending', $pending === [] ? '0' : (string) count($pending));
    cli_out();

    $applied = 0;

    foreach ($migrator->run() as $line) {
        if (str_starts_with($line, 'applied') || str_starts_with($line, 'created')) {
            $applied++;
            cli_ok($line);

            continue;
        }

        cli_info($line);
    }

    cli_out();

    if ($applied === 0) {
        cli_ok('Schema is already up to date — nothing to do.');
    } else {
        cli_ok($applied . ' migration(s) applied.');
    }

    if (!$migrator->isInstalled()) {
        cli_fail('The schema still looks incomplete — check the errors above.');

        return 1;
    }

    return 0;
}

/**
 * The webhook URL this installation should use.
 */
function cli_webhook_url(App $app): string
{
    $base = rtrim(trim((string) $app->config('app.base_url', '')), '/');

    return $base === '' ? '' : $base . '/index.php';
}

/**
 * True for an https URL Telegram will accept.
 */
function cli_is_webhook_url(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = (string) parse_url($url, PHP_URL_HOST);

    return $scheme === 'https' && $host !== '';
}

/**
 * Pull the description out of an Api::tryCall() envelope.
 *
 * @param array<string,mixed>|null $response
 */
function cli_api_error(?array $response): string
{
    if ($response === null) {
        return 'no response';
    }

    $description = trim((string) ($response['description'] ?? ''));
    $code = (int) ($response['error_code'] ?? 0);

    if ($description === '') {
        $description = 'unknown error';
    }

    return cli_mask($code > 0 ? $description . ' (HTTP ' . $code . ')' : $description);
}

/**
 * webhook:set — register the webhook with Telegram.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_webhook_set(array $args, array $options): int
{
    $app = cli_app();

    $url = trim($args[0] ?? '');

    if ($url === '') {
        $url = cli_webhook_url($app);
    }

    cli_heading('Webhook: set');

    if ($url === '') {
        cli_fail('No URL given and app.base_url is empty in config.php.');
        cli_info('Usage: php cli.php webhook:set https://domen.uz/bot/index.php');

        return 1;
    }

    if (!cli_is_webhook_url($url)) {
        cli_fail('Not a valid https URL: ' . $url);
        cli_info('Telegram only accepts https:// addresses with a valid certificate.');

        return 1;
    }

    $secret = trim((string) $app->config('telegram.webhook_secret', ''));
    $dropPending = cli_flag($options, 'drop-pending');

    $params = [
        'url'                  => $url,
        'allowed_updates'      => CLI_ALLOWED_UPDATES,
        'max_connections'      => 40,
        'drop_pending_updates' => $dropPending,
    ];

    if ($secret !== '') {
        $params['secret_token'] = $secret;
    }

    cli_kv('URL', $url);
    cli_kv('Secret token', $secret !== '' ? 'configured' : 'NOT SET', $secret !== '' ? 'green' : 'yellow');
    cli_kv('Drop pending', $dropPending ? 'yes' : 'no');
    cli_out();

    $response = $app->api()->tryCall('setWebhook', $params);

    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        cli_fail('setWebhook failed: ' . cli_api_error($response));

        return 1;
    }

    cli_ok('Webhook registered.');

    if ($secret === '') {
        cli_warn('telegram.webhook_secret is empty — anyone who guesses the URL can post fake updates.');
        cli_info('Set a random 32+ character secret in config.php and run this command again.');
    }

    return cli_cmd_webhook_info([], []);
}

/**
 * webhook:delete — unregister the webhook.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_webhook_delete(array $args, array $options): int
{
    $app = cli_app();

    cli_heading('Webhook: delete');

    $response = $app->api()->tryCall('deleteWebhook', [
        'drop_pending_updates' => cli_flag($options, 'drop-pending'),
    ]);

    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        cli_fail('deleteWebhook failed: ' . cli_api_error($response));

        return 1;
    }

    cli_ok('Webhook removed. The bot will not receive updates until it is set again.');

    return 0;
}

/**
 * webhook:info — what Telegram thinks the webhook is.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_webhook_info(array $args, array $options): int
{
    $app = cli_app();

    cli_heading('Webhook: info');

    $response = $app->api()->tryCall('getWebhookInfo');

    if (!is_array($response) || ($response['ok'] ?? false) !== true) {
        cli_fail('getWebhookInfo failed: ' . cli_api_error($response));

        return 1;
    }

    $info = is_array($response['result'] ?? null) ? $response['result'] : [];
    $url = trim((string) ($info['url'] ?? ''));

    cli_kv('URL', $url === '' ? 'not set' : cli_mask($url), $url === '' ? 'yellow' : 'green');
    cli_kv('Pending updates', (string) (int) ($info['pending_update_count'] ?? 0));
    cli_kv('Custom certificate', ($info['has_custom_certificate'] ?? false) ? 'yes' : 'no');
    cli_kv('Max connections', cli_value($info['max_connections'] ?? null));
    cli_kv('IP address', cli_value($info['ip_address'] ?? null));
    cli_kv('Allowed updates', cli_value($info['allowed_updates'] ?? null));

    $errorDate = (int) ($info['last_error_date'] ?? 0);
    $errorMessage = trim((string) ($info['last_error_message'] ?? ''));

    if ($errorDate > 0 || $errorMessage !== '') {
        cli_kv('Last error', date('Y-m-d H:i:s', $errorDate > 0 ? $errorDate : time()), 'red');
        cli_kv('Message', cli_mask($errorMessage), 'red');
        cli_out();
        cli_warn('Telegram could not deliver an update. Common causes: an invalid certificate,');
        cli_info('a 401 answer (webhook secret mismatch) or a PHP fatal error in index.php.');
    } else {
        cli_out();
        cli_ok('No delivery errors reported.');
    }

    return 0;
}

/**
 * poll — long polling for local development.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_poll(array $args, array $options): int
{
    $app = cli_app();
    $api = $app->api();

    $timeout = cli_option_int($options, 'timeout', 25, 1, 60);
    $limit = cli_option_int($options, 'limit', 100, 1, 100);
    $once = cli_flag($options, 'once');

    cli_heading('Long polling');

    if (!$api->hasToken()) {
        cli_fail('telegram.token is empty in config.php — there is nothing to poll.');

        return 1;
    }

    // A registered webhook and getUpdates cannot coexist (Telegram error 409).
    $info = $api->tryCall('getWebhookInfo');
    $webhookUrl = '';

    if (is_array($info) && ($info['ok'] ?? false) === true && is_array($info['result'] ?? null)) {
        $webhookUrl = trim((string) ($info['result']['url'] ?? ''));
    }

    if ($webhookUrl !== '') {
        if (!cli_flag($options, 'drop-webhook')) {
            cli_fail('A webhook is registered: ' . cli_mask($webhookUrl));
            cli_info('Telegram refuses getUpdates while a webhook is active (error 409).');
            cli_info('Run "php cli.php webhook:delete" first, or pass --drop-webhook.');

            return 1;
        }

        $deleted = $api->tryCall('deleteWebhook', ['drop_pending_updates' => false]);

        if (!is_array($deleted) || ($deleted['ok'] ?? false) !== true) {
            cli_fail('Could not delete the webhook: ' . cli_api_error($deleted));

            return 1;
        }

        cli_ok('Webhook deleted for this session.');
    }

    $me = $api->tryCall('getMe');

    if (is_array($me) && ($me['ok'] ?? false) === true && is_array($me['result'] ?? null)) {
        cli_kv('Bot', '@' . (string) ($me['result']['username'] ?? '?'));
    }

    cli_kv('Poll timeout', $timeout . 's');
    cli_kv('Batch limit', (string) $limit);
    cli_kv('Mode', $once ? 'single batch' : 'loop (Ctrl+C to stop)');
    cli_out();

    cli_install_signals();

    $router = new Router($app);
    $offset = 0;
    $handled = 0;
    $failures = 0;

    // Give up instead of hammering Telegram when the connection stays broken.
    $maxFailures = 5;

    while (!cli_stopping()) {
        try {
            $updates = $api->getUpdates([
                'offset'          => $offset,
                'limit'           => $limit,
                'timeout'         => $timeout,
                'allowed_updates' => CLI_ALLOWED_UPDATES,
            ]);

            $failures = 0;
        } catch (ApiException $e) {
            if ($e->errorCode() === 409) {
                cli_fail('Telegram error 409: another process is polling, or a webhook is set.');

                return 1;
            }

            $failures++;
            cli_fail('getUpdates failed: ' . cli_mask($e->getMessage()));

            if ($once || $failures >= $maxFailures) {
                cli_fail('Giving up after ' . $failures . ' failed attempt(s).');

                return 1;
            }

            cli_info('Retrying in 3 seconds… (' . $failures . '/' . $maxFailures . ')');
            sleep(3);

            continue;
        }

        foreach ($updates as $raw) {
            $updateId = (int) ($raw['update_id'] ?? 0);

            if ($updateId > 0) {
                $offset = max($offset, $updateId + 1);
            }

            $update = new Update($raw);
            $handled++;

            $who = $update->userId();
            $summary = $update->type()
                . ($who !== null ? ' from ' . $who : '')
                . ($update->text() !== null ? ': ' . Text::truncate((string) $update->text(), 48) : '')
                . ($update->callbackData() !== null ? ' [' . (string) $update->callbackData() . ']' : '');

            cli_out(
                cli_style('  ' . date('H:i:s') . ' ', 'dim')
                . cli_style('#' . $updateId, 'magenta') . '  ' . $summary
            );

            try {
                $router->dispatch($update);
            } catch (\Throwable $e) {
                // Router::dispatch() handles its own errors; this is belt and braces.
                cli_fail('dispatch failed: ' . cli_mask($e->getMessage()));
            }
        }

        if ($once) {
            break;
        }
    }

    cli_out();
    cli_ok('Stopped after ' . $handled . ' update(s).');

    if ($webhookUrl !== '') {
        cli_warn('The webhook was deleted — restore it with:');
        cli_info('php cli.php webhook:set ' . cli_mask($webhookUrl));
    }

    return 0;
}

/**
 * broadcast:run — drain the queued batches (the cron entry point).
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_broadcast_run(array $args, array $options): int
{
    $app = cli_app();
    $service = new BroadcastService($app);
    $repository = $app->broadcasts();

    $batchSize = cli_option_int($options, 'batch', 20, 1, 100);
    $maxBatches = cli_option_int($options, 'max-batches', 0, 0, 10000);

    cli_heading('Broadcast');

    $ids = [];
    $requested = trim($args[0] ?? '');

    if ($requested !== '') {
        if (!ctype_digit($requested)) {
            cli_fail('The broadcast id must be a number: php cli.php broadcast:run 12');

            return 1;
        }

        $id = (int) $requested;

        if ($repository->find($id) === null) {
            cli_fail('Broadcast #' . $id . ' does not exist.');

            return 1;
        }

        $ids[] = $id;
    } else {
        foreach ($repository->running(50) as $row) {
            $ids[] = (int) ($row['id'] ?? 0);
        }

        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    if ($ids === []) {
        cli_ok('Nothing to send — no running campaign.');

        return 0;
    }

    cli_install_signals();

    $totalSent = 0;
    $totalFailed = 0;
    $failures = 0;

    foreach ($ids as $id) {
        $broadcast = $repository->find($id);
        $status = (string) ($broadcast['status'] ?? '');

        cli_out();
        cli_kv('Campaign', '#' . $id . ' (' . $status . ')');

        $batches = 0;

        while (!cli_stopping()) {
            try {
                $result = $service->runBatch($id, $batchSize);
            } catch (\Throwable $e) {
                cli_fail('Batch failed: ' . cli_mask($e->getMessage()));
                $failures++;

                break;
            }

            $batches++;
            $totalSent += (int) $result['sent'];
            $totalFailed += (int) $result['failed'];

            $progress = $service->progress($id);

            cli_out(
                '  ' . cli_style('batch ' . $batches, 'dim')
                . '  sent ' . cli_style((string) (int) $result['sent'], 'green')
                . '  failed ' . cli_style((string) (int) $result['failed'], (int) $result['failed'] > 0 ? 'red' : 'dim')
                . '  remaining ' . (int) $result['remaining']
                . '  ' . cli_style(number_format((float) $progress['percent'], 1) . '%', 'cyan')
            );

            if ($result['done'] === true) {
                break;
            }

            if ($maxBatches > 0 && $batches >= $maxBatches) {
                cli_info('Batch limit reached (--max-batches=' . $maxBatches . ') — stopping for this run.');

                break;
            }
        }

        $final = $service->progress($id);

        cli_kv(
            'Result',
            'total ' . (int) $final['total']
            . ', sent ' . (int) $final['sent']
            . ', failed ' . (int) $final['failed']
            . ', remaining ' . (int) $final['remaining']
            . ' — ' . (string) $final['status']
        );
    }

    cli_out();
    cli_ok('Delivered ' . $totalSent . ', failed ' . $totalFailed . '.');

    return $failures > 0 ? 1 : 0;
}

/**
 * export — write the registrations into an .xlsx workbook.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_export(array $args, array $options): int
{
    $app = cli_app();

    $locale = strtolower(cli_option($options, 'locale', $app->defaultLocale()));

    if (!in_array($locale, $app->locales(), true)) {
        $locale = $app->defaultLocale();
    }

    $exporter = new XlsxExporter($app->registrations());

    $path = trim($args[0] ?? '');

    if ($path === '') {
        $path = AITALENTS_ROOT . '/data/exports/' . $exporter->filename($locale);
    }

    // The export is an Excel workbook, never a CSV; keep the extension honest.
    $renamed = false;

    if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
        $path .= '.xlsx';
        $renamed = true;
    }

    $filters = [];

    foreach (['status', 'district', 'direction'] as $name) {
        $value = trim(cli_option($options, $name, ''));

        if ($value !== '') {
            $filters[$name] = $value;
        }
    }

    foreach (['from' => 'date_from', 'to' => 'date_to'] as $option => $filter) {
        $value = trim(cli_option($options, $option, ''));

        if ($value !== '') {
            $filters[$filter] = $value;
        }
    }

    cli_heading('Excel export');

    if ($renamed && ($args[0] ?? '') !== '') {
        cli_warn('The export format is Excel — the file name was completed to ' . basename($path) . '.');
    }

    cli_kv('File', $path);
    cli_kv('Locale', $locale);
    cli_kv('Filters', $filters === [] ? 'none' : (string) json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    cli_kv('ZIP backend', XlsxWriter::zipSupported());
    cli_out();

    $rows = $exporter->toFile($path, $filters, $locale);
    $size = is_file($path) ? (int) filesize($path) : 0;

    cli_ok($rows . ' registration(s) written.');
    cli_kv('Size', Text::bytes($size));

    if ($rows === 0) {
        cli_warn('The workbook only contains the header row — check your filters.');
    }

    return 0;
}

/**
 * admin:hash — generate the panel password hash.
 *
 * Works without config.php: this is usually the very first command an operator
 * runs, before the configuration file even exists.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_admin_hash(array $args, array $options): int
{
    $password = $args[0] ?? '';

    if ($password === '' && cli_flag($options, 'stdin')) {
        $line = fgets(STDIN);
        $password = $line === false ? '' : rtrim($line, "\r\n");
    }

    // Interactive fallback: keeps the password out of the shell history.
    if ($password === '' && function_exists('readline') && cli_stdin_is_tty()) {
        $answer = readline('Parol / password: ');
        $password = is_string($answer) ? trim($answer) : '';
    }

    cli_heading('Admin panel password hash');

    if ($password === '') {
        cli_fail('A password is required.');
        cli_info('Usage: php cli.php admin:hash \'JudaKuchliParol123!\'');
        cli_info('Or:    php cli.php admin:hash --stdin < parol.txt');

        return 1;
    }

    if (mb_strlen($password, 'UTF-8') < 8) {
        cli_warn('That password is shorter than 8 characters — please pick a stronger one.');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);

    if (!is_string($hash) || $hash === '') {
        cli_fail('password_hash() failed on this PHP build.');

        return 1;
    }

    cli_out();
    cli_out('  ' . cli_style($hash, 'green', 'bold'));
    cli_out();
    cli_info('config.php:');
    cli_out();
    cli_out(cli_style("      'admin_panel' => [", 'dim'));
    cli_out(cli_style("          'username'      => 'admin',", 'dim'));
    cli_out("          'password_hash' => '" . $hash . "',");
    cli_out(cli_style('      ],', 'dim'));
    cli_out();
    cli_warn('Never store the plain password anywhere; only this hash belongs in config.php.');

    return 0;
}

/**
 * stats — the numbers the admin panel dashboard shows.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_stats(array $args, array $options): int
{
    $app = cli_app();
    $stats = $app->stats();
    $overview = $stats->overview();

    cli_heading('Statistika / statistics');

    cli_kv('Bot users', (string) $overview['users']);
    cli_kv('Registrations', (string) $overview['registrations']);
    cli_kv('Today', (string) $overview['today']);
    cli_kv('Yesterday', (string) $overview['yesterday']);
    cli_kv('Last 7 days', (string) $overview['week']);
    cli_kv('Last 30 days', (string) $overview['month']);
    cli_kv('Pending', (string) $overview['pending'], 'yellow');
    cli_kv('Approved', (string) $overview['approved'], 'green');
    cli_kv('Rejected', (string) $overview['rejected'], 'red');
    cli_kv('Blocked users', (string) $overview['blocked']);
    cli_kv('Conversion', number_format((float) $overview['conversion'], 1) . '%');

    $topDay = $stats->topDay();

    if (is_array($topDay) && isset($topDay['date'])) {
        cli_kv('Busiest day', (string) $topDay['date'] . ' (' . (int) ($topDay['count'] ?? 0) . ')');
    }

    $directions = $stats->byDirection();

    if ($directions !== []) {
        cli_heading('Yo\'nalishlar / directions');

        $max = (int) ($directions[0]['count'] ?? 0);

        foreach (array_slice($directions, 0, 10) as $row) {
            $key = (string) ($row['key'] ?? '');
            $label = Catalog::hasDirection($key)
                ? Catalog::directionLabel($key, 'uz', false)
                : $key;

            cli_kv($label, str_pad((string) (int) $row['count'], 5, ' ', STR_PAD_LEFT) . ' ' . cli_bar((int) $row['count'], $max));
        }
    }

    $districts = $stats->byDistrict();

    if ($districts !== []) {
        cli_heading('Hududlar / districts');

        $max = (int) ($districts[0]['count'] ?? 0);

        foreach (array_slice($districts, 0, 10) as $row) {
            $key = (string) ($row['key'] ?? '');
            $label = Catalog::hasDistrict($key)
                ? Catalog::districtLabel($key, 'uz')
                : $key;

            cli_kv($label, str_pad((string) (int) $row['count'], 5, ' ', STR_PAD_LEFT) . ' ' . cli_bar((int) $row['count'], $max));
        }
    }

    $daily = $stats->daily(14);

    if ($daily !== []) {
        cli_heading('So\'nggi 14 kun / last 14 days');

        $max = 0;

        foreach ($daily as $row) {
            $max = max($max, (int) ($row['count'] ?? 0));
        }

        foreach ($daily as $row) {
            $count = (int) ($row['count'] ?? 0);
            cli_kv((string) ($row['date'] ?? ''), str_pad((string) $count, 5, ' ', STR_PAD_LEFT) . ' ' . cli_bar($count, $max));
        }
    }

    cli_out();

    return 0;
}

/**
 * cleanup — housekeeping for a long running installation.
 *
 * @param array<int,string>         $args
 * @param array<string,string|bool> $options
 */
function cli_cmd_cleanup(array $args, array $options): int
{
    $app = cli_app();
    $days = cli_option_int($options, 'days', 90, 1, 3650);

    cli_heading('Tozalash / cleanup');

    /* Log files ------------------------------------------------------- */
    if (cli_flag($options, 'keep-logs')) {
        cli_info('Log files kept (--keep-logs).');
    } else {
        $logger = $app->logger();
        $before = count($logger->files());
        $logger->purgeOld();
        $after = count($logger->files());

        cli_ok('Log files: ' . $before . ' -> ' . $after
            . ' (keeping ' . (int) $app->config('log.max_files', 14) . ' days)');
    }

    /* Audit log ------------------------------------------------------- */
    $db = $app->db();

    if ($db->tableExists('audit_log')) {
        $removed = $app->audit()->purgeOlderThan($days);
        cli_ok('Audit rows older than ' . $days . ' days removed: ' . $removed);
    } else {
        cli_info('No audit_log table yet — run "php cli.php migrate" first.');
    }

    /* Rate limit windows ---------------------------------------------- */
    if ($db->tableExists('rate_limits')) {
        // A window is stale once it can no longer influence a decision; ten
        // windows (at least an hour) is a comfortable safety margin.
        $perSeconds = max(60, (int) $app->config('security.rate_limit.per_seconds', 60));
        $cutoff = time() - max(3600, $perSeconds * 10);

        $statement = $db->query(
            'DELETE FROM ' . $db->quoteIdent($db->table('rate_limits'))
            . ' WHERE ' . $db->quoteIdent('window_started_at') . ' < ?',
            [$cutoff]
        );

        cli_ok('Stale rate limit rows removed: ' . $statement->rowCount());
    } else {
        cli_info('No rate_limits table yet — run "php cli.php migrate" first.');
    }

    cli_out();
    cli_ok('Cleanup finished.');

    return 0;
}

/* =========================================================================
 | 6. Dispatch
 ========================================================================= */

/** command name => handler function */
const CLI_COMMANDS = [
    'migrate'        => 'cli_cmd_migrate',
    'webhook:set'    => 'cli_cmd_webhook_set',
    'webhook:delete' => 'cli_cmd_webhook_delete',
    'webhook:info'   => 'cli_cmd_webhook_info',
    'poll'           => 'cli_cmd_poll',
    'broadcast:run'  => 'cli_cmd_broadcast_run',
    'export'         => 'cli_cmd_export',
    'admin:hash'     => 'cli_cmd_admin_hash',
    'stats'          => 'cli_cmd_stats',
    'cleanup'        => 'cli_cmd_cleanup',
    'help'           => 'cli_cmd_help',
];

$cliArgv = is_array($argv ?? null) ? $argv : [];
$cliCommand = strtolower(trim((string) ($cliArgv[1] ?? 'help')));
$cliParsed = cli_parse(array_slice($cliArgv, 2));

// Global flags that must be applied before anything is printed.
if (cli_flag($cliParsed['options'], 'no-color')) {
    cli_colors(false);
}

if ($cliCommand === '' || $cliCommand === '-h' || $cliCommand === '--help') {
    $cliCommand = 'help';
}

if (!isset(CLI_COMMANDS[$cliCommand])) {
    cli_err(cli_style('Unknown command: ' . $cliCommand, 'red'));
    cli_err('Run "php cli.php help" for the list of commands.');

    exit(1);
}

try {
    /** @var callable(array<int,string>, array<string,string|bool>): int $handler */
    $handler = CLI_COMMANDS[$cliCommand];

    $exitCode = (int) $handler($cliParsed['args'], $cliParsed['options']);
} catch (ApiException $e) {
    cli_out();
    cli_fail('Telegram API error: ' . cli_mask($e->getMessage()));

    if ($e->errorCode() === 401) {
        cli_info('The bot token is wrong or was revoked — get a fresh one from @BotFather.');
    } elseif ($e->errorCode() === 409) {
        cli_info('Conflict: a webhook is registered, or another process is polling.');
    }

    $exitCode = 1;
} catch (\PDOException $e) {
    cli_out();
    cli_fail('Database error: ' . cli_mask($e->getMessage()));
    cli_info('Check the "database" section of config.php, then run "php cli.php migrate".');

    $exitCode = 1;
} catch (\Throwable $e) {
    cli_out();
    cli_fail(get_class($e) . ': ' . cli_mask($e->getMessage()));
    cli_err(cli_style('  ' . $e->getFile() . ':' . $e->getLine(), 'dim'));

    $exitCode = 1;
}

exit($exitCode === 0 ? 0 : 1);
