<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  Andijon AI Talents — o'rnatish sahifasi / guided web installer
 * =============================================================================
 *
 *      https://domen.uz/bot/setup.php?key=<security.setup_key>
 *
 *  A single self-contained page that walks an operator through the parts of the
 *  installation that cannot be done by uploading files:
 *
 *      1. PHP version and extension check
 *      2. directory permissions (data/, data/logs/, data/exports/)
 *      3. a summary of config.php (never printing a secret)
 *      4. the database connection test and the migration runner
 *      5. the webhook panel (getWebhookInfo + set / delete)
 *      6. the getMe health check and the BotFather command list
 *      7. the admin panel password hash generator
 *
 *  Security model:
 *   - the page does absolutely nothing without `?key=` matching
 *     `security.setup_key`, compared with hash_equals();
 *   - when `security.setup_key` is empty the installer refuses to run at all,
 *     so an uploaded-and-forgotten setup.php cannot be used by a stranger;
 *   - every state changing action is a POST carrying a token derived from the
 *     setup key (a stray link or an <img> tag can therefore not trigger one);
 *   - no secret is ever echoed: the bot token, the webhook secret, the database
 *     password and the setup key are only ever reported as "set" / "not set".
 *
 *  The stylesheet is inline on purpose: setup.php lives outside the admin panel
 *  and its strict Content-Security-Policy, and it must render correctly on a
 *  freshly uploaded installation where nothing else works yet. There is no
 *  JavaScript at all, which lets the page ship a very tight CSP of its own.
 *
 *  DELETE THIS FILE once the bot is running.
 *
 *  PHP 8.1 compatible. No Composer, no external libraries, no CDN assets.
 * =============================================================================
 */

use AiTalents\App;
use AiTalents\Lang;
use AiTalents\Migrator;
use AiTalents\Text;

/** @var App $app */
$app = require __DIR__ . '/bootstrap.php';

/* =========================================================================
 | 1. Helpers
 ========================================================================= */

if (!function_exists('setup_e')) {
    /**
     * HTML escaping for everything printed on this page.
     */
    function setup_e(?string $value): string
    {
        return Text::esc($value);
    }
}

if (!function_exists('setup_t')) {
    /**
     * A translated string from lang/uz.php.
     *
     * @param array<string,scalar> $params
     */
    function setup_t(string $key, array $params = []): string
    {
        return Lang::t($key, 'uz', $params);
    }
}

if (!function_exists('setup_headers')) {
    /**
     * Response headers shared by the installer and by its refusal page.
     */
    function setup_headers(int $status): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

        // The page carries one inline stylesheet and no script at all.
        header(
            "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; "
            . "img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'"
        );
    }
}

if (!function_exists('setup_refuse')) {
    /**
     * Stop with a 403 page.
     *
     * The "no key configured" case gets a real explanation (the operator is the
     * only one who can hit it before config.php is finished); a wrong key gets
     * nothing but a closed door, after a short delay that makes guessing the
     * key over the network pointless.
     */
    function setup_refuse(bool $keyMissing): never
    {
        if (!$keyMissing) {
            usleep(250000);
        }

        setup_headers(403);

        $title = $keyMissing
            ? 'O\'rnatuvchi o\'chirilgan'
            : 'Kirish taqiqlangan';

        $body = $keyMissing
            ? '<p><code>config.php</code> faylidagi <code>security.setup_key</code> qiymati bo\'sh, '
                . 'shuning uchun o\'rnatuvchi umuman ishlamaydi.</p>'
                . '<pre>\'security\' =&gt; [
    \'setup_key\' =&gt; \'uzun-tasodifiy-kalit-32-belgi\',
],</pre>'
                . '<p>Kalitni yozing va sahifani <code>setup.php?key=...</code> ko\'rinishida oching.</p>'
                . '<p class="muted">EN: set <code>security.setup_key</code> in config.php, then open '
                . '<code>setup.php?key=&lt;that key&gt;</code>.</p>'
            : '<p>Noto\'g\'ri kalit.</p><p class="muted">EN: invalid setup key.</p>';

        echo '<!doctype html><html lang="uz"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>403 — ' . setup_e($title) . '</title><style>'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:radial-gradient(1200px 700px at 50% -10%,#0a1b3d 0%,#050b1a 60%);'
            . 'color:#eef5ff;font:15px/1.65 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;padding:24px}'
            . '.box{max-width:560px;width:100%;background:#0a1730;border:1px solid rgba(124,243,255,.16);'
            . 'border-radius:18px;padding:28px 26px;box-shadow:0 24px 60px rgba(3,8,20,.55)}'
            . 'h1{margin:0 0 6px;font-size:20px;color:#7cf3ff}'
            . 'code,pre{background:rgba(124,243,255,.08);border-radius:8px;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px}'
            . 'code{padding:2px 6px}pre{padding:12px 14px;overflow:auto;border:1px solid rgba(124,243,255,.14)}'
            . '.muted{color:#7089ae;font-size:13px}'
            . '</style></head><body><div class="box"><h1>' . setup_e($title) . '</h1>' . $body . '</div></body></html>';

        exit;
    }
}

/* =========================================================================
 | 2. The gate
 ========================================================================= */

$setupKey = trim((string) $app->config('security.setup_key', ''));

if ($setupKey === '') {
    setup_refuse(true);
}

$providedKey = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';

if ($providedKey === '' || !hash_equals($setupKey, $providedKey)) {
    setup_refuse(false);
}

/** Where every form posts back to (the key stays in the query string). */
$selfUrl = 'setup.php?key=' . rawurlencode($setupKey);

/**
 * CSRF-ish token: derived from the setup key, so it is stable across requests
 * (no session needed on a half-installed site) but unguessable for anybody who
 * does not already know the key — and knowing the key is full access anyway.
 */
$csrfToken = hash_hmac('sha256', 'aitalents:setup:csrf:v1', $setupKey);

/* =========================================================================
 | 3. Actions (POST only)
 ========================================================================= */

/** @var array<int,array{type:string,text:string}> $flashes */
$flashes = [];

/** The freshly generated password hash, when the generator was used. */
$generatedHash = '';

/**
 * Queue a message for the top of the page.
 *
 * @param string $type ok|error|warn|info
 */
$flash = static function (string $type, string $text) use (&$flashes): void {
    $flashes[] = ['type' => $type, 'text' => $text];
};

/**
 * The webhook URL built from app.base_url.
 */
$suggestedWebhookUrl = (static function (App $app): string {
    $base = rtrim(trim((string) $app->config('app.base_url', '')), '/');

    return $base === '' ? '' : $base . '/index.php';
})($app);

/**
 * The bot command list published to BotFather, built from the help.* strings so
 * the menu and /help can never drift apart.
 *
 * @return array<int,array{command:string,description:string}>
 */
$botCommands = static function (string $locale): array {
    $map = [
        'start'  => 'help.cmd_start',
        'royxat' => 'help.cmd_register',
        'profil' => 'help.cmd_profile',
        'til'    => 'help.cmd_language',
        'loyiha' => 'help.cmd_about',
        'yordam' => 'help.cmd_help',
        'bekor'  => 'help.cmd_cancel',
    ];

    $commands = [];

    foreach ($map as $command => $key) {
        $text = Lang::t($key, $locale);

        // The lang strings read "/royxat — ro'yxatdan o'tish"; Telegram wants
        // the description alone, so drop everything up to the dash.
        $dash = mb_strpos($text, '—', 0, 'UTF-8');

        if ($dash !== false) {
            $text = mb_substr($text, $dash + 1, null, 'UTF-8');
        }

        $text = trim($text);

        if ($text === '' || $text === $key) {
            continue; // no usable translation — better to omit the entry
        }

        $commands[] = [
            'command'     => $command,
            'description' => mb_substr($text, 0, 256, 'UTF-8'),
        ];
    }

    return $commands;
};

/**
 * Pull a readable error out of an Api::tryCall() envelope.
 *
 * @param array<string,mixed>|null $response
 */
$apiError = static function (?array $response): string {
    if ($response === null) {
        return 'no response';
    }

    $description = trim((string) ($response['description'] ?? ''));
    $code = (int) ($response['error_code'] ?? 0);

    if ($description === '') {
        $description = setup_t('common.error');
    }

    return $code > 0 ? $description . ' (' . $code . ')' : $description;
};

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = '';

if ($requestMethod === 'POST') {
    $postedToken = isset($_POST['_token']) && is_string($_POST['_token']) ? $_POST['_token'] : '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $flash('error', setup_t('panel.csrf_invalid'));
    } else {
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
    }
}

switch ($action) {
    /* ---------------------------------------------------------------- */
    case 'migrate':
        try {
            $migrator = new Migrator($app->db());
            $lines = $migrator->run();
            $appliedLines = [];

            foreach ($lines as $line) {
                if (!str_starts_with($line, 'skipped')) {
                    $appliedLines[] = $line;
                }
            }

            if ($appliedLines === []) {
                $flash('info', 'Migratsiyalar allaqachon bajarilgan — o\'zgarish yo\'q.');
            } else {
                $flash('ok', 'Migratsiya tugadi: ' . count($appliedLines) . ' ta amal bajarildi.');
            }

            $app->audit()->log('setup', 'setup.migrate', null, ['applied' => count($appliedLines)]);
        } catch (\Throwable $e) {
            $flash('error', setup_t('error.db') . ' — ' . $e->getMessage());
        }

        break;

    /* ---------------------------------------------------------------- */
    case 'webhook_set':
        $url = isset($_POST['url']) && is_string($_POST['url']) ? trim($_POST['url']) : '';

        if ($url === '') {
            $url = $suggestedWebhookUrl;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || $scheme !== 'https') {
            $flash('error', setup_t('panel.set_webhook_failed', [
                'error' => 'faqat https:// bilan boshlanuvchi to\'liq manzil qabul qilinadi',
            ]));

            break;
        }

        $params = [
            'url'                  => $url,
            'allowed_updates'      => ['message', 'edited_message', 'callback_query', 'my_chat_member'],
            'max_connections'      => 40,
            'drop_pending_updates' => isset($_POST['drop_pending']),
        ];

        $secret = trim((string) $app->config('telegram.webhook_secret', ''));

        if ($secret === '') {
            // index.php refuses every update while the secret is empty, so
            // registering the webhook now would only produce a silently dead
            // bot. Hand the operator a ready-made value instead.
            $flash('error', 'telegram.webhook_secret bo\'sh. Webhook o\'rnatilmadi: '
                . 'bo\'sh maxfiy so\'z bilan bot barcha yangilanishlarni rad etadi. '
                . 'config.php ga quyidagini yozing va sahifani yangilang — '
                . '\'webhook_secret\' => \'' . bin2hex(random_bytes(24)) . '\',');

            break;
        }

        $params['secret_token'] = $secret;

        $response = $app->api()->tryCall('setWebhook', $params);

        if (is_array($response) && ($response['ok'] ?? false) === true) {
            $flash('ok', setup_t('panel.set_webhook_ok'));

            $app->audit()->log('setup', 'setup.webhook_set', null, ['url' => $url]);
        } else {
            $flash('error', setup_t('panel.set_webhook_failed', ['error' => $apiError($response)]));
        }

        break;

    /* ---------------------------------------------------------------- */
    case 'webhook_delete':
        $response = $app->api()->tryCall('deleteWebhook', [
            'drop_pending_updates' => isset($_POST['drop_pending']),
        ]);

        if (is_array($response) && ($response['ok'] ?? false) === true) {
            $flash('ok', 'Webhook o\'chirildi. Bot yangilanishlarni qabul qilmaydi.');
            $app->audit()->log('setup', 'setup.webhook_delete');
        } else {
            $flash('error', setup_t('panel.set_webhook_failed', ['error' => $apiError($response)]));
        }

        break;

    /* ---------------------------------------------------------------- */
    case 'set_commands':
        $uzCommands = $botCommands('uz');

        if ($uzCommands === []) {
            $flash('error', setup_t('common.error'));

            break;
        }

        $response = $app->api()->tryCall('setMyCommands', ['commands' => $uzCommands]);

        if (is_array($response) && ($response['ok'] ?? false) === true) {
            $flash('ok', 'Buyruqlar ro\'yxati o\'rnatildi (' . count($uzCommands) . ' ta).');

            // The Russian menu is a bonus: a failure here must not look like a
            // failure of the whole action.
            $ruCommands = $botCommands('ru');

            if ($ruCommands !== []) {
                $ruResponse = $app->api()->tryCall('setMyCommands', [
                    'commands'      => $ruCommands,
                    'language_code' => 'ru',
                ]);

                if (is_array($ruResponse) && ($ruResponse['ok'] ?? false) === true) {
                    $flash('info', 'Ruscha buyruqlar ro\'yxati ham o\'rnatildi.');
                }
            }

            $app->audit()->log('setup', 'setup.set_commands', null, ['count' => count($uzCommands)]);
        } else {
            $flash('error', 'Buyruqlarni o\'rnatib bo\'lmadi: ' . $apiError($response));
        }

        break;

    /* ---------------------------------------------------------------- */
    case 'make_hash':
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';

        if (trim($password) === '') {
            $flash('error', setup_t('error.empty'));

            break;
        }

        if (mb_strlen($password, 'UTF-8') < 8) {
            $flash('warn', 'Parol 8 belgidan qisqa — kuchliroq parol tanlang.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if (!is_string($hash) || $hash === '') {
            $flash('error', setup_t('common.error'));

            break;
        }

        $generatedHash = $hash;
        $flash('ok', 'Xesh tayyor — uni config.php ga ko\'chiring.');

        break;
}

/* =========================================================================
 | 4. Checks
 ========================================================================= */

/** @var array<int,array{label:string,ok:bool,required:bool,value:string,hint:string}> $environmentChecks */
$environmentChecks = [];

$environmentChecks[] = [
    'label'    => setup_t('panel.set_php_version'),
    'ok'       => PHP_VERSION_ID >= 80100,
    'required' => true,
    'value'    => PHP_VERSION,
    'hint'     => PHP_VERSION_ID >= 80100
        ? 'PHP 8.1 yoki undan yuqori — talab bajarildi.'
        : 'PHP 8.1+ kerak. cPanel → MultiPHP Manager orqali versiyani ko\'taring.',
];

$dbDriver = strtolower(trim((string) $app->config('database.driver', 'sqlite')));
$driverExtension = $dbDriver === 'mysql' ? 'pdo_mysql' : 'pdo_sqlite';

/** name => [required, hint] */
$extensions = [
    'pdo'            => [true,  'Ma\'lumotlar bazasi bilan ishlash uchun shart.'],
    $driverExtension => [true,  'Tanlangan drayver (' . $dbDriver . ') uchun shart.'],
    'json'           => [true,  'Telegram javoblarini o\'qish uchun shart.'],
    'mbstring'       => [true,  'O\'zbek va rus matnlari uchun shart.'],
    'curl'           => [false, 'Bo\'lmasa file_get_contents zaxira yo\'li ishlatiladi (sekinroq).'],
    'zip'            => [false, 'Bo\'lmasa Excel eksporti sof PHP ZIP yozuvchisiga o\'tadi.'],
    'openssl'        => [false, 'HTTPS so\'rovlari uchun tavsiya etiladi.'],
];

foreach ($extensions as $extension => $meta) {
    $loaded = extension_loaded($extension);

    $environmentChecks[] = [
        'label'    => 'ext-' . $extension,
        'ok'       => $loaded,
        'required' => $meta[0],
        'value'    => $loaded ? setup_t('common.enabled') : setup_t('common.disabled'),
        'hint'     => $meta[1],
    ];
}

/* --- Directory permissions ------------------------------------------- */

$sqlitePath = $dbDriver === 'sqlite' ? (string) $app->config('database.path', '') : '';

$directories = [
    'data/'         => AITALENTS_ROOT . '/data',
    'data/logs/'    => (string) $app->config('log.dir', AITALENTS_ROOT . '/data/logs'),
    'data/exports/' => AITALENTS_ROOT . '/data/exports',
];

/** @var array<int,array{label:string,ok:bool,required:bool,value:string,hint:string}> $directoryChecks */
$directoryChecks = [];

foreach ($directories as $label => $path) {
    $exists = is_dir($path);
    $writable = $exists && is_writable($path);

    $directoryChecks[] = [
        'label'    => $label,
        'ok'       => $writable,
        'required' => true,
        'value'    => $writable
            ? 'yoziladi'
            : ($exists ? 'yozib bo\'lmaydi' : 'papka yo\'q'),
        'hint'     => $writable
            ? $path
            : 'chmod 755 (kerak bo\'lsa 775): ' . $path,
    ];
}

if ($sqlitePath !== '') {
    $sqliteExists = is_file($sqlitePath);
    $sqliteOk = $sqliteExists ? is_writable($sqlitePath) : is_writable(dirname($sqlitePath));

    $directoryChecks[] = [
        'label'    => 'SQLite fayli',
        'ok'       => $sqliteOk,
        'required' => true,
        'value'    => $sqliteExists
            ? ($sqliteOk ? 'yoziladi' : 'yozib bo\'lmaydi')
            : 'hali yaratilmagan',
        'hint'     => $sqliteExists
            ? 'chmod 664: ' . $sqlitePath
            : 'Migratsiya paytida yaratiladi: ' . $sqlitePath,
    ];
}

/* --- Configuration summary -------------------------------------------- */

$token = trim((string) $app->config('telegram.token', ''));
$botUsername = ltrim(trim((string) $app->config('telegram.bot_username', '')), '@');
$webhookSecret = trim((string) $app->config('telegram.webhook_secret', ''));
$adminIds = $app->adminIds();
$adminChatId = $app->config('telegram.admin_chat_id', null);
$passwordHash = trim((string) $app->config('security.admin_panel.password_hash', ''));
$panelEnabled = (bool) $app->config('security.admin_panel.enabled', true);
$baseUrl = rtrim(trim((string) $app->config('app.base_url', '')), '/');

/** @var array<int,array{label:string,ok:bool,required:bool,value:string,hint:string}> $configChecks */
$configChecks = [
    [
        'label'    => 'telegram.token',
        'ok'       => $token !== '',
        'required' => true,
        'value'    => $token !== '' ? 'kiritilgan' : 'bo\'sh',
        'hint'     => '@BotFather bergan token. Sahifada hech qachon ko\'rsatilmaydi.',
    ],
    [
        'label'    => 'telegram.bot_username',
        'ok'       => $botUsername !== '',
        'required' => false,
        'value'    => $botUsername !== '' ? '@' . $botUsername : 'bo\'sh',
        'hint'     => 'Havolalarni yasashda ishlatiladi (@ belgisisiz yozing).',
    ],
    [
        'label'    => 'telegram.webhook_secret',
        'ok'       => $webhookSecret !== '',
        'required' => false,
        'value'    => $webhookSecret !== '' ? 'kiritilgan' : 'bo\'sh',
        'hint'     => 'index.php shu maxfiy so\'z bilan kelmagan so\'rovni 401 bilan rad etadi.',
    ],
    [
        'label'    => 'telegram.admin_ids',
        'ok'       => $adminIds !== [],
        'required' => true,
        'value'    => $adminIds === [] ? 'bo\'sh' : count($adminIds) . ' ta',
        'hint'     => 'Telegram ID raqamlaringizni @userinfobot beradi.',
    ],
    [
        'label'    => 'telegram.admin_chat_id',
        'ok'       => $adminChatId !== null && (int) $adminChatId !== 0,
        'required' => false,
        'value'    => $adminChatId !== null && (int) $adminChatId !== 0 ? (string) (int) $adminChatId : 'sozlanmagan',
        'hint'     => 'Yangi arizalar kartochkasi yuboriladigan guruh/kanal.',
    ],
    [
        'label'    => setup_t('panel.set_db_driver'),
        'ok'       => in_array($dbDriver, ['mysql', 'sqlite'], true),
        'required' => true,
        'value'    => $dbDriver,
        'hint'     => $dbDriver === 'mysql'
            ? (string) $app->config('database.username', '') . '@' . (string) $app->config('database.host', '')
                . ' / ' . (string) $app->config('database.database', '')
            : $sqlitePath,
    ],
    [
        'label'    => 'app.base_url',
        'ok'       => $baseUrl !== '',
        'required' => true,
        'value'    => $baseUrl !== '' ? $baseUrl : 'bo\'sh',
        'hint'     => 'Oxirida "/" bo\'lmasin. Webhook manzili shundan yasaladi.',
    ],
    [
        'label'    => 'security.admin_panel',
        'ok'       => !$panelEnabled || $passwordHash !== '',
        'required' => true,
        'value'    => $panelEnabled
            ? ($passwordHash !== '' ? 'parol xeshi kiritilgan' : 'parol xeshi yo\'q')
            : setup_t('common.disabled'),
        'hint'     => 'Xeshni quyidagi "Parol xeshini yaratish" panelidan oling.',
    ],
];

/* --- Database ---------------------------------------------------------- */

$dbOk = false;
$dbError = '';
$dbServer = '';
$dbSize = '';
$migrationsInstalled = false;
$migrationsPending = [];
$migrationsApplied = [];

try {
    $pdo = $app->db()->pdo();
    $dbOk = true;

    $version = $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
    $dbServer = is_scalar($version) ? (string) $version : '';

    if ($dbDriver === 'sqlite' && $sqlitePath !== '' && is_file($sqlitePath)) {
        $dbSize = Text::bytes((int) filesize($sqlitePath));
    }

    $migrator = new Migrator($app->db());
    $migrationsInstalled = $migrator->isInstalled();
    $migrationsPending = $migrator->pending();
    $migrationsApplied = $migrator->applied();
} catch (\Throwable $e) {
    $dbError = $e->getMessage();

    // A MySQL error text can echo the credentials back at us.
    $dbPassword = (string) $app->config('database.password', '');

    if ($dbPassword !== '') {
        $dbError = str_replace($dbPassword, '***', $dbError);
    }
}

/* --- Telegram ---------------------------------------------------------- */

$botOk = false;
$botError = '';
/** @var array<string,mixed> $botInfo */
$botInfo = [];

$webhookOk = false;
$webhookError = '';
/** @var array<string,mixed> $webhookInfo */
$webhookInfo = [];

if ($token === '') {
    $botError = 'telegram.token bo\'sh — config.php ni to\'ldiring.';
    $webhookError = $botError;
} else {
    $meResponse = $app->api()->tryCall('getMe');

    if (is_array($meResponse) && ($meResponse['ok'] ?? false) === true && is_array($meResponse['result'] ?? null)) {
        $botOk = true;
        $botInfo = $meResponse['result'];
    } else {
        $botError = $apiError($meResponse);
    }

    $hookResponse = $app->api()->tryCall('getWebhookInfo');

    if (is_array($hookResponse) && ($hookResponse['ok'] ?? false) === true && is_array($hookResponse['result'] ?? null)) {
        $webhookOk = true;
        $webhookInfo = $hookResponse['result'];
    } else {
        $webhookError = $apiError($hookResponse);
    }
}

$currentWebhookUrl = trim((string) ($webhookInfo['url'] ?? ''));
$webhookLastError = trim((string) ($webhookInfo['last_error_message'] ?? ''));
$webhookLastErrorAt = (int) ($webhookInfo['last_error_date'] ?? 0);

/* --- Overall readiness -------------------------------------------------- */

$blockers = 0;

foreach ([$environmentChecks, $directoryChecks, $configChecks] as $group) {
    foreach ($group as $check) {
        if ($check['required'] && !$check['ok']) {
            $blockers++;
        }
    }
}

if (!$dbOk || !$migrationsInstalled || $migrationsPending !== []) {
    $blockers++;
}

if (!$botOk || $currentWebhookUrl === '') {
    $blockers++;
}

/* =========================================================================
 | 5. Rendering helpers
 ========================================================================= */

if (!function_exists('setup_pill')) {
    /**
     * A coloured status pill.
     */
    function setup_pill(bool $ok, bool $required = true): string
    {
        if ($ok) {
            return '<span class="pill pill-ok">OK</span>';
        }

        return $required
            ? '<span class="pill pill-bad">XATO</span>'
            : '<span class="pill pill-warn">IXTIYORIY</span>';
    }
}

if (!function_exists('setup_check_rows')) {
    /**
     * Render a table body of check rows.
     *
     * @param array<int,array{label:string,ok:bool,required:bool,value:string,hint:string}> $checks
     */
    function setup_check_rows(array $checks): string
    {
        $html = '';

        foreach ($checks as $check) {
            $state = $check['ok'] ? 'row-ok' : ($check['required'] ? 'row-bad' : 'row-warn');

            $html .= '<tr class="' . $state . '">'
                . '<td class="c-label"><span class="lbl">' . setup_e($check['label']) . '</span>'
                . '<span class="hint">' . setup_e($check['hint']) . '</span></td>'
                . '<td class="c-value">' . setup_e($check['value']) . '</td>'
                . '<td class="c-state">' . setup_pill($check['ok'], $check['required']) . '</td>'
                . '</tr>';
        }

        return $html;
    }
}

setup_headers(200);

$appName = trim((string) $app->config('app.name', 'Andijon AI Talents'));
$version = defined('AITALENTS_VERSION') ? (string) AITALENTS_VERSION : '1.0.0';

?><!doctype html>
<html lang="uz">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= setup_e($appName) ?> — o'rnatish</title>
<style>
/* ---------------------------------------------------------------------------
   Inline by design: setup.php must render on a server where nothing else works
   yet, and it is not covered by the admin panel's strict CSP.
   Palette matches admin/assets/app.css (the campaign poster).
   --------------------------------------------------------------------------- */
:root {
    color-scheme: dark;
    --accent:   #22a7ff;
    --accent-2: #7cf3ff;
    --bg:       #050b1a;
    --bg-2:     #0a1b3d;
    --surface:  #0a1730;
    --surface-2:#0e1f42;
    --border:   rgba(124, 243, 255, .14);
    --border-2: rgba(124, 243, 255, .28);
    --text:     #eef5ff;
    --text-2:   #a8bedd;
    --text-3:   #7089ae;
    --ok:       #2ee6a8;
    --ok-soft:  rgba(46, 230, 168, .13);
    --warn:     #ffbe4d;
    --warn-soft:rgba(255, 190, 77, .13);
    --bad:      #ff5d73;
    --bad-soft: rgba(255, 93, 115, .13);
    --info-soft:rgba(82, 185, 255, .13);
    --radius:   16px;
    --mono:     ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    padding: 0 0 64px;
    background: radial-gradient(1200px 720px at 50% -12%, var(--bg-2) 0%, var(--bg) 62%) no-repeat, var(--bg);
    color: var(--text);
    font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    -webkit-text-size-adjust: 100%;
}

.wrap { max-width: 1120px; margin: 0 auto; padding: 0 20px; }

/* --- Header ------------------------------------------------------------- */
.top { padding: 34px 0 12px; }
.brand { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.logo {
    width: 46px; height: 46px; flex: 0 0 46px; border-radius: 14px;
    background: linear-gradient(150deg, var(--accent) 0%, var(--accent-2) 100%);
    color: #04121f; font-weight: 800; font-size: 17px; letter-spacing: -.5px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 10px 26px rgba(34, 167, 255, .28);
}
.brand h1 { margin: 0; font-size: 21px; line-height: 1.25; font-weight: 700; }
.brand p  { margin: 2px 0 0; color: var(--text-3); font-size: 13px; }

/* --- Banners ------------------------------------------------------------ */
.banner {
    margin: 20px 0 0; padding: 16px 18px; border-radius: var(--radius);
    border: 1px solid var(--border); background: var(--surface);
    display: flex; gap: 12px; align-items: flex-start;
}
.banner .ico { font-size: 18px; line-height: 1.3; }
.banner strong { display: block; margin-bottom: 2px; }
.banner p { margin: 0; color: var(--text-2); font-size: 14px; }
.banner code { word-break: break-all; }
.banner-danger { border-color: rgba(255, 93, 115, .45); background: var(--bad-soft); }
.banner-ok     { border-color: rgba(46, 230, 168, .40); background: var(--ok-soft); }
.banner-warn   { border-color: rgba(255, 190, 77, .40); background: var(--warn-soft); }
.banner-info   { border-color: var(--border-2); background: var(--info-soft); }
.banner-last   { margin-top: 22px; }

/* --- Cards -------------------------------------------------------------- */
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 18px; margin-top: 22px; }
.card {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    padding: 20px 20px 18px; box-shadow: 0 18px 46px rgba(3, 8, 20, .38);
    min-width: 0;
}
.card.wide { grid-column: 1 / -1; }
.card h2 {
    margin: 0 0 4px; font-size: 15px; letter-spacing: .02em; text-transform: uppercase;
    color: var(--accent-2); display: flex; align-items: center; gap: 9px; flex-wrap: wrap;
}
.card .lead { margin: 0 0 14px; color: var(--text-3); font-size: 13px; }

/* --- Check tables ------------------------------------------------------- */
.table-scroll { overflow-x: auto; margin: 0 -4px; }
table { width: 100%; border-collapse: collapse; font-size: 14px; }
th, td { text-align: left; padding: 9px 6px; border-bottom: 1px solid rgba(148, 176, 214, .12); vertical-align: top; }
tr:last-child td { border-bottom: 0; }
td.c-label { min-width: 190px; }
td.c-label .lbl { display: block; font-weight: 600; }
td.c-label .hint { display: block; color: var(--text-3); font-size: 12px; margin-top: 2px; }
td.c-value { color: var(--text-2); font-family: var(--mono); font-size: 12.5px; word-break: break-word; }
td.c-state { text-align: right; white-space: nowrap; }
tr.row-bad td.c-label .lbl { color: var(--bad); }

/* --- Definition list ---------------------------------------------------- */
.dl { margin: 0; display: grid; grid-template-columns: minmax(120px, 38%) 1fr; gap: 8px 14px; font-size: 14px; }
.dl dt { color: var(--text-3); }
.dl dd { margin: 0; word-break: break-word; font-family: var(--mono); font-size: 12.5px; color: var(--text-2); }

/* --- Pills -------------------------------------------------------------- */
.pill {
    display: inline-block; padding: 2px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
}
.pill-ok   { background: var(--ok-soft);   color: var(--ok);   border: 1px solid rgba(46, 230, 168, .35); }
.pill-warn { background: var(--warn-soft); color: var(--warn); border: 1px solid rgba(255, 190, 77, .35); }
.pill-bad  { background: var(--bad-soft);  color: var(--bad);  border: 1px solid rgba(255, 93, 115, .35); }

/* --- Forms -------------------------------------------------------------- */
form { margin: 0; }
.field { margin: 12px 0; }
label { display: block; font-size: 13px; color: var(--text-2); margin-bottom: 5px; }
input[type="text"], input[type="url"], input[type="password"] {
    width: 100%; padding: 10px 12px; border-radius: 10px; font: inherit; font-size: 14px;
    background: var(--surface-2); border: 1px solid var(--border); color: var(--text);
}
input:focus-visible, button:focus-visible, a:focus-visible {
    outline: 2px solid var(--accent-2); outline-offset: 2px;
}
.check { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-2); margin: 10px 0; }
.check input { width: 16px; height: 16px; accent-color: var(--accent); }
.actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }

button, .btn {
    display: inline-flex; align-items: center; gap: 7px; padding: 10px 16px; border-radius: 10px;
    font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none;
    border: 1px solid var(--border-2); background: var(--surface-2); color: var(--text);
}
button:hover, .btn:hover { border-color: var(--accent); color: #fff; }
.btn-primary {
    background: linear-gradient(140deg, var(--accent) 0%, var(--accent-2) 130%);
    border-color: transparent; color: #04121f;
}
.btn-primary:hover { color: #04121f; filter: brightness(1.06); }
.btn-danger { border-color: rgba(255, 93, 115, .45); color: #ffd7dd; background: var(--bad-soft); }
.btn-danger:hover { border-color: var(--bad); color: #fff; }
button[disabled] { opacity: .45; cursor: not-allowed; }

/* --- Code --------------------------------------------------------------- */
code, pre, .hashbox {
    font-family: var(--mono); background: rgba(124, 243, 255, .07);
    border-radius: 9px; font-size: 12.5px;
}
code { padding: 2px 6px; word-break: break-all; }
pre {
    padding: 13px 14px; overflow-x: auto; border: 1px solid var(--border);
    margin: 12px 0 0; line-height: 1.5; color: var(--text-2);
}
.hashbox {
    display: block; width: 100%; padding: 12px 14px; border: 1px solid var(--border-2);
    color: var(--accent-2); word-break: break-all; margin-top: 12px;
}

/* --- Lists -------------------------------------------------------------- */
ol.steps { margin: 6px 0 0; padding-left: 20px; color: var(--text-2); font-size: 14px; }
ol.steps li { margin: 6px 0; }
.muted { color: var(--text-3); font-size: 13px; }
.foot { margin-top: 28px; text-align: center; color: var(--text-3); font-size: 12.5px; line-height: 1.8; }

/* --- Responsive --------------------------------------------------------- */
@media (max-width: 720px) {
    .wrap { padding: 0 14px; }
    .grid { grid-template-columns: 1fr; gap: 14px; }
    .card { padding: 16px 15px 14px; }
    .dl { grid-template-columns: 1fr; gap: 2px 0; }
    .dl dd { margin-bottom: 8px; }
    td.c-label { min-width: 140px; }
    .actions button, .actions .btn { width: 100%; justify-content: center; }
    .brand h1 { font-size: 18px; }
}

@media (prefers-reduced-motion: reduce) {
    * { transition: none !important; animation: none !important; }
}
</style>
</head>
<body>
<div class="wrap">

    <header class="top">
        <div class="brand">
            <div class="logo">AI</div>
            <div>
                <h1><?= setup_e($appName) ?> — o'rnatish sehrgari</h1>
                <p><?= setup_e(setup_t('panel.version', ['version' => $version])) ?> ·
                   PHP <?= setup_e(PHP_VERSION) ?> · <?= setup_e(date('Y-m-d H:i')) ?></p>
            </div>
        </div>
    </header>

    <!-- The most important sentence on the page. -->
    <div class="banner banner-danger">
        <span class="ico">⚠️</span>
        <div>
            <strong>O'rnatish tugagach <code>setup.php</code> faylini serverdan o'chiring!</strong>
            <p>Bu sahifa maxfiy kalit bilan himoyalangan bo'lsa ham, ishlab turgan saytda ortiqcha
               kirish nuqtasi qoldirmaslik kerak. Keyinchalik kerak bo'lsa, faylni qayta yuklaysiz.</p>
        </div>
    </div>

<?php if ($blockers === 0) { ?>
    <div class="banner banner-ok">
        <span class="ico">✅</span>
        <div>
            <strong>Hammasi tayyor.</strong>
            <p>Muhit, ma'lumotlar bazasi va webhook sozlangan. Botga Telegram'da <code>/start</code>
               yozib tekshiring, so'ng bu faylni o'chiring.</p>
        </div>
    </div>
<?php } else { ?>
    <div class="banner banner-warn">
        <span class="ico">🛠</span>
        <div>
            <strong><?= (int) $blockers ?> ta bo'lim e'tibor talab qiladi.</strong>
            <p>Quyidagi qizil belgilangan bandlarni tuzating va sahifani yangilang.</p>
        </div>
    </div>
<?php } ?>

<?php foreach ($flashes as $flashMessage) {
    $flashClass = match ($flashMessage['type']) {
        'ok'    => 'banner-ok',
        'error' => 'banner-danger',
        'warn'  => 'banner-warn',
        default => 'banner-info',
    };
    $flashIcon = match ($flashMessage['type']) {
        'ok'    => '✅',
        'error' => '⛔',
        'warn'  => '⚠️',
        default => 'ℹ️',
    };
    ?>
    <div class="banner <?= $flashClass ?>">
        <span class="ico"><?= $flashIcon ?></span>
        <div><p><?= setup_e($flashMessage['text']) ?></p></div>
    </div>
<?php } ?>

    <div class="grid">

        <!-- 1. PHP va kengaytmalar ------------------------------------- -->
        <section class="card">
            <h2>1 · PHP va kengaytmalar</h2>
            <p class="lead">Bot PHP 8.1+ va bir nechta standart kengaytmani talab qiladi.</p>
            <div class="table-scroll">
                <table>
                    <tbody><?= setup_check_rows($environmentChecks) ?></tbody>
                </table>
            </div>
        </section>

        <!-- 2. Papka ruxsatlari ---------------------------------------- -->
        <section class="card">
            <h2>2 · Papka ruxsatlari</h2>
            <p class="lead">Bot <code>data/</code> ichiga log, eksport va (SQLite bo'lsa) bazani yozadi.
               <strong>777</strong> ruxsat qo'ymang.</p>
            <div class="table-scroll">
                <table>
                    <tbody><?= setup_check_rows($directoryChecks) ?></tbody>
                </table>
            </div>
        </section>

        <!-- 3. Konfiguratsiya ------------------------------------------ -->
        <section class="card wide">
            <h2>3 · Konfiguratsiya (<code>config.php</code>)</h2>
            <p class="lead">Maxfiy qiymatlar bu yerda hech qachon ko'rsatilmaydi — faqat
               «kiritilgan / bo'sh» holati.</p>
            <div class="table-scroll">
                <table>
                    <tbody><?= setup_check_rows($configChecks) ?></tbody>
                </table>
            </div>
        </section>

        <!-- 4. Ma'lumotlar bazasi -------------------------------------- -->
        <section class="card">
            <h2>4 · <?= setup_e(setup_t('panel.set_db')) ?> <?= setup_pill($dbOk && $migrationsInstalled && $migrationsPending === []) ?></h2>
            <p class="lead">Ulanishni tekshiring va jadvallarni yarating. Migratsiyani qayta ishga
               tushirish xavfsiz — bajarilganlari takrorlanmaydi.</p>

            <dl class="dl">
                <dt><?= setup_e(setup_t('panel.set_db_driver')) ?></dt>
                <dd><?= setup_e($dbDriver) ?><?= $dbServer !== '' ? ' · ' . setup_e($dbServer) : '' ?></dd>

                <dt>Ulanish</dt>
                <dd><?= $dbOk ? 'OK' : setup_e(setup_t('common.error')) ?></dd>

<?php if ($dbSize !== '') { ?>
                <dt><?= setup_e(setup_t('panel.set_db_size')) ?></dt>
                <dd><?= setup_e($dbSize) ?></dd>
<?php } ?>

                <dt>Migratsiyalar</dt>
                <dd><?= count($migrationsApplied) ?> bajarilgan ·
                    <?= count($migrationsPending) ?> kutilmoqda</dd>
            </dl>

<?php if (!$dbOk) { ?>
            <pre><?= setup_e($dbError) ?></pre>
            <p class="muted">config.php dagi <code>database</code> bo'limini tekshiring: nom, foydalanuvchi
               va parol cPanel prefiksi bilan to'liq yozilganmi?</p>
<?php } elseif ($migrationsPending !== []) { ?>
            <pre><?= setup_e(implode("\n", $migrationsPending)) ?></pre>
<?php } ?>

            <form method="post" action="<?= setup_e($selfUrl) ?>">
                <input type="hidden" name="_token" value="<?= setup_e($csrfToken) ?>">
                <input type="hidden" name="action" value="migrate">
                <div class="actions">
                    <button type="submit" class="btn-primary"<?= $dbOk ? '' : ' disabled' ?>>
                        Migratsiyalarni ishga tushirish
                    </button>
                </div>
            </form>
        </section>

        <!-- 5. Webhook -------------------------------------------------- -->
        <section class="card">
            <h2>5 · <?= setup_e(setup_t('panel.set_webhook')) ?> <?= setup_pill($webhookOk && $currentWebhookUrl !== '') ?></h2>
            <p class="lead">Telegram yangilanishlarni <code>index.php</code> ga yuboradi. Manzil
               <code>https://</code> bilan boshlanishi shart.</p>

<?php if ($webhookOk) { ?>
            <dl class="dl">
                <dt>Joriy manzil</dt>
                <dd><?= $currentWebhookUrl === '' ? setup_e(setup_t('panel.not_available')) : setup_e($currentWebhookUrl) ?></dd>

                <dt>Navbatdagi</dt>
                <dd><?= (int) ($webhookInfo['pending_update_count'] ?? 0) ?></dd>

                <dt>IP</dt>
                <dd><?= setup_e(trim((string) ($webhookInfo['ip_address'] ?? '')) ?: '—') ?></dd>

<?php if ($webhookLastError !== '') { ?>
                <dt>Oxirgi xato</dt>
                <dd><?= setup_e($webhookLastError) ?>
                    <?= $webhookLastErrorAt > 0 ? '(' . setup_e(date('Y-m-d H:i', $webhookLastErrorAt)) . ')' : '' ?></dd>
<?php } ?>
            </dl>
<?php } else { ?>
            <p class="muted"><?= setup_e(setup_t('panel.set_webhook_failed', ['error' => $webhookError])) ?></p>
<?php } ?>

            <form method="post" action="<?= setup_e($selfUrl) ?>">
                <input type="hidden" name="_token" value="<?= setup_e($csrfToken) ?>">
                <input type="hidden" name="action" value="webhook_set">
                <div class="field">
                    <label for="webhook-url">Webhook manzili</label>
                    <input type="url" id="webhook-url" name="url" inputmode="url" spellcheck="false"
                           placeholder="https://domen.uz/bot/index.php"
                           value="<?= setup_e($suggestedWebhookUrl) ?>">
                </div>
                <label class="check">
                    <input type="checkbox" name="drop_pending" value="1">
                    Kutib turgan eski yangilanishlarni tashlab yuborish
                </label>
                <div class="actions">
                    <button type="submit" class="btn-primary"<?= $token === '' ? ' disabled' : '' ?>>
                        Webhook'ni o'rnatish
                    </button>
                </div>
            </form>

            <form method="post" action="<?= setup_e($selfUrl) ?>">
                <input type="hidden" name="_token" value="<?= setup_e($csrfToken) ?>">
                <input type="hidden" name="action" value="webhook_delete">
                <div class="actions">
                    <button type="submit" class="btn-danger"<?= $token === '' ? ' disabled' : '' ?>>
                        Webhook'ni o'chirish
                    </button>
                </div>
            </form>
        </section>

        <!-- 6. Bot bilan aloqa ----------------------------------------- -->
        <section class="card">
            <h2>6 · <?= setup_e(setup_t('panel.set_getme')) ?> <?= setup_pill($botOk) ?></h2>
            <p class="lead">Token to'g'ri ekanini <code>getMe</code> orqali tekshiramiz va menyudagi
               buyruqlar ro'yxatini o'rnatamiz.</p>

<?php if ($botOk) { ?>
            <dl class="dl">
                <dt>Username</dt>
                <dd>@<?= setup_e((string) ($botInfo['username'] ?? '')) ?></dd>

                <dt>Nomi</dt>
                <dd><?= setup_e((string) ($botInfo['first_name'] ?? '')) ?></dd>

                <dt>Bot ID</dt>
                <dd><?= (int) ($botInfo['id'] ?? 0) ?></dd>

                <dt>Guruhlarga qo'shilish</dt>
                <dd><?= ($botInfo['can_join_groups'] ?? false) ? setup_t('common.yes') : setup_t('common.no') ?></dd>
            </dl>
            <p class="muted"><?= setup_e(setup_t('panel.set_getme_ok', ['username' => (string) ($botInfo['username'] ?? '')])) ?></p>
<?php } else { ?>
            <p class="muted"><?= setup_e(setup_t('panel.set_getme_failed', ['error' => $botError])) ?></p>
<?php } ?>

            <form method="post" action="<?= setup_e($selfUrl) ?>">
                <input type="hidden" name="_token" value="<?= setup_e($csrfToken) ?>">
                <input type="hidden" name="action" value="set_commands">
                <div class="actions">
                    <button type="submit"<?= $botOk ? '' : ' disabled' ?>>
                        Buyruqlar ro'yxatini o'rnatish
                    </button>
                </div>
            </form>

            <pre><?php foreach ($botCommands('uz') as $command) {
                echo '/' . setup_e($command['command']) . ' — ' . setup_e($command['description']) . "\n";
            } ?></pre>
        </section>

        <!-- 7. Parol xeshini yaratish ---------------------------------- -->
        <section class="card">
            <h2>7 · Parol xeshini yaratish</h2>
            <p class="lead">Admin panel paroli bazada emas, <code>config.php</code> ichida xesh
               ko'rinishida saqlanadi. Parolni bu yerda yozing va natijani ko'chiring — parolning
               o'zi hech qayerga saqlanmaydi.</p>

            <form method="post" action="<?= setup_e($selfUrl) ?>" autocomplete="off">
                <input type="hidden" name="_token" value="<?= setup_e($csrfToken) ?>">
                <input type="hidden" name="action" value="make_hash">
                <div class="field">
                    <label for="panel-password"><?= setup_e(setup_t('panel.login_password')) ?></label>
                    <input type="password" id="panel-password" name="password"
                           autocomplete="new-password" spellcheck="false" placeholder="kamida 8 belgi">
                </div>
                <div class="actions">
                    <button type="submit" class="btn-primary">Xesh yaratish</button>
                </div>
            </form>

<?php if ($generatedHash !== '') { ?>
            <code class="hashbox"><?= setup_e($generatedHash) ?></code>
            <pre>'admin_panel' =&gt; [
    'username'      =&gt; '<?= setup_e((string) $app->config('security.admin_panel.username', 'admin')) ?>',
    'password_hash' =&gt; '<?= setup_e($generatedHash) ?>',
],</pre>
<?php } ?>
            <p class="muted">Terminal mavjud bo'lsa xuddi shu ishni
               <code>php cli.php admin:hash '...'</code> buyrug'i bajaradi.</p>
        </section>

        <!-- 8. Keyingi qadamlar ---------------------------------------- -->
        <section class="card wide">
            <h2>8 · Keyingi qadamlar</h2>
            <ol class="steps">
                <li><code>config.php</code> to'ldirilgan: token, admin ID, baza va
                    <code>security.setup_key</code>.</li>
                <li>Migratsiya bajarilgan (4-bo'lim yashil).</li>
                <li>Webhook o'rnatilgan (5-bo'lim yashil), <code>last_error_message</code> bo'sh.</li>
                <li>Telegram'da botga <code>/start</code> yozib, ro'yxatdan o'tishni sinab ko'ring.</li>
                <li>Admin panelga kiring:
<?php if ($baseUrl !== '') { ?>
                    <code><?= setup_e($baseUrl . '/admin/') ?></code>
<?php } else { ?>
                    <code>/admin/</code> (avval <code>app.base_url</code> ni to'ldiring)
<?php } ?>
                </li>
                <li>Cron qo'shing (tarqatmalar navbatini bo'shatadi, daqiqada bir marta):
                    <pre>cd <?= setup_e(AITALENTS_ROOT) ?> &amp;&amp; php cli.php broadcast:run &gt;/dev/null 2&gt;&amp;1</pre>
                </li>
                <li><strong>Ushbu <code>setup.php</code> faylini o'chiring.</strong></li>
            </ol>
        </section>

    </div>

    <div class="banner banner-danger banner-last">
        <span class="ico">🗑</span>
        <div>
            <strong>Oxirgi eslatma: <code>setup.php</code> ni o'chiring.</strong>
            <p>cPanel File Manager → <code>setup.php</code> → Delete, yoki
               <code>rm setup.php</code>. O'chira olmasangiz, hech bo'lmaganda
               <code>security.setup_key</code> ni yangi tasodifiy satrga almashtiring.</p>
        </div>
    </div>

    <p class="foot">
        <?= setup_e(setup_t('panel.footer')) ?><br>
        <?= setup_e(setup_t('panel.version', ['version' => $version])) ?>
    </p>

</div>
</body>
</html>
