<?php

declare(strict_types=1);

/**
 * Andijon AI Talents — dependency free test harness.
 *
 *     php tests/run.php            run everything
 *     php tests/run.php --no-color plain output (CI logs, files)
 *     php tests/run.php --quiet    only failures and the summary
 *
 * Design notes
 * ------------
 *  - No PHPUnit, no Composer: the whole harness is this single file.
 *  - The suite builds its own configuration array in memory and boots the app
 *    with it, so a developer does NOT need a config.php to run the tests.
 *  - The database is a throw-away SQLite file under tests/tmp/, deleted and
 *    recreated on every run.
 *  - The Telegram client is wired to {@see FakeTransport}; the API base URL
 *    points at the local discard port. Two guards make an accidental network
 *    call impossible to miss: the transport identity is verified after every
 *    suite, and the run fails when CurlTransport was ever loaded at all.
 *  - A suite that explodes is reported as a failed assertion; the run carries
 *    on with the next suite.
 *
 * Exit code: 0 when everything passed, 1 when at least one assertion failed.
 */

use AiTalents\Admin\Auth;
use AiTalents\App;
use AiTalents\Config;
use AiTalents\Database;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Enum\Step;
use AiTalents\Export\XlsxExporter;
use AiTalents\Export\XlsxWriter;
use AiTalents\Lang;
use AiTalents\Logger;
use AiTalents\Migrator;
use AiTalents\RateLimiter;
use AiTalents\Registration\Catalog;
use AiTalents\Registration\Flow;
use AiTalents\Router;
use AiTalents\Service\BroadcastService;
use AiTalents\Telegram\Api;
use AiTalents\Telegram\ApiException;
use AiTalents\Telegram\FakeTransport;
use AiTalents\Telegram\Keyboard;
use AiTalents\Telegram\Update;
use AiTalents\Text;
use AiTalents\Validator;

/* =========================================================================
 | 1. The runner
 ========================================================================= */

/**
 * Collects assertions, prints them grouped by suite and owns the exit code.
 */
final class TestRunner
{
    /** ANSI colours, keyed by role. */
    private const COLOURS = [
        'reset'  => "\033[0m",
        'bold'   => "\033[1m",
        'dim'    => "\033[2m",
        'green'  => "\033[32m",
        'red'    => "\033[31m",
        'yellow' => "\033[33m",
        'cyan'   => "\033[36m",
    ];

    private static ?TestRunner $instance = null;

    private bool $colour = false;
    private bool $quiet = false;
    private string $suite = '';
    private int $suiteCount = 0;
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;
    private float $startedAt = 0.0;

    /** @var string[] "suite :: label" of every failed assertion. */
    private array $failures = [];

    /** @var string[] PHP warnings/notices/deprecations raised during the run. */
    private array $diagnostics = [];

    private function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function configure(bool $colour, bool $quiet): void
    {
        $this->colour = $colour;
        $this->quiet = $quiet;
    }

    /* ------------------------------------------------------------------ */

    /** Open a new group of assertions. */
    public function suite(string $name): void
    {
        $this->suite = $name;
        $this->suiteCount++;

        $this->write("\n" . $this->paint('  ▸ ' . $name, 'bold', 'cyan') . "\n");
    }

    /**
     * Record one assertion.
     *
     * @return bool the asserted condition, so callers may branch on it
     */
    public function check(string $label, bool $condition, string $detail = ''): bool
    {
        if ($condition) {
            $this->passed++;

            if (!$this->quiet) {
                $this->write('    ' . $this->paint('PASS', 'green') . '  ' . $label . "\n");
            }

            return true;
        }

        $this->failed++;
        $this->failures[] = ($this->suite === '' ? '(no suite)' : $this->suite) . ' :: ' . $label;

        $this->write('    ' . $this->paint('FAIL', 'bold', 'red') . '  ' . $label . "\n");

        if ($detail !== '') {
            foreach (explode("\n", $detail) as $line) {
                $this->write('          ' . $this->paint($line, 'dim') . "\n");
            }
        }

        return false;
    }

    /** Record an assertion that could not run (a missing PHP extension, ...). */
    public function skip(string $label, string $why): void
    {
        $this->skipped++;

        $this->write(
            '    ' . $this->paint('SKIP', 'yellow') . '  ' . $label
            . ' ' . $this->paint('(' . $why . ')', 'dim') . "\n"
        );
    }

    /** An informational line inside the current suite. */
    public function note(string $line): void
    {
        if (!$this->quiet) {
            $this->write('    ' . $this->paint('····  ' . $line, 'dim') . "\n");
        }
    }

    /** A suite threw: report it as a failure and keep going. */
    public function crashed(\Throwable $e): void
    {
        $this->check(
            'the suite ran to the end',
            false,
            get_class($e) . ': ' . $e->getMessage() . "\n"
            . 'at ' . $this->relative($e->getFile()) . ':' . $e->getLine()
        );
    }

    /* ------------------------------------------------------------------ */

    /** Remember a PHP diagnostic (warning/notice/deprecation) for the report. */
    public function diagnostic(string $line): void
    {
        if (count($this->diagnostics) < 200 && !in_array($line, $this->diagnostics, true)) {
            $this->diagnostics[] = $line;
        }
    }

    /**
     * Run $fn with a private diagnostics list and hand back what it collected.
     *
     * This is how the harness proves — permanently, not just once by hand —
     * that a PHP warning raised anywhere inside a test really is caught and
     * really would fail the run: the probe raises one on purpose, checks that
     * the collector saw it, and restores the real list so the deliberate
     * warning never reaches the verdict.
     *
     * @return string[] the diagnostics raised while $fn ran
     */
    public function probe(callable $fn): array
    {
        $saved = $this->diagnostics;
        $this->diagnostics = [];

        try {
            $fn();
        } finally {
            $collected = $this->diagnostics;
            $this->diagnostics = $saved;
        }

        return $collected;
    }

    /** @return string[] */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }

    public function failedCount(): int
    {
        return $this->failed;
    }

    /** Print the closing report and return the process exit code. */
    public function summary(): int
    {
        $seconds = number_format(microtime(true) - $this->startedAt, 2);

        $this->write("\n" . $this->paint(str_repeat('─', 68), 'dim') . "\n");

        if ($this->failures !== []) {
            $this->write('  ' . $this->paint('Failed assertions:', 'bold', 'red') . "\n");

            foreach ($this->failures as $failure) {
                $this->write('    ' . $this->paint('✗', 'red') . ' ' . $failure . "\n");
            }

            $this->write("\n");
        }

        $verdict = $this->failed === 0
            ? $this->paint('  ALL GREEN  ', 'bold', 'green')
            : $this->paint('  FAILURES  ', 'bold', 'red');

        $this->write(
            $verdict . '  '
            . $this->suiteCount . ' suites · '
            . $this->passed . ' passed · '
            . $this->failed . ' failed · '
            . $this->skipped . ' skipped'
            . $this->paint('   (' . $seconds . 's, PHP ' . PHP_VERSION . ')', 'dim')
            . "\n\n"
        );

        return $this->failed === 0 ? 0 : 1;
    }

    /* ------------------------------------------------------------------ */

    /** Colourise a fragment when the output stream supports it. */
    private function paint(string $text, string ...$roles): string
    {
        if (!$this->colour) {
            return $text;
        }

        $prefix = '';

        foreach ($roles as $role) {
            $prefix .= self::COLOURS[$role] ?? '';
        }

        return $prefix === '' ? $text : $prefix . $text . self::COLOURS['reset'];
    }

    private function write(string $text): void
    {
        fwrite(STDOUT, $text);
    }

    /** Shorten an absolute path for the report. */
    private function relative(string $path): string
    {
        $root = defined('AITALENTS_ROOT') ? (string) AITALENTS_ROOT : dirname(__DIR__);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}

/* =========================================================================
 | 2. Assertion helpers (the tiny public API the suites use)
 ========================================================================= */

/** Start a new group of assertions. */
function suite(string $name): void
{
    TestRunner::instance()->suite($name);
}

/** Assert that a condition holds. */
function ok(string $label, bool $cond): bool
{
    return TestRunner::instance()->check($label, $cond);
}

/** Assert strict equality; arrays are compared by value (order included). */
function eq(mixed $expected, mixed $actual, string $label): bool
{
    $same = $expected === $actual;

    return TestRunner::instance()->check(
        $label,
        $same,
        $same ? '' : 'expected: ' . describe($expected) . "\nactual:   " . describe($actual)
    );
}

/** Assert that $fn throws an instance of $class. */
function throws(callable $fn, string $class, string $label): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return TestRunner::instance()->check(
            $label,
            $e instanceof $class,
            $e instanceof $class
                ? ''
                : 'expected: ' . $class . "\nactual:   " . get_class($e) . ': ' . $e->getMessage()
        );
    }

    return TestRunner::instance()->check($label, false, 'expected ' . $class . ', but nothing was thrown');
}

/** Record an assertion that cannot run in this environment. */
function skip(string $label, string $why): void
{
    TestRunner::instance()->skip($label, $why);
}

/** An informational line inside the current suite. */
function note(string $line): void
{
    TestRunner::instance()->note($line);
}

/** Render any value as a short, single-line description for a failure report. */
function describe(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (is_string($value)) {
        $short = mb_strlen($value, 'UTF-8') > 160 ? mb_substr($value, 0, 157, 'UTF-8') . '…' : $value;

        return '"' . str_replace(["\n", "\r", "\t"], ['\n', '\r', '\t'], $short) . '"';
    }

    if (is_array($value)) {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $json === false ? 'array(' . count($value) . ')' : $json;

        return strlen($json) > 200 ? substr($json, 0, 197) . '…' : $json;
    }

    if (is_object($value)) {
        return 'object(' . get_class($value) . ')';
    }

    return gettype($value);
}

/* =========================================================================
 | 3. Filesystem helpers
 ========================================================================= */

/**
 * Delete the contents of a directory, keeping the directory itself.
 *
 * Refuses to touch anything outside $guard — a test harness must never be able
 * to wipe the project by accident.
 */
function purge_directory(string $directory, string $guard): void
{
    $real = is_dir($directory) ? realpath($directory) : false;
    $guardReal = realpath($guard);

    if ($real === false || $guardReal === false) {
        return;
    }

    if ($real !== $guardReal && !str_starts_with($real, $guardReal . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Refusing to purge "' . $real . '": it is outside "' . $guardReal . '".');
    }

    /** @var iterable<\SplFileInfo> $items */
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        // .gitkeep keeps the empty directory in git — never remove it.
        if ($item->getFilename() === '.gitkeep') {
            continue;
        }

        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
}

/**
 * Every PHP file of the project that the compatibility / language scanners look at.
 *
 * @param string[] $directories relative to the project root
 *
 * @return string[] absolute paths
 */
function project_php_files(string $root, array $directories, bool $includeRootFiles = true): array
{
    $files = [];

    foreach ($directories as $directory) {
        $path = $root . '/' . $directory;

        if (!is_dir($path)) {
            continue;
        }

        /** @var iterable<\SplFileInfo> $items */
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($items as $item) {
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $files[] = $item->getPathname();
            }
        }
    }

    if ($includeRootFiles) {
        foreach ((array) glob($root . '/*.php') as $file) {
            if (is_string($file) && is_file($file)) {
                $files[] = $file;
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Strip comments and string literals from PHP source so a scanner only sees code.
 *
 * Without this a docblock that *documents* a forbidden construct — or a string
 * that merely mentions one — would be reported as a violation.
 */
function php_code_only(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $out .= $token;

            continue;
        }

        switch ($token[0]) {
            case T_COMMENT:
            case T_DOC_COMMENT:
            case T_INLINE_HTML:
                $out .= ' ';
                break;

            case T_CONSTANT_ENCAPSED_STRING:
            case T_ENCAPSED_AND_WHITESPACE:
                $out .= "''";
                break;

            default:
                $out .= $token[1];
        }
    }

    return $out;
}

/**
 * Strip comments from PHP source but KEEP the string literals.
 *
 * This is what the language-key scanner needs: the keys it looks for live
 * inside those literals, while a `Lang::t('...')` example in a docblock must
 * not be mistaken for a real call.
 */
function php_without_comments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            $out .= $token;

            continue;
        }

        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            $out .= ' ';

            continue;
        }

        $out .= $token[1];
    }

    return $out;
}

/* =========================================================================
 | 4. The suites
 ========================================================================= */

/**
 * Every test suite of the project, as one method each.
 *
 * The suites share one booted {@see App} and one {@see FakeTransport}; state
 * that travels between suites (the id of the simulated registration, the calls
 * recorded during the flow) lives in explicit properties.
 */
final class TestSuites
{
    /** Telegram id of the simulated applicant. */
    public const APPLICANT_ID = 5551001;

    /** Telegram id of the configured administrator. */
    public const ADMIN_ID = 9990001;

    /** Telegram ids used by the repository suites (kept apart from the flow). */
    private const REPO_USER_ID = 7770001;
    private const REPO_USER_ID_2 = 7770002;
    private const REPO_USER_ID_3 = 7770003;

    /** Credentials of the admin panel account the harness configures. */
    public const PANEL_USER = 'admin';
    public const PANEL_PASSWORD = 'harness-password';

    /** Telegram ids owned by the admin-panel suites (kept apart from everything else). */
    private const PANEL_BASE_ID = 8880000;

    /** Every page of the panel, in sidebar order, plus the detail screen. */
    private const PANEL_PAGES = [
        'dashboard',
        'registrations',
        'registration',
        'users',
        'broadcast',
        'broadcasts',
        'settings',
        'logs',
        'audit',
        'export',
    ];


    private App $app;
    private FakeTransport $fake;
    private string $root;
    private string $tmpDir;

    /** Increasing ids for the synthetic updates. */
    private int $updateId = 100000;
    private int $messageId = 500;

    /** The registration created by the simulated flow. */
    private int $flowRegistrationId = 0;

    /** True once the panel session exists and the fixtures are in the database. */
    private bool $panelReady = false;

    /** The `$_SESSION` of a signed-in administrator, captured after a real login. */
    private array $panelSession = [];

    /** Ids the panel fixtures created, so the suites can address them by name. */
    private array $panelIds = [];

    /** How many panel requests the suites drove (in process + child processes). */
    private int $panelRequests = 0;

    /** Absolute path of the generated child-process worker (see panelWorker()). */
    private string $panelWorkerFile = '';

    /** Increasing counter naming the case/result files of the child processes. */
    private int $panelCaseNumber = 0;

    /**
     * Every transport call recorded while the registration flow ran.
     *
     * @var array<int,array{method:string,params:array<string,mixed>,url:string}>
     */
    private array $flowCalls = [];

    public function __construct(App $app, FakeTransport $fake, string $root, string $tmpDir)
    {
        $this->app = $app;
        $this->fake = $fake;
        $this->root = $root;
        $this->tmpDir = $tmpDir;
    }

    /**
     * Suite name => callable, in execution order.
     *
     * @return array<string,callable():void>
     */
    public function all(): array
    {
        return [
            'Bootstrap & configuration'      => $this->configSuite(...),
            'Migrations'                     => $this->migrationSuite(...),
            'Network guard (FakeTransport)'  => $this->transportSuite(...),
            'Text'                           => $this->textSuite(...),
            'Validator'                      => $this->validatorSuite(...),
            'Lang'                           => $this->langSuite(...),
            'Lang key coverage (src + admin)' => $this->langCoverageSuite(...),
            'Catalog'                        => $this->catalogSuite(...),
            'Keyboard'                       => $this->keyboardSuite(...),
            'Enums (Step, RegistrationStatus)' => $this->enumSuite(...),
            'Logger'                         => $this->loggerSuite(...),
            'Telegram Api'                   => $this->apiSuite(...),
            'UserRepository (state machine)' => $this->userRepositorySuite(...),
            'RegistrationRepository'         => $this->registrationRepositorySuite(...),
            'Settings & audit log'           => $this->settingsSuite(...),
            'StatsService'                   => $this->statsSuite(...),
            'RateLimiter'                    => $this->rateLimiterSuite(...),
            'XlsxWriter'                     => $this->xlsxWriterSuite(...),
            'XlsxExporter'                   => $this->xlsxExporterSuite(...),
            'Registration flow (end to end)' => $this->flowSuite(...),
            'Callback data budget'           => $this->callbackBudgetSuite(...),
            'Router guards'                  => $this->routerSuite(...),
            'BroadcastService'               => $this->broadcastSuite(...),
            'Admin panel (pages)'            => $this->panelPageSuite(...),
            'Admin panel (hostile input)'    => $this->panelHostileSuite(...),
            'Admin panel (pathological data)' => $this->panelPathologicalSuite(...),
            'Admin panel (controller actions)' => $this->panelActionSuite(...),
            'Admin panel (empty database)'   => $this->panelEmptySuite(...),
            'Diagnostics collector (self test)' => $this->diagnosticsProbeSuite(...),
            'PHP 8.1 compatibility scan'     => $this->compatibilitySuite(...),
        ];
    }

    /** How many admin-panel requests the suites drove, for the closing report. */
    public function panelRequests(): int
    {
        return $this->panelRequests;
    }

    /* =====================================================================
     | Bootstrap & configuration
     ===================================================================== */

    private function configSuite(): void
    {
        ok('bootstrap.php returned an App instance', $this->app instanceof App);
        ok('App::instance() is the booted app', App::instance() === $this->app);
        ok('AITALENTS_ROOT is defined', defined('AITALENTS_ROOT'));
        eq($this->root, defined('AITALENTS_ROOT') ? (string) AITALENTS_ROOT : '', 'AITALENTS_ROOT points at the project root');
        ok('AITALENTS_VERSION is defined', defined('AITALENTS_VERSION'));
        ok('App::version() is a non empty string', $this->app->version() !== '');

        // Dot notation.
        eq('Andijon AI Talents (tests)', $this->app->config('app.name'), 'config("app.name") resolves a nested key');
        eq(true, $this->app->config('app.steps.district'), 'config() walks three levels deep');
        eq('fallback', $this->app->config('app.does.not.exist', 'fallback'), 'config() returns the default for a missing key');
        ok('config(null) hands out the Config object', $this->app->config() instanceof Config);

        $config = new Config(['a' => ['b' => ['c' => 1]], 'top' => 'value']);
        eq(1, $config->get('a.b.c'), 'Config::get() reads a deep key');
        eq('value', $config->get('top'), 'Config::get() reads a top level key');
        eq(null, $config->get('a.b.missing'), 'Config::get() defaults to null');
        ok('Config::has() finds an existing key', $config->has('a.b.c'));
        ok('Config::has() rejects a missing key', !$config->has('a.b.x'));

        $config->set('a.b.d', 7);
        eq(7, $config->get('a.b.d'), 'Config::set() creates a deep key');
        eq(7, $config['a.b.d'], 'Config implements ArrayAccess (offsetGet)');
        ok('Config ArrayAccess offsetExists works', isset($config['top']));
        $config['fresh'] = 'x';
        eq('x', $config->get('fresh'), 'Config ArrayAccess offsetSet works');
        ok('Config::all() returns the whole array', array_key_exists('top', $config->all()));

        // Locales and admins.
        eq(['uz', 'ru'], $this->app->locales(), 'the configured locales are uz and ru');
        eq('uz', $this->app->defaultLocale(), 'the default locale is uz');
        ok('the configured admin id is recognised', $this->app->isAdmin(self::ADMIN_ID));
        ok('a random id is not an admin', !$this->app->isAdmin(424242));
        ok('adminIds() contains the configured admin', in_array(self::ADMIN_ID, $this->app->adminIds(), true));

        // Timestamps.
        ok(
            'App::now() produces a Y-m-d H:i:s timestamp',
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', App::now()) === 1
        );
        eq('Asia/Tashkent', date_default_timezone_get(), 'bootstrap.php applied the configured timezone');

        // Database wiring.
        eq('sqlite', $this->app->db()->driver(), 'the test database is SQLite');
        eq('users', $this->app->db()->table('users'), 'table() applies the (empty) prefix');
        ok('quoteIdent() quotes SQLite identifiers', $this->app->db()->quoteIdent('key') === '"key"');
    }

    /* =====================================================================
     | Migrations
     ===================================================================== */

    private function migrationSuite(): void
    {
        $db = $this->app->db();
        $migrator = new Migrator($db);

        ok('a fresh database reports "not installed"', !$migrator->isInstalled());
        ok('every migration is pending before the first run', $migrator->pending() !== []);

        $first = $migrator->run();
        ok('run() reported what it did', $first !== []);
        ok('the schema is installed after run()', $migrator->isInstalled());
        eq([], $migrator->pending(), 'nothing is pending after a successful run');

        $tables = [
            'users', 'registrations', 'settings', 'broadcasts', 'broadcast_targets',
            'rate_limits', 'audit_log', 'login_attempts', 'migrations',
        ];

        foreach ($tables as $table) {
            ok('table "' . $table . '" exists', $db->tableExists($table));
        }

        // Idempotency: a second run must not apply anything.
        $second = $migrator->run();
        $applied = array_values(array_filter(
            $second,
            static fn (string $line): bool => str_starts_with($line, 'applied')
        ));

        eq([], $applied, 'a second run() applies nothing (idempotent)');
        ok('the second run still reports the skipped migrations', $second !== []);
        ok('applied() lists the migration names', $migrator->applied() !== []);

        // The schema really is usable.
        $id = $db->insert('settings', ['name' => 'harness_probe', 'value' => '"1"', 'updated_at' => App::now()]);
        ok('an insert into settings returns an id', $id > 0);
        ok('the probe row can be read back', $db->exists('settings', ['name' => 'harness_probe']));
        eq(1, $db->delete('settings', ['name' => 'harness_probe']), 'the probe row can be deleted again');
    }

    /* =====================================================================
     | Network guard
     ===================================================================== */

    private function transportSuite(): void
    {
        $api = $this->app->api();

        ok('the app talks through the FakeTransport', $api->transport() instanceof FakeTransport);
        ok('the FakeTransport is the very instance the harness owns', $api->transport() === $this->fake);
        ok(
            'CurlTransport was never loaded',
            !class_exists('AiTalents\\Telegram\\CurlTransport', false)
        );

        $base = (string) $this->app->config('telegram.api_base', '');
        ok('the API base URL points at the loopback interface', str_contains($base, '127.0.0.1'));
        ok('the API base URL is not api.telegram.org', !str_contains($base, 'api.telegram.org'));

        // A call really is recorded instead of sent.
        $before = $this->fake->count();
        $this->fake->pushOk(true);
        $api->sendChatAction(self::ADMIN_ID, 'typing');
        eq($before + 1, $this->fake->count(), 'an API call is captured by the fake transport');
        eq('sendChatAction', (string) ($this->fake->lastCall()['method'] ?? ''), 'the fake decodes the method name from the URL');
    }

    /* =====================================================================
     | Text
     ===================================================================== */

    private function textSuite(): void
    {
        eq('&lt;b&gt;', Text::esc('<b>'), 'esc() escapes angle brackets');
        eq('&amp;', Text::esc('&'), 'esc() escapes ampersands');
        eq('&quot;', Text::esc('"'), 'esc() escapes double quotes');
        eq('&#039;', Text::esc("'"), 'esc() escapes single quotes numerically');
        eq('', Text::esc(null), 'esc(null) is an empty string');
        eq('Ali', Text::esc('Ali'), 'esc() leaves plain text alone');
        ok('esc() survives broken UTF-8', Text::esc("bad \xC3(") !== '');

        eq('a b', Text::clean("a   \t  b"), 'clean() collapses horizontal whitespace');
        eq("a\n\nb", Text::clean("a\n\n\n\n\nb"), 'clean() squashes blank line runs');
        eq('ab', Text::clean("a\x00b"), 'clean() strips control characters');
        eq('abc', Text::clean('  abc  '), 'clean() trims');
        eq(5, mb_strlen(Text::clean(str_repeat('x', 40), 5), 'UTF-8'), 'clean() honours the max length');

        eq('abc', Text::truncate('abc', 10), 'truncate() leaves short strings alone');
        ok('truncate() shortens long strings', mb_strlen(Text::truncate(str_repeat('x', 50), 10), 'UTF-8') <= 10);
        eq('', Text::truncate('abc', 0), 'truncate() with a zero limit is empty');
        eq(
            5,
            mb_strlen(Text::truncate('Тошкент шаҳри', 5), 'UTF-8'),
            'truncate() counts characters, not bytes'
        );

        eq('a b c', Text::normalizeSpaces("a\n b \t c"), 'normalizeSpaces() flattens every whitespace kind');

        eq('+998 90 123 45 67', Text::phoneDisplay('+998901234567'), 'phoneDisplay() groups an Uzbek number');
        ok('maskPhone() hides the middle digits', str_contains(Text::maskPhone('+998901234567'), '*'));
        ok('maskPhone() keeps the last two digits', str_ends_with(Text::maskPhone('+998901234567'), '67'));

        ok('slug() produces an ASCII slug', preg_match('/^[a-z0-9-]+$/', Text::slug('Bo‘z tumani')) === 1);
        eq('AV', Text::initials('Ali Valiyev'), 'initials() takes the first letters');
        ok('bytes() renders a human readable size', str_contains(Text::bytes(2048), 'KB'));
        eq('', Text::multiline(null), 'multiline(null) is empty');
        ok('multiline() escapes HTML', str_contains(Text::multiline('<b>x</b>'), '&lt;b&gt;'));
    }

    /* =====================================================================
     | Validator
     ===================================================================== */

    private function validatorSuite(): void
    {
        // Full name.
        $name = Validator::fullName('Ali Valiyev');
        ok('fullName() accepts a two word name', $name['ok'] === true);
        eq('Ali Valiyev', $name['value'], 'fullName() returns the normalised name');

        eq('Ali Valiyev', Validator::fullName('ali valiyev')['value'], 'fullName() capitalises lowercase input');
        ok('fullName() accepts Uzbek apostrophes', Validator::fullName('G‘ulom O‘ktamov')['ok'] === true);
        ok('fullName() accepts Cyrillic', Validator::fullName('Али Валиев')['ok'] === true);
        ok('fullName() accepts a hyphenated surname', Validator::fullName('Ali Valiyev-Qodirov')['ok'] === true);
        ok('fullName() rejects a single word', Validator::fullName('Ali')['ok'] === false);
        ok('fullName() rejects a two character input', Validator::fullName('Ab')['ok'] === false);
        ok('fullName() rejects digits', Validator::fullName('Ali 123')['ok'] === false);
        ok('fullName() rejects an 81 character name', Validator::fullName(str_repeat('a', 60) . ' ' . str_repeat('b', 40))['ok'] === false);
        ok('a rejected name carries a lang key', is_string(Validator::fullName('Ali')['error']));
        ok(
            'the full name error key exists in uz.php',
            Lang::has((string) Validator::fullName('Ali')['error'], 'uz')
        );

        // Phone.
        $phones = [
            '901234567'         => '+998901234567',
            '90 123 45 67'      => '+998901234567',
            '(90) 123-45-67'    => '+998901234567',
            '+998901234567'     => '+998901234567',
            '998901234567'      => '+998901234567',
            '+998 90 123 45 67' => '+998901234567',
            '00998901234567'    => '+998901234567',
            '0901234567'        => '+998901234567',
            '8 90 123 45 67'    => '+998901234567',
        ];

        foreach ($phones as $input => $expected) {
            $result = Validator::phone((string) $input);
            eq($expected, $result['ok'] === true ? $result['value'] : null, 'phone("' . $input . '") normalises to E.164');
        }

        foreach (['', 'abc', '12345', '+1 555 0100', '9012345678901234'] as $bad) {
            ok('phone("' . $bad . '") is rejected', Validator::phone($bad)['ok'] === false);
        }

        ok('phone() rejects an operator code starting with 0', Validator::phone('001234567')['ok'] === false);

        // Birth year.
        $year = (int) date('Y');
        ok('birthYear() accepts a plausible year', Validator::birthYear((string) ($year - 18))['ok'] === true);
        eq($year - 18, Validator::birthYear((string) ($year - 18))['value'], 'birthYear() returns an int');
        ok('birthYear() tolerates "2005 yil"', Validator::birthYear(((string) ($year - 20)) . ' yil')['ok'] === true);
        ok('birthYear() rejects a child under seven', Validator::birthYear((string) ($year - 3))['ok'] === false);
        ok('birthYear() rejects an age above sixty', Validator::birthYear((string) ($year - 80))['ok'] === false);
        ok('birthYear() rejects a future year', Validator::birthYear((string) ($year + 1))['ok'] === false);
        ok('birthYear() rejects free text', Validator::birthYear('kecha')['ok'] === false);
        ok('birthYear() rejects a three digit year', Validator::birthYear('200')['ok'] === false);

        // Portfolio.
        ok('portfolio() accepts normal text', Validator::portfolio('github.com/ali')['ok'] === true);
        ok('portfolio() rejects an empty answer', Validator::portfolio('   ')['ok'] === false);
        ok('portfolio() rejects more than 2000 characters', Validator::portfolio(str_repeat('a', 2001))['ok'] === false);

        // URLs and usernames.
        ok('isUrl() accepts an https link', Validator::isUrl('https://github.com/ali'));
        ok('isUrl() accepts a schemeless host', Validator::isUrl('github.com/ali'));
        ok('isUrl() rejects a sentence', !Validator::isUrl('salom dunyo'));

        $urls = Validator::extractUrls('Mening ishim: https://github.com/ali va t.me/ali_dev');
        ok('extractUrls() finds the https link', in_array('https://github.com/ali', $urls, true));
        eq(2, count($urls), 'extractUrls() finds both links');

        ok('username() accepts a valid handle', Validator::username('ali_valiyev'));
        ok('username() rejects a four character handle', !Validator::username('ali'));
        ok('username() rejects a handle with a dash', !Validator::username('ali-valiyev'));
    }

    /* =====================================================================
     | Lang
     ===================================================================== */

    private function langSuite(): void
    {
        eq('uz', Lang::FALLBACK, 'the fallback locale is uz');
        eq(['uz', 'ru'], array_keys(Lang::available()), 'available() lists uz and ru');

        eq('uz', Lang::normalize(null), 'normalize(null) falls back to uz');
        eq('uz', Lang::normalize(''), 'normalize("") falls back to uz');
        eq('ru', Lang::normalize('ru'), 'normalize("ru") stays ru');
        eq('ru', Lang::normalize('ru-RU'), 'normalize("ru-RU") becomes ru');
        eq('ru', Lang::normalize('RU_ru'), 'normalize("RU_ru") becomes ru');
        eq('uz', Lang::normalize('en-GB'), 'an unsupported locale falls back to uz');
        eq('uz', Lang::normalize('uz-Latn'), 'normalize("uz-Latn") stays uz');

        eq('this.key.does.not.exist', Lang::t('this.key.does.not.exist'), 'a missing key is returned verbatim');
        eq('this.key.does.not.exist', Lang::t('this.key.does.not.exist', 'ru'), 'a missing key is returned verbatim in ru too');

        ok('a real key resolves to a translation', Lang::t('common.yes', 'uz') !== 'common.yes');
        ok('the ru translation differs from the uz one', Lang::t('common.yes', 'ru') !== Lang::t('common.yes', 'uz'));

        ok('has() finds an existing key', Lang::has('common.yes', 'uz'));
        ok('has() rejects a missing key', !Lang::has('nope.nope', 'uz'));

        // Parameter substitution.
        $uz = Lang::load('uz');
        $withParam = null;

        foreach ($uz as $key => $value) {
            if (str_contains($value, ':id')) {
                $withParam = $key;
                break;
            }
        }

        if ($withParam === null) {
            skip('parameter substitution', 'no uz string uses the :id placeholder');
        } else {
            $rendered = Lang::t($withParam, 'uz', ['id' => 4242]);
            ok('a :id placeholder is replaced (' . $withParam . ')', str_contains($rendered, '4242'));
            ok('the placeholder itself is gone', !str_contains($rendered, ':id'));
        }

        // Without parameters a placeholder is left alone rather than blanked out.
        if ($withParam !== null) {
            ok('an unsupplied placeholder survives untouched', str_contains(Lang::t($withParam, 'uz'), ':id'));
        }

        // The ru -> uz fallback, proven on a throw-away language directory:
        // the shipped files are in perfect parity, so a gap has to be staged.
        $this->withTemporaryLanguages(
            ['probe.both' => 'UZ qiymat', 'probe.only_uz' => 'Faqat uzbekcha', 'probe.hello' => 'Salom :name'],
            ['probe.both' => 'RU значение', 'probe.hello' => 'Привет :name'],
            static function (): void {
                eq('RU значение', Lang::t('probe.both', 'ru'), 'a translated key uses the requested locale');
                eq('Faqat uzbekcha', Lang::t('probe.only_uz', 'ru'), 'a key missing in ru falls back to uz');
                eq('probe.nowhere', Lang::t('probe.nowhere', 'ru'), 'a key missing everywhere returns the key');
                ok('has() does not follow the fallback', !Lang::has('probe.only_uz', 'ru'));
                ok('has() sees the key in the fallback locale', Lang::has('probe.only_uz', 'uz'));
                eq('Привет Ali', Lang::t('probe.hello', 'ru', ['name' => 'Ali']), 'parameters are substituted in ru too');
                ok('all("ru") is filled up from uz', array_key_exists('probe.only_uz', Lang::all('ru')));
                eq('RU значение', Lang::all('ru')['probe.both'] ?? null, 'all("ru") keeps the ru translation');
            }
        );

        // Key parity between the two language files.
        $ru = Lang::load('ru');
        $onlyUz = array_values(array_diff(array_keys($uz), array_keys($ru)));
        $onlyRu = array_values(array_diff(array_keys($ru), array_keys($uz)));

        ok('lang/uz.php is not empty', count($uz) > 100);
        eq(count($uz), count($ru), 'uz and ru hold the same number of keys');
        eq([], $onlyUz, 'no key exists only in uz.php');
        eq([], $onlyRu, 'no key exists only in ru.php');

        $emptyUz = [];
        $emptyRu = [];

        foreach ($uz as $key => $value) {
            if (trim($value) === '') {
                $emptyUz[] = $key;
            }
        }

        foreach ($ru as $key => $value) {
            if (trim($value) === '') {
                $emptyRu[] = $key;
            }
        }

        eq([], $emptyUz, 'no uz translation is empty');
        eq([], $emptyRu, 'no ru translation is empty');

        note(count($uz) . ' keys per locale');
    }

    /**
     * Run $fn against a throw-away language directory, then restore the real one.
     *
     * @param array<string,string> $uz
     * @param array<string,string> $ru
     */
    private function withTemporaryLanguages(array $uz, array $ru, callable $fn): void
    {
        $directory = $this->tmpDir . '/lang';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            skip('the ru -> uz language fallback', 'cannot create ' . $directory);

            return;
        }

        $render = static function (array $strings): string {
            $lines = ["<?php\n", "declare(strict_types=1);\n\n", "return [\n"];

            foreach ($strings as $key => $value) {
                $lines[] = '    ' . var_export((string) $key, true) . ' => ' . var_export($value, true) . ",\n";
            }

            $lines[] = "];\n";

            return implode('', $lines);
        };

        file_put_contents($directory . '/uz.php', $render($uz));
        file_put_contents($directory . '/ru.php', $render($ru));

        try {
            Lang::setDirectory($directory);
            $fn();
        } finally {
            // Whatever happened: point the loader back at lang/ and drop the cache.
            Lang::setDirectory(null);
            Lang::flush();
        }
    }

    /* =====================================================================
     | Lang key coverage — the guard for the whole project
     ===================================================================== */

    private function langCoverageSuite(): void
    {
        $uz = Lang::load('uz');
        $files = project_php_files($this->root, ['src', 'admin']);

        ok('there are PHP sources to scan', $files !== []);

        /** @var array<string,string[]> $referenced key => files */
        $referenced = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            try {
                // Comments go, string literals stay: the keys live inside them.
                $code = php_without_comments($source);
            } catch (\Throwable $e) {
                // A file that cannot be tokenised is a problem of its own.
                ok('tokenising ' . $this->relativePath($file), false);

                continue;
            }

            // Lang::t('key', ...) — the literal must be the whole argument, so a
            // concatenated key such as Lang::t('btn.lang_' . $code) is skipped.
            if (preg_match_all("/Lang::t\\(\\s*'([^']*)'\\s*[,)]/", $code, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $referenced[$key][] = $file;
                }
            }

            // The admin panel helper t('key') — never ->t( or ::t( or format('...').
            if (preg_match_all("/(?<![\\w\\$>:\\\\-])t\\(\\s*'([^']*)'\\s*[,)]/", $code, $matches) > 0) {
                foreach ($matches[1] as $key) {
                    $referenced[$key][] = $file;
                }
            }
        }

        ok('the scanner found translation calls', count($referenced) > 50);
        note(count($referenced) . ' distinct keys referenced by src/ and admin/');

        $missing = [];

        foreach ($referenced as $key => $where) {
            if ($key === '' || array_key_exists($key, $uz)) {
                continue;
            }

            $sources = array_unique(array_map(fn (string $p): string => $this->relativePath($p), $where));
            $missing[$key] = implode(', ', $sources);
        }

        if ($missing === []) {
            ok('every referenced lang key exists in lang/uz.php', true);
        } else {
            foreach ($missing as $key => $where) {
                ok('lang key "' . $key . '" exists (used in ' . $where . ')', false);
            }
        }

        // Keys the enums build at runtime — the regex cannot see those.
        $dynamic = [];

        foreach (Step::cases() as $step) {
            $dynamic[] = $step->labelKey();
            $dynamic[] = $step->promptKey();
            $dynamic[] = $step->hintKey();
            $dynamic[] = $step->fieldKey();
        }

        foreach (Step::questions() as $step) {
            $dynamic[] = 'btn.edit_' . $step->value;
        }

        foreach (RegistrationStatus::cases() as $status) {
            $dynamic[] = $status->labelKey();
            $dynamic[] = $status->hintKey();
        }

        foreach ($this->app->locales() as $locale) {
            $dynamic[] = 'btn.lang_' . $locale;
            $dynamic[] = 'lang.native_' . $locale;
        }

        $dynamicMissing = [];

        foreach (array_unique($dynamic) as $key) {
            if (!array_key_exists($key, $uz)) {
                $dynamicMissing[] = $key;
            }
        }

        eq([], $dynamicMissing, 'every lang key built at runtime by the enums exists');
    }

    /* =====================================================================
     | Catalog
     ===================================================================== */

    private function catalogSuite(): void
    {
        $directions = Catalog::directions();
        $districts = Catalog::districts();

        eq(11, count($directions), 'the catalog holds 11 directions');
        eq(18, count($districts), 'the catalog holds 18 districts');

        foreach (['programming', 'ai_ml', 'data_science', 'design', 'web', 'mobile',
                  'robotics', 'cybersecurity', 'game_3d', 'content', 'other'] as $key) {
            ok('direction "' . $key . '" is in the catalog', Catalog::hasDirection($key));
        }

        foreach (['andijon_city', 'xonobod_city', 'qorasuv_city', 'andijon', 'asaka', 'baliqchi',
                  'boz', 'buloqboshi', 'izboskan', 'jalaquduq', 'xojaobod', 'qorgontepa',
                  'marhamat', 'oltinkol', 'paxtaobod', 'shahrixon', 'ulugnor', 'other'] as $key) {
            ok('district "' . $key . '" is in the catalog', Catalog::hasDistrict($key));
        }

        $brokenDirections = [];

        foreach ($directions as $key => $entry) {
            if (!is_string($key) || $key === ''
                || !isset($entry['uz'], $entry['ru'], $entry['emoji'])
                || trim((string) $entry['uz']) === ''
                || trim((string) $entry['ru']) === ''
                || trim((string) $entry['emoji']) === '') {
                $brokenDirections[] = (string) $key;
            }
        }

        eq([], $brokenDirections, 'every direction carries uz, ru and an emoji');

        $brokenDistricts = [];

        foreach ($districts as $key => $entry) {
            if (!is_string($key) || $key === ''
                || !isset($entry['uz'], $entry['ru'], $entry['type'])
                || !in_array($entry['type'], ['city', 'district'], true)) {
                $brokenDistricts[] = (string) $key;
            }
        }

        eq([], $brokenDistricts, 'every district carries uz, ru and a valid type');

        eq(array_keys($directions), Catalog::directionKeys(), 'directionKeys() mirrors directions()');
        eq(array_keys($districts), Catalog::districtKeys(), 'districtKeys() mirrors districts()');

        ok('hasDirection() rejects an unknown key', !Catalog::hasDirection('quantum'));
        ok('hasDistrict() rejects an unknown key', !Catalog::hasDistrict('tashkent'));

        $uzLabel = Catalog::directionLabel('ai_ml', 'uz');
        $ruLabel = Catalog::directionLabel('ai_ml', 'ru');
        ok('directionLabel() renders the uz label with the emoji', str_contains($uzLabel, '🤖'));
        ok('directionLabel(withEmoji=false) drops the emoji', !str_contains(Catalog::directionLabel('ai_ml', 'uz', false), '🤖'));
        ok('the ru direction label differs from the uz one', $uzLabel !== $ruLabel);

        ok('districtLabel() renders Uzbek', str_contains(Catalog::districtLabel('asaka', 'uz'), 'Asaka'));
        ok('districtLabel() renders Russian', str_contains(Catalog::districtLabel('asaka', 'ru'), 'Асак'));

        eq(['ai_ml', 'web'], Catalog::filterDirections(['ai_ml', 'nope', 'web', 'ai_ml']), 'filterDirections() drops unknown and duplicate keys');
        eq([], Catalog::filterDirections('not an array'), 'filterDirections() tolerates junk');
        eq('other', Catalog::OTHER, 'the "other" key is a constant');

        // Every district key must survive the 64 byte callback budget with a prefix.
        $tooLong = [];

        foreach (array_merge(array_keys($directions), array_keys($districts)) as $key) {
            if (strlen('d:' . $key) > 64 || strlen('t:' . $key) > 64) {
                $tooLong[] = (string) $key;
            }
        }

        eq([], $tooLong, 'every catalog key fits into a callback_data payload');
    }

    /* =====================================================================
     | Keyboard
     ===================================================================== */

    private function keyboardSuite(): void
    {
        $btn = Keyboard::btn('Ha', 'r:confirm');
        eq(['text' => 'Ha', 'callback_data' => 'r:confirm'], $btn, 'btn() builds a callback button');

        $url = Keyboard::url('Kanal', 'https://t.me/andijon');
        eq('https://t.me/andijon', $url['url'] ?? null, 'url() builds a link button');

        $contact = Keyboard::contact('Raqamni yuborish');
        eq(true, $contact['request_contact'] ?? null, 'contact() asks for the phone number');

        $inline = Keyboard::inline([[$btn]]);
        $decoded = json_decode($inline, true);
        ok('inline() produces JSON', is_array($decoded));
        ok('inline() nests inline_keyboard rows', isset($decoded['inline_keyboard'][0][0]['callback_data']));
        eq('r:confirm', $decoded['inline_keyboard'][0][0]['callback_data'] ?? null, 'the callback data survives encoding');

        $reply = json_decode(Keyboard::reply([['A', 'B']], true, false, 'Tanlang'), true);
        ok('reply() marks the keyboard resizable', ($reply['resize_keyboard'] ?? false) === true);
        eq('Tanlang', $reply['input_field_placeholder'] ?? null, 'reply() carries the placeholder');

        $remove = json_decode(Keyboard::remove(), true);
        ok('remove() removes the keyboard', ($remove['remove_keyboard'] ?? false) === true);

        $rows = Keyboard::rows([$btn, $btn, $btn, $btn, $btn], 2);
        eq(3, count($rows), 'rows() splits five buttons into three rows of two');
        eq(2, count($rows[0]), 'the first row holds two buttons');
        eq(1, count($rows[2]), 'the last row holds the remainder');

        $back = Keyboard::backRow('Orqaga', 'r:back', 'Bekor', 'r:cancel');
        eq(2, count($back), 'backRow() renders back and cancel');
        $backOnly = Keyboard::backRow('Orqaga', 'r:back');
        eq(1, count($backOnly), 'backRow() without a cancel button renders one button');

        // The 64 byte budget.
        eq(64, Keyboard::CALLBACK_DATA_LIMIT, 'the callback data limit is 64 bytes');
        ok('a 64 byte payload fits', Keyboard::callbackDataFits(str_repeat('a', 64)));
        ok('a 65 byte payload does not fit', !Keyboard::callbackDataFits(str_repeat('a', 65)));
        ok('an empty payload does not fit', !Keyboard::callbackDataFits(''));

        eq('r:ok', Keyboard::guardCallbackData('r:ok'), 'guardCallbackData() returns valid data');
        throws(
            static fn () => Keyboard::guardCallbackData(str_repeat('x', 65)),
            InvalidArgumentException::class,
            'guardCallbackData() rejects 65 bytes'
        );
        throws(
            static fn () => Keyboard::guardCallbackData(''),
            InvalidArgumentException::class,
            'guardCallbackData() rejects an empty payload'
        );
        throws(
            static fn () => Keyboard::inline([[Keyboard::btn('x', str_repeat('y', 70))]]),
            InvalidArgumentException::class,
            'inline() refuses an oversized callback payload'
        );
    }

    /* =====================================================================
     | Enums
     ===================================================================== */

    private function enumSuite(): void
    {
        eq('reg:phone', Step::Phone->stateKey(), 'stateKey() prefixes the step value');
        eq(Step::Phone, Step::fromState('reg:phone'), 'fromState() resolves a full state string');
        eq(Step::Phone, Step::fromState('phone'), 'fromState() resolves a bare value');
        eq(null, Step::fromState('idle'), 'fromState("idle") is null');
        eq(null, Step::fromState('admin:broadcast'), 'fromState() ignores foreign states');
        eq('step.phone', Step::Phone->labelKey(), 'labelKey() builds the step.* key');
        eq(6, count(Step::questions()), 'six steps collect data');
        ok('FullName is a question', Step::FullName->isQuestion());
        ok('Confirm is not a question', !Step::Confirm->isQuestion());
        ok('BirthYear is optional', Step::BirthYear->isOptional());
        ok('Phone is not optional', !Step::Phone->isOptional());

        eq('status.pending', RegistrationStatus::Pending->labelKey(), 'labelKey() builds the status.* key');
        eq('warn', RegistrationStatus::Pending->badge(), 'pending uses the warn badge');
        eq('ok', RegistrationStatus::Approved->badge(), 'approved uses the ok badge');
        eq('danger', RegistrationStatus::Rejected->badge(), 'rejected uses the danger badge');
        eq('⏳', RegistrationStatus::Pending->emoji(), 'pending shows an hourglass');
        eq(RegistrationStatus::Approved, RegistrationStatus::tryOrNull('APPROVED'), 'tryOrNull() is case insensitive');
        eq(null, RegistrationStatus::tryOrNull('nonsense'), 'tryOrNull() rejects nonsense');
        eq(null, RegistrationStatus::tryOrNull(null), 'tryOrNull(null) is null');
        eq(RegistrationStatus::Pending, RegistrationStatus::default(), 'a fresh application is pending');
    }

    /* =====================================================================
     | Logger
     ===================================================================== */

    private function loggerSuite(): void
    {
        $dir = $this->tmpDir . '/logger';
        $logger = new Logger($dir, 'debug', true, 3);

        $logger->info('harness info line', ['k' => 'v']);
        $logger->error('harness error line');
        $logger->debug('harness debug line');

        $tail = $logger->tail(50);
        ok('the log file received the lines', count($tail) >= 3);
        ok('tail() returns the newest line last', str_contains((string) end($tail), 'harness debug line'));
        ok('context is serialised into the line', str_contains(implode("\n", $tail), '"k"'));
        ok('files() lists the current day', $logger->files() !== []);

        // A higher threshold silences the lower levels.
        $quietDir = $this->tmpDir . '/logger-quiet';
        $quiet = new Logger($quietDir, 'error', true, 3);
        $quiet->debug('must not appear');
        $quiet->error('must appear');
        $quietTail = implode("\n", $quiet->tail(50));
        ok('a debug line is dropped at level=error', !str_contains($quietTail, 'must not appear'));
        ok('an error line is kept at level=error', str_contains($quietTail, 'must appear'));

        // Disabled logger writes nothing at all.
        $offDir = $this->tmpDir . '/logger-off';
        $off = new Logger($offDir, 'debug', false, 3);
        $off->error('nothing');
        eq([], $off->tail(10), 'a disabled logger produces no lines');

        // The logger never throws, even on an impossible directory.
        $broken = new Logger('/proc/definitely-not-writable/aitalents', 'debug', true, 3);
        $threw = false;

        try {
            $broken->error('this must be swallowed');
            $broken->purgeOld();
        } catch (\Throwable $e) {
            $threw = true;
        }

        ok('the logger swallows filesystem errors', !$threw);
    }

    /* =====================================================================
     | Telegram Api
     ===================================================================== */

    private function apiSuite(): void
    {
        $fake = new FakeTransport();
        $api = new Api('123:TEST', null, 5, $fake, 'http://127.0.0.1:9/offline');

        eq(4096, $api->messageLimit(), 'the message limit is 4096 characters');

        // Splitting a long message.
        $line = str_repeat('a', 200);
        $long = implode("\n", array_fill(0, 60, $line)); // ~12k characters
        ok('the sample message is longer than the limit', mb_strlen($long, 'UTF-8') > 4096);

        $api->sendMessage(555, $long);
        $calls = $fake->callsOf('sendMessage');
        ok('a >4096 character message is split into several calls', count($calls) > 1);

        $oversized = [];
        $rebuilt = [];

        foreach ($calls as $call) {
            $text = (string) ($call['params']['text'] ?? '');
            $rebuilt[] = $text;

            if (mb_strlen($text, 'UTF-8') > 4096) {
                $oversized[] = mb_strlen($text, 'UTF-8');
            }
        }

        eq([], $oversized, 'every chunk stays within 4096 characters');
        eq($long, implode("\n", $rebuilt), 'the chunks rebuild the original message');

        // Defaults.
        $fake->reset();
        $api->sendMessage(555, 'salom');
        $params = $fake->lastCall('sendMessage')['params'] ?? [];
        eq('HTML', $params['parse_mode'] ?? null, 'sendMessage() defaults to parse_mode=HTML');
        eq(true, $params['disable_web_page_preview'] ?? null, 'sendMessage() disables the link preview');
        eq(555, $params['chat_id'] ?? null, 'the chat id travels with the call');

        $fake->reset();
        $api->sendMessage(555, 'salom', ['parse_mode' => 'MarkdownV2']);
        eq('MarkdownV2', $fake->lastCall('sendMessage')['params']['parse_mode'] ?? null, 'an explicit parse_mode wins');

        // splitText on its own.
        eq([], $api->splitText(''), 'splitText("") is empty');
        eq(['short'], $api->splitText('short'), 'splitText() leaves a short text alone');

        // Callback answers and their length cap.
        $fake->reset();
        $fake->pushOk(true);
        ok('answerCallbackQuery() reports success', $api->answerCallbackQuery('cb-1', 'Tayyor'));
        $answer = $fake->lastCall('answerCallbackQuery')['params'] ?? [];
        eq('cb-1', $answer['callback_query_id'] ?? null, 'the callback id travels with the answer');

        $fake->reset();
        $fake->pushOk(true);
        $api->answerCallbackQuery('cb-2', str_repeat('x', 400), true);
        $answer = $fake->lastCall('answerCallbackQuery')['params'] ?? [];
        ok('a callback answer is capped at 200 characters', mb_strlen((string) ($answer['text'] ?? ''), 'UTF-8') <= 200);
        eq(true, $answer['show_alert'] ?? null, 'show_alert is forwarded');

        // Errors.
        $fake->reset();
        $fake->pushError(400, 'Bad Request: chat not found');
        $result = $api->tryCall('sendMessage', ['chat_id' => 1, 'text' => 'x']);
        eq(false, $result['ok'] ?? null, 'tryCall() reports a Telegram error instead of throwing');
        eq(400, $result['error_code'] ?? null, 'tryCall() surfaces the error code');

        $fake->reset();
        $fake->pushError(403, 'Forbidden: bot was blocked by the user');
        throws(
            static fn () => $api->call('sendMessage', ['chat_id' => 1, 'text' => 'x']),
            ApiException::class,
            'call() throws an ApiException on ok:false'
        );

        $blocked = new ApiException('Forbidden: bot was blocked by the user', 403, [
            'ok' => false,
            'error_code' => 403,
            'description' => 'Forbidden: bot was blocked by the user',
        ]);
        ok('isBlockedByUser() detects a blocked bot', $blocked->isBlockedByUser());
        eq(403, $blocked->errorCode(), 'errorCode() returns the Telegram code');

        $flood = new ApiException('Too Many Requests', 429, [
            'ok' => false,
            'error_code' => 429,
            'description' => 'Too Many Requests: retry after 7',
            'parameters' => ['retry_after' => 7],
        ]);
        eq(7, $flood->retryAfter(), 'retryAfter() reads the parameters');
        ok('a 429 is not a block', !$flood->isBlockedByUser());

        // A token-less client refuses to talk at all.
        $mute = new Api('', null, 5, new FakeTransport(), 'http://127.0.0.1:9/offline');
        ok('hasToken() is false without a token', !$mute->hasToken());
        throws(
            static fn () => $mute->call('getMe'),
            ApiException::class,
            'a call without a token throws instead of hitting the network'
        );
    }

    /* =====================================================================
     | UserRepository
     ===================================================================== */

    private function userRepositorySuite(): void
    {
        $users = $this->app->users();
        $id = self::REPO_USER_ID;

        eq(null, $users->findByTelegramId($id), 'an unknown user is null');

        $row = $users->touch([
            'id' => $id,
            'is_bot' => false,
            'first_name' => 'Nodira',
            'last_name' => 'Qodirova',
            'username' => 'nodira_q',
            'language_code' => 'ru',
        ], 'private');

        eq($id, (int) $row['telegram_id'], 'touch() creates the row');
        eq('nodira_q', $row['username'], 'touch() stores the username');
        eq('ru', $row['locale'], 'touch() maps language_code onto the locale');
        eq('idle', $row['state'], 'a new user starts idle');
        eq(0, (int) $row['is_admin'], 'a new user is not an admin');
        eq(0, (int) $row['is_blocked'], 'a new user is not blocked');
        ok('last_seen_at is set for a private chat', ($row['last_seen_at'] ?? null) !== null);

        // A second touch updates instead of duplicating.
        $again = $users->touch([
            'id' => $id,
            'first_name' => 'Nodira',
            'last_name' => 'Qodirova',
            'username' => 'nodira_dev',
            'language_code' => 'uz',
        ], 'private');

        eq((int) $row['id'], (int) $again['id'], 'touch() reuses the existing row');
        eq('nodira_dev', $again['username'], 'touch() refreshes a changed username');
        eq('ru', $again['locale'], 'touch() never overwrites the chosen locale');
        eq(1, $this->app->db()->count('users', ['telegram_id' => $id]), 'exactly one row per telegram id');

        // The state machine.
        eq('idle', $users->state($id), 'state() defaults to idle');
        eq([], $users->stateData($id), 'stateData() starts empty');

        $users->setState($id, Step::FullName->stateKey(), ['mode' => 'new']);
        eq('reg:full_name', $users->state($id), 'setState() writes the state');
        eq(['mode' => 'new'], $users->stateData($id), 'setState() writes the state data');

        $users->setState($id, Step::Phone->stateKey());
        eq('reg:phone', $users->state($id), 'setState() without data moves the state');
        eq(['mode' => 'new'], $users->stateData($id), 'setState() without data keeps the payload');

        $merged = $users->mergeStateData($id, ['full_name' => 'Nodira Qodirova']);
        eq(['mode' => 'new', 'full_name' => 'Nodira Qodirova'], $merged, 'mergeStateData() returns the merged payload');
        eq($merged, $users->stateData($id), 'mergeStateData() persists the merge');

        $users->mergeStateData($id, ['mode' => 'update']);
        eq('update', $users->stateData($id)['mode'] ?? null, 'mergeStateData() overwrites an existing key');

        $users->clearState($id);
        eq('idle', $users->state($id), 'clearState() returns to idle');
        eq([], $users->stateData($id), 'clearState() forgets the payload');

        // Locale, block and admin flags.
        $users->setLocale($id, 'ru');
        eq('ru', $users->findByTelegramId($id)['locale'] ?? null, 'setLocale() stores a supported locale');
        $users->setLocale($id, 'de');
        eq('uz', $users->findByTelegramId($id)['locale'] ?? null, 'setLocale() normalises an unsupported locale');
        $users->setLocale($id, 'uz');

        $users->setBlocked($id, true);
        eq(1, (int) ($users->findByTelegramId($id)['is_blocked'] ?? 0), 'setBlocked(true) sets the flag');
        ok('a blocked user is not in activeTelegramIds()', !in_array($id, $users->activeTelegramIds(), true));
        $users->setBlocked($id, false);
        ok('an unblocked user is back in activeTelegramIds()', in_array($id, $users->activeTelegramIds(), true));

        $users->setAdmin($id, true);
        ok('setAdmin(true) is reflected by adminTelegramIds()', in_array($id, $users->adminTelegramIds(), true));
        ok('App::isAdmin() honours the database flag', $this->app->isAdmin($id));
        $users->setAdmin($id, false);
        ok('setAdmin(false) revokes the flag', !in_array($id, $users->adminTelegramIds(), true));

        // Two more users for the listing assertions.
        $users->touch(['id' => self::REPO_USER_ID_2, 'first_name' => 'Bekzod', 'username' => 'bekzod'], 'private');
        $users->touch(['id' => self::REPO_USER_ID_3, 'first_name' => 'Malika', 'username' => 'malika'], 'private');
        $users->setBlocked(self::REPO_USER_ID_3, true);

        ok('total() counts every user', $users->total() >= 3);
        ok('blockedCount() counts the blocked ones', $users->blockedCount() >= 1);

        $found = $users->paginate(['q' => 'bekzod'], 10, 0);
        eq(1, count($found), 'paginate() finds a user by username');
        eq(self::REPO_USER_ID_2, (int) ($found[0]['telegram_id'] ?? 0), 'the right user came back');
        eq(1, $users->countAll(['q' => 'bekzod']), 'countAll() agrees with paginate()');

        $blocked = $users->paginate(['blocked' => 1], 50, 0);
        $blockedIds = array_map(static fn (array $r): int => (int) $r['telegram_id'], $blocked);
        ok('the blocked filter finds the blocked user', in_array(self::REPO_USER_ID_3, $blockedIds, true));
        ok('the blocked filter hides the active users', !in_array(self::REPO_USER_ID_2, $blockedIds, true));

        eq(1, count($users->paginate([], 1, 0)), 'paginate() honours the limit');

        // An unknown sort column must not reach the SQL.
        $safe = true;

        try {
            $users->paginate([], 5, 0, 'id; DROP TABLE users', 'desc');
        } catch (\Throwable $e) {
            $safe = false;
        }

        ok('paginate() whitelists the sort column', $safe && $this->app->db()->tableExists('users'));

        $telegramIds = $users->paginate([], 5, 0, 'telegram_id', 'asc');
        ok('a whitelisted sort column works', $telegramIds !== []);

        // Unknown users never fatal.
        eq('idle', $users->state(1), 'state() of an unknown user is idle');
        eq([], $users->stateData(1), 'stateData() of an unknown user is empty');
        eq(null, $users->findById(999999), 'findById() returns null for a missing row');

        throws(
            static fn () => $users->touch([]),
            InvalidArgumentException::class,
            'touch() refuses a "from" object without an id'
        );
    }

    /* =====================================================================
     | RegistrationRepository
     ===================================================================== */

    private function registrationRepositorySuite(): void
    {
        $repo = $this->app->registrations();
        $users = $this->app->users();

        $userRow = $users->findByTelegramId(self::REPO_USER_ID);
        $userId = (int) ($userRow['id'] ?? 0);
        ok('the repository user exists', $userId > 0);

        $id = $repo->save($userId, self::REPO_USER_ID, [
            'full_name' => 'Nodira Qodirova',
            'phone' => '+998901112233',
            'birth_year' => 2004,
            'district' => 'asaka',
            'directions' => ['ai_ml', 'web'],
            'direction_other' => null,
            'portfolio' => 'https://github.com/nodira',
            'portfolio_links' => ['https://github.com/nodira'],
            'status' => 'pending',
            'source' => 'bot',
        ]);

        ok('save() returns a registration id', $id > 0);

        $row = $repo->findByTelegramId(self::REPO_USER_ID);
        ok('the row can be read back', $row !== null);
        eq('Nodira Qodirova', $row['full_name'] ?? null, 'the full name round-trips');
        eq('+998901112233', $row['phone'] ?? null, 'the phone round-trips');
        eq(2004, $row['birth_year'] ?? null, 'the birth year is an int');
        eq(['ai_ml', 'web'], $row['directions'] ?? null, 'directions come back decoded');
        eq(['https://github.com/nodira'], $row['portfolio_links'] ?? null, 'portfolio_links come back decoded');
        eq('pending', $row['status'] ?? null, 'a fresh application is pending');
        eq($userId, $row['user_id'] ?? null, 'the user id is stored');

        // Saving again updates the same row.
        $again = $repo->save($userId, self::REPO_USER_ID, [
            'full_name' => 'Nodira Qodirova',
            'phone' => '+998901112233',
            'birth_year' => 2003,
            'district' => 'boz',
            'directions' => ['design'],
        ]);

        eq($id, $again, 'save() updates instead of inserting a duplicate');
        eq(1, $this->app->db()->count('registrations', ['telegram_id' => self::REPO_USER_ID]), 'still exactly one row');

        $row = $repo->findById($id);
        eq('boz', $row['district'] ?? null, 'the district was updated');
        eq(['design'], $row['directions'] ?? null, 'the directions were replaced');
        eq(2003, $row['birth_year'] ?? null, 'the birth year was updated');

        // Two more applications for the filters.
        $second = $repo->save(0, self::REPO_USER_ID_2, [
            'full_name' => 'Bekzod Rasulov',
            'phone' => '+998902223344',
            'birth_year' => 2001,
            'district' => 'asaka',
            'directions' => ['web', 'mobile'],
            'portfolio' => 'telegram: @bekzod',
            'status' => 'approved',
        ]);

        $third = $repo->save(0, self::REPO_USER_ID_3, [
            'full_name' => 'Malika Yusupova',
            'phone' => '+998903334455',
            'birth_year' => 2006,
            'district' => 'marhamat',
            'directions' => ['design', 'content'],
            'status' => 'rejected',
        ]);

        ok('two more applications were stored', $second > 0 && $third > 0);

        // Filters.
        eq(1, $repo->countAll(['status' => 'approved']), 'the status filter narrows the result');
        eq(2, $repo->countAll(['district' => 'asaka']) + $repo->countAll(['district' => 'boz']), 'the district filter works');
        eq(1, $repo->countAll(['direction' => 'mobile']), 'the direction filter matches inside the JSON column');
        eq(2, $repo->countAll(['direction' => 'design']), 'the direction filter finds every applicant of a direction');
        eq(0, $repo->countAll(['direction' => 'robotics']), 'an unused direction returns nothing');
        eq(1, $repo->countAll(['q' => 'Bekzod']), 'the free text filter searches the name');
        eq(1, $repo->countAll(['q' => '+998903334455']), 'the free text filter searches the phone');
        eq(1, $repo->countAll(['q' => (string) self::REPO_USER_ID_2]), 'the free text filter matches the telegram id');
        ok(
            'the free text filter matches the application id',
            in_array($second, array_map(
                static fn (array $r): int => (int) $r['id'],
                $repo->paginate(['q' => (string) $second], 50, 0)
            ), true)
        );
        eq(0, $repo->countAll(['date_from' => date('Y-m-d', strtotime('+2 days'))]), 'a future date_from excludes everything');
        ok('a date_to of today keeps the rows', $repo->countAll(['date_to' => date('Y-m-d')]) >= 3);

        // Pagination and sorting.
        $page = $repo->paginate([], 2, 0, 'created_at', 'desc');
        eq(2, count($page), 'paginate() honours the limit');
        $rest = $repo->paginate([], 2, 2, 'created_at', 'desc');
        ok('paginate() honours the offset', $rest !== []);

        $byName = $repo->paginate([], 10, 0, 'full_name', 'asc');
        $names = array_map(static fn (array $r): string => (string) $r['full_name'], $byName);
        $sorted = $names;
        sort($sorted, SORT_STRING);
        eq($sorted, $names, 'paginate() sorts by full_name');

        $safe = true;

        try {
            $repo->paginate([], 5, 0, 'id) --', 'asc');
        } catch (\Throwable $e) {
            $safe = false;
        }

        ok('paginate() whitelists the sort column', $safe && $this->app->db()->tableExists('registrations'));

        // search / latest / total.
        eq(1, count($repo->search('Malika')), 'search() finds an applicant');
        ok('latest() returns the newest first', count($repo->latest(2)) === 2);
        ok('total() counts every application', $repo->total() >= 3);

        // Status changes.
        ok('setStatus() approves an application', $repo->setStatus($id, 'approved', 'panel:tests', 'ok'));
        $row = $repo->findById($id);
        eq('approved', $row['status'] ?? null, 'the new status is stored');
        eq('ok', $row['admin_note'] ?? null, 'the admin note is stored');
        ok('reviewed_at was written', ($row['reviewed_at'] ?? null) !== null);
        ok('setStatus() rejects an unknown status', !$repo->setStatus($id, 'banana'));
        $repo->setStatus($id, 'pending');

        // Broadcast targeting.
        $ids = $repo->telegramIdsFor([]);
        ok('telegramIdsFor() lists the applicants', in_array(self::REPO_USER_ID, $ids, true));
        ok('telegramIdsFor() skips blocked users', !in_array(self::REPO_USER_ID_3, $ids, true));
        eq(1, count($repo->telegramIdsFor(['status' => 'approved'])), 'telegramIdsFor() honours the filters');

        // each() streams everything.
        $streamed = 0;
        $seen = [];

        foreach ($repo->each([], 2) as $registration) {
            $streamed++;
            $seen[] = (int) $registration['id'];
            ok('each() yields decoded directions for #' . (int) $registration['id'], is_array($registration['directions']));
        }

        eq($repo->countAll(), $streamed, 'each() yields every row across chunk boundaries');
        eq(count(array_unique($seen)), $streamed, 'each() never yields a row twice');
        eq(0, iterator_count($repo->each(['direction' => 'robotics'])), 'each() honours the filters');

        // Deleting.
        ok('deleteById() removes a row', $repo->deleteById($third));
        eq(null, $repo->findById($third), 'the deleted row is gone');
        ok('deleteById() of a missing row is false', !$repo->deleteById($third));
        ok('deleteByTelegramId() removes a row', $repo->deleteByTelegramId(self::REPO_USER_ID_2));
        eq(null, $repo->findByTelegramId(self::REPO_USER_ID_2), 'the row is gone by telegram id too');

        throws(
            static fn () => $repo->save(0, 0, ['full_name' => 'x', 'phone' => '+998901234567']),
            InvalidArgumentException::class,
            'save() refuses a zero telegram id'
        );
    }

    /* =====================================================================
     | SettingRepository, App::setting() and the audit log
     ===================================================================== */

    private function settingsSuite(): void
    {
        $settings = $this->app->settings();
        $audit = $this->app->audit();

        // Typed round-trips: the repository JSON encodes on the way in.
        eq(null, $settings->get('harness_missing'), 'an unknown setting is null');
        eq('fallback', $settings->get('harness_missing', 'fallback'), 'an unknown setting honours the default');

        $settings->set('harness_string', 'salom');
        eq('salom', $settings->get('harness_string'), 'a string round-trips');

        $settings->set('harness_bool', false);
        eq(false, $settings->get('harness_bool'), 'a boolean false round-trips (and is not read as null)');

        $settings->set('harness_int', 42);
        eq(42, $settings->get('harness_int'), 'an integer round-trips');

        $settings->set('harness_array', ['a' => 1, 'b' => [2, 3]]);
        eq(['a' => 1, 'b' => [2, 3]], $settings->get('harness_array'), 'a nested array round-trips');

        $settings->set('harness_string', 'yangi');
        eq('yangi', $settings->get('harness_string'), 'set() overwrites an existing setting');
        eq(1, $this->app->db()->count('settings', ['name' => 'harness_string']), 'set() never duplicates a row');

        ok('has() finds a stored setting', $settings->has('harness_string'));
        ok('all() returns the stored settings', array_key_exists('harness_int', $settings->all()));

        $settings->forget('harness_string');
        ok('forget() removes the setting', !$settings->has('harness_string'));
        eq(null, $settings->get('harness_string'), 'a forgotten setting reads back as null');

        // App::setting(): the table wins, config.php is the fallback.
        eq(true, $this->app->setting('registration_open'), 'setting() falls back to config.php');
        $settings->set('registration_open', false);
        $settings->flush();
        eq(false, $this->app->setting('registration_open'), 'a stored setting overrides config.php');
        $settings->forget('registration_open');
        $settings->flush();
        eq(true, $this->app->setting('registration_open'), 'removing the override restores the config value');
        eq('nothing', $this->app->setting('a_setting_that_does_not_exist', 'nothing'), 'setting() honours the default');

        // Audit log.
        $before = $audit->countAll();
        $audit->log('panel:tests', 'harness_action', 'registration:1', ['note' => 'salom'], '203.0.113.9');
        eq($before + 1, $audit->countAll(), 'log() appends an entry');

        $entries = $audit->paginate(['action' => 'harness_action'], 10, 0);
        eq(1, count($entries), 'paginate() filters by action');
        eq('panel:tests', $entries[0]['actor'] ?? null, 'the actor is stored');
        eq('registration:1', $entries[0]['target'] ?? null, 'the target is stored');
        eq('203.0.113.9', $entries[0]['ip'] ?? null, 'the ip is stored');
        ok('the meta payload survives', str_contains(json_encode($entries[0]['meta'] ?? null) ?: '', 'salom'));

        eq(1, count($audit->forTarget('registration:1', 10)), 'forTarget() finds the trail of one object');
        ok('actions() lists the recorded action names', in_array('harness_action', $audit->actions(50), true));
        eq(0, $audit->purgeOlderThan(3650), 'purgeOlderThan() keeps recent entries');

        // The audit log must never break a request, whatever it is handed.
        $threw = false;

        try {
            $audit->log('', '', null, [], null);
            $audit->log(str_repeat('x', 500), str_repeat('y', 500), str_repeat('z', 500), ['deep' => ['a' => ['b' => 1]]]);
        } catch (\Throwable $e) {
            $threw = true;
        }

        ok('log() never throws', !$threw);
    }

    /* =====================================================================
     | StatsService
     ===================================================================== */

    private function statsSuite(): void
    {
        $stats = $this->app->stats();
        $db = $this->app->db();

        // Backdate one application so the daily series has something to find.
        $id = $this->app->registrations()->save(0, 8880001, [
            'full_name' => 'Sardor Aliyev',
            'phone' => '+998904445566',
            'birth_year' => 2002,
            'district' => 'izboskan',
            'directions' => ['robotics'],
            'status' => 'pending',
        ]);

        $threeDaysAgo = date('Y-m-d', strtotime('-3 days'));
        $db->update('registrations', ['created_at' => $threeDaysAgo . ' 10:15:00'], ['id' => $id]);

        $overview = $stats->overview();

        foreach (['users', 'registrations', 'today', 'yesterday', 'week', 'month',
                  'pending', 'approved', 'rejected', 'blocked', 'conversion'] as $key) {
            ok('overview() exposes "' . $key . '"', array_key_exists($key, $overview));
        }

        ok('overview().users counts the users', (int) $overview['users'] > 0);
        ok('overview().registrations counts the applications', (int) $overview['registrations'] > 0);
        ok('the conversion rate is a percentage', $overview['conversion'] >= 0.0 && $overview['conversion'] <= 100.0);
        eq(
            (int) $overview['registrations'],
            (int) $overview['pending'] + (int) $overview['approved'] + (int) $overview['rejected'],
            'the status counters add up to the total'
        );

        // daily(): gap filled, ascending, ending today.
        $series = $stats->daily(14);
        eq(14, count($series), 'daily(14) returns exactly 14 buckets');
        eq(date('Y-m-d'), $series[13]['date'] ?? null, 'the last bucket is today');
        eq(date('Y-m-d', strtotime('-13 days')), $series[0]['date'] ?? null, 'the first bucket is 13 days ago');

        $dates = array_map(static fn (array $r): string => (string) $r['date'], $series);
        $sorted = $dates;
        sort($sorted, SORT_STRING);
        eq($sorted, $dates, 'the daily series is ascending');
        eq(count(array_unique($dates)), count($dates), 'every day appears exactly once');

        $everyBucketIsAnInt = true;

        foreach ($series as $bucket) {
            if (!is_int($bucket['count'] ?? null)) {
                $everyBucketIsAnInt = false;
            }
        }

        ok('every bucket carries an int count', $everyBucketIsAnInt);
        eq(0, $series[1]['count'] ?? null, 'a day without applications is filled with a zero');

        $backdated = null;

        foreach ($series as $bucket) {
            if ($bucket['date'] === $threeDaysAgo) {
                $backdated = (int) $bucket['count'];
            }
        }

        eq(1, $backdated, 'the backdated application lands in its own bucket');
        eq(1, count($stats->daily(1)), 'daily(1) returns a single bucket');

        // hourly()
        $hourly = $stats->hourly();
        eq(24, count($hourly), 'hourly() returns 24 buckets');
        ok('hourly buckets carry a label', isset($hourly[0]['label']));

        // Breakdowns.
        $byDirection = $stats->byDirection();
        ok('byDirection() returns rows', $byDirection !== []);
        ok('byDirection() rows carry key/label/count', isset($byDirection[0]['key'], $byDirection[0]['count']));

        $counts = array_map(static fn (array $r): int => (int) $r['count'], $byDirection);
        $descending = $counts;
        rsort($descending);
        eq($descending, $counts, 'byDirection() is sorted descending');

        $robotics = 0;

        foreach ($byDirection as $bucket) {
            if ($bucket['key'] === 'robotics') {
                $robotics = (int) $bucket['count'];
            }
        }

        eq(1, $robotics, 'byDirection() counts the JSON encoded direction');

        $byDistrict = $stats->byDistrict();
        ok('byDistrict() returns rows', $byDistrict !== []);
        ok('byDistrict() rows carry key/label/count', isset($byDistrict[0]['key'], $byDistrict[0]['count']));

        $byStatus = $stats->byStatus();
        ok('byStatus() returns rows', $byStatus !== []);

        $top = $stats->topDay();
        ok('topDay() returns a day or null', $top === null || isset($top['date']));

        // Clean up so the later suites see a predictable table.
        $this->app->registrations()->deleteById($id);
    }

    /* =====================================================================
     | RateLimiter
     ===================================================================== */

    private function rateLimiterSuite(): void
    {
        $db = $this->app->db();
        $limiter = new RateLimiter($db, true, 3, 60);
        $id = 6660001;

        $limiter->reset($id, 'test');

        eq(3, $limiter->remaining($id, 'test'), 'a fresh window has the full budget');
        ok('the first hit is allowed', $limiter->allow($id, 'test'));
        ok('the second hit is allowed', $limiter->allow($id, 'test'));
        eq(1, $limiter->remaining($id, 'test'), 'remaining() counts down');
        ok('the third hit is allowed', $limiter->allow($id, 'test'));
        eq(0, $limiter->remaining($id, 'test'), 'the budget is used up');
        ok('the fourth hit is refused', !$limiter->allow($id, 'test'));
        ok('the fifth hit is refused as well', !$limiter->allow($id, 'test'));

        // Buckets are independent.
        ok('another bucket has its own window', $limiter->allow($id, 'other'));

        // A new window opens once the old one expired.
        $db->update('rate_limits', ['window_started_at' => time() - 120], ['telegram_id' => $id, 'bucket' => 'test']);
        ok('an expired window lets the user through again', $limiter->allow($id, 'test'));
        eq(2, $limiter->remaining($id, 'test'), 'the new window starts at one hit');

        $limiter->reset($id, 'test');
        eq(3, $limiter->remaining($id, 'test'), 'reset() clears the window');

        // Disabled limiter.
        $off = new RateLimiter($db, false, 1, 60);
        ok('a disabled limiter always allows', $off->allow($id, 'test') && $off->allow($id, 'test') && $off->allow($id, 'test'));
        ok('isEnabled() reports the flag', !$off->isEnabled());

        // A database without the table must never fatal.
        $emptyDb = new Database([
            'driver' => 'sqlite',
            'prefix' => '',
            'path' => $this->tmpDir . '/no-schema.sqlite',
        ]);

        $orphan = new RateLimiter($emptyDb, true, 1, 60);
        ok('a missing rate_limits table lets everything through', $orphan->allow($id) && $orphan->allow($id));
        eq(1, $orphan->remaining($id), 'remaining() falls back to the maximum');
        $orphan->reset($id);
        ok('reset() is a no-op without the table', true);
    }

    /* =====================================================================
     | XlsxWriter
     ===================================================================== */

    private function xlsxWriterSuite(): void
    {
        // Column reference encoding beyond Z.
        eq('A', XlsxWriter::columnLetter(1), 'columnLetter(1) is A');
        eq('Z', XlsxWriter::columnLetter(26), 'columnLetter(26) is Z');
        eq('AA', XlsxWriter::columnLetter(27), 'columnLetter(27) is AA');
        eq('AD', XlsxWriter::columnLetter(30), 'columnLetter(30) is AD');
        eq('AZ', XlsxWriter::columnLetter(52), 'columnLetter(52) is AZ');
        eq('BA', XlsxWriter::columnLetter(53), 'columnLetter(53) is BA');
        eq('AAA', XlsxWriter::columnLetter(703), 'columnLetter(703) is AAA');
        eq('A', XlsxWriter::columnLetter(0), 'columnLetter() clamps to A');

        ok(
            'zipSupported() names a known strategy',
            in_array(XlsxWriter::zipSupported(), ['ziparchive', 'native'], true)
        );
        note('zip strategy: ' . XlsxWriter::zipSupported());

        $path = $this->tmpDir . '/writer.xlsx';
        $writer = $this->buildWorkbook();

        eq(2, $writer->rowCount(), 'rowCount() counts the data rows only');
        eq(30, $writer->columnCount(), 'columnCount() counts the declared columns');

        $bytes = $writer->toString();
        ok('toString() produces bytes', strlen($bytes) > 0);
        ok('the package starts with the ZIP local header magic', str_starts_with($bytes, "PK\x03\x04"));
        ok('the package carries a central directory', str_contains($bytes, "PK\x01\x02"));
        ok('the package carries an end-of-central-directory record', str_contains($bytes, "PK\x05\x06"));

        $written = $writer->save($path);
        ok('save() reports the byte count', $written > 0);
        ok('save() created the file', is_file($path));
        eq($written, filesize($path) === false ? -1 : filesize($path), 'save() returned the real file size');

        $this->assertWorkbookIsValid($path, 'default');

        // The pure PHP ZIP fallback must produce the very same, readable package.
        $this->withNativeZip(function (): void {
            eq('native', XlsxWriter::zipSupported(), 'the native ZIP writer is active');

            $nativePath = $this->tmpDir . '/writer-native.xlsx';
            $this->buildWorkbook()->save($nativePath);

            ok('the native writer produced a file', is_file($nativePath));

            $head = (string) file_get_contents($nativePath, false, null, 0, 4);
            ok('the native package starts with the ZIP magic', $head === "PK\x03\x04");

            $this->assertWorkbookIsValid($nativePath, 'native');
        });

        eq('sanitised name', (new XlsxWriter('sanitised name'))->sheetName(), 'the sheet name survives when it is legal');
        ok(
            'an illegal sheet name is sanitised',
            !str_contains((new XlsxWriter('a/b[c]:d*e?f'))->sheetName(), '/')
        );
        ok(
            'a long sheet name is cut to 31 characters',
            mb_strlen((new XlsxWriter(str_repeat('x', 60)))->sheetName(), 'UTF-8') <= 31
        );
    }

    /** Build the workbook used by both ZIP strategies. */
    private function buildWorkbook(): XlsxWriter
    {
        $columns = [];

        for ($i = 1; $i <= 30; $i++) {
            $columns[] = [
                'title' => 'Ustun ' . $i,
                'width' => 14,
                'type' => $i === 1 ? 'number' : 'text',
            ];
        }

        $writer = new XlsxWriter('Arizalar');
        $writer->setColumns($columns)->setFreezeHeader(true)->setAutoFilter(true);

        $first = ['1', '+998901234567', '5551001', "chiziq\nikkinchi", '<b>&amp;</b>'];
        $first = array_pad($first, 30, 'x');
        $writer->addRow($first);

        $second = array_pad(['2', '+998907654321'], 30, 'y');
        $writer->addRow($second);

        return $writer;
    }

    /**
     * Open a generated workbook and verify every part of the OOXML package.
     */
    private function assertWorkbookIsValid(string $path, string $label): void
    {
        if (!class_exists('ZipArchive')) {
            skip('the ' . $label . ' package is readable by ZipArchive', 'ext-zip is not installed');

            return;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($path);

        if ($opened !== true) {
            ok('the ' . $label . ' package opens with ZipArchive (code ' . (string) $opened . ')', false);

            return;
        }

        ok('the ' . $label . ' package opens with ZipArchive', true);

        $expected = [
            '[Content_Types].xml',
            '_rels/.rels',
            'docProps/app.xml',
            'docProps/core.xml',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ];

        $missing = [];
        $malformed = [];

        foreach ($expected as $part) {
            $contents = $zip->getFromName($part);

            if ($contents === false) {
                $missing[] = $part;

                continue;
            }

            $document = new DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $parsed = $document->loadXML($contents);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            if (!$parsed) {
                $malformed[] = $part;
            }
        }

        eq([], $missing, 'the ' . $label . ' package contains every OOXML part');
        eq([], $malformed, 'every part of the ' . $label . ' package is well-formed XML');

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (!is_string($sheet)) {
            ok('the ' . $label . ' worksheet can be read', false);

            return;
        }

        ok('the ' . $label . ' worksheet freezes the header row', str_contains($sheet, 'topLeftCell="A2"'));
        ok('the ' . $label . ' worksheet defines an autofilter', str_contains($sheet, '<autoFilter'));
        ok(
            'the ' . $label . ' worksheet addresses a column beyond Z',
            str_contains($sheet, 'r="AD1"')
        );
        ok(
            'the phone number stays an inline string in the ' . $label . ' worksheet',
            str_contains($sheet, '>+998901234567<')
        );
        ok(
            'the telegram id is not turned into a number in the ' . $label . ' worksheet',
            str_contains($sheet, '>5551001<')
        );
        ok(
            'HTML in a cell is XML escaped in the ' . $label . ' worksheet',
            str_contains($sheet, '&lt;b&gt;')
        );

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadXML($sheet);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $rows = $document->getElementsByTagName('row');
        eq(3, $rows->length, 'the ' . $label . ' worksheet holds a header plus two data rows');

        $header = $rows->item(0);
        $headerCells = 0;

        if ($header !== null) {
            foreach ($header->childNodes as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'c') {
                    $headerCells++;
                }
            }
        }

        eq(30, $headerCells, 'the ' . $label . ' header row holds one cell per column');
    }

    /**
     * Run $fn with the pure-PHP ZIP writer forced on, then restore the default.
     */
    private function withNativeZip(callable $fn): void
    {
        try {
            $property = new ReflectionProperty(XlsxWriter::class, 'forceNativeZip');
        } catch (\ReflectionException $e) {
            skip('the pure-PHP ZIP fallback', 'XlsxWriter has no forceNativeZip switch');

            return;
        }

        $property->setAccessible(true);
        $previous = (bool) $property->getValue();

        try {
            $property->setValue(null, true);
            $fn();
        } finally {
            $property->setValue(null, $previous);
        }
    }

    /* =====================================================================
     | XlsxExporter
     ===================================================================== */

    private function xlsxExporterSuite(): void
    {
        $exporter = new XlsxExporter($this->app->registrations());

        $columns = $exporter->columns('uz');
        $headers = $exporter->headers('uz');

        eq(19, count($columns), 'the export has 19 columns');
        eq(count($columns), count($headers), 'headers() and columns() agree');
        eq(Lang::t('export.id', 'uz'), $headers[0], 'the first column title comes from export.id');
        eq(Lang::t('export.updated_at', 'uz'), $headers[18], 'the last column title comes from export.updated_at');

        $untranslated = [];

        foreach ($headers as $header) {
            if (str_starts_with($header, 'export.')) {
                $untranslated[] = $header;
            }
        }

        eq([], $untranslated, 'every export column title is translated');
        ok('the ru headers differ from the uz ones', $exporter->headers('ru') !== $headers);

        // A single row renders labels, not raw keys.
        $row = $exporter->row([
            'id' => 7,
            'created_at' => '2026-09-01 09:30:00',
            'full_name' => 'Sardor Aliyev',
            'phone' => '+998904445566',
            'birth_year' => 2002,
            'district' => 'asaka',
            'directions' => ['ai_ml', 'web'],
            'direction_other' => '',
            'portfolio' => 'github.com/sardor',
            'portfolio_links' => ['https://github.com/sardor'],
            'status' => 'approved',
            'admin_note' => '',
            'telegram_id' => 5551001,
            'username' => 'sardor',
            'locale' => 'uz',
            'source' => 'bot',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'updated_at' => '2026-09-01 10:00:00',
        ], 'uz');

        eq(count($columns), count($row), 'row() lines up with the columns');
        eq('+998904445566', $row[3], 'the phone stays a string with its plus sign');
        ok('the district is rendered as a label', str_contains((string) $row[5], 'Asaka'));
        ok('the directions are a readable list', str_contains((string) $row[6], ','));
        ok('the directions are not raw keys', !str_contains((string) $row[6], 'ai_ml'));
        ok('the status is rendered as a label', (string) $row[10] !== 'approved');

        // A complete build over the live table.
        $writer = $exporter->build([], 'uz');
        eq($this->app->registrations()->countAll(), $writer->rowCount(), 'build() exports every matching row');

        $path = $this->tmpDir . '/export.xlsx';
        $written = $exporter->toFile($path, [], 'uz');
        ok('toFile() reports the exported row count', $written === $this->app->registrations()->countAll());
        ok('toFile() created the workbook', is_file($path) && filesize($path) > 0);
        ok('the exported workbook is a ZIP', str_starts_with((string) file_get_contents($path, false, null, 0, 4), "PK\x03\x04"));

        $filtered = $this->tmpDir . '/export-filtered.xlsx';
        $exporter->toFile($filtered, ['direction' => 'nothing_matches_this'], 'uz');
        ok('an empty result still produces a valid workbook', is_file($filtered) && filesize($filtered) > 0);

        $filename = $exporter->filename('uz');
        ok('filename() ends with .xlsx', str_ends_with($filename, '.xlsx'));
        ok('filename() carries the current date', str_contains($filename, date('Y-m-d')));
        ok('filename() is ASCII safe', preg_match('/^[A-Za-z0-9._-]+$/', $filename) === 1);
    }

    /* =====================================================================
     | The full registration flow through Router::dispatch()
     ===================================================================== */

    private function flowSuite(): void
    {
        $users = $this->app->users();
        $registrations = $this->app->registrations();
        $router = new Router($this->app);
        $applicant = self::APPLICANT_ID;

        // A clean slate for this applicant.
        $registrations->deleteByTelegramId($applicant);
        $this->fake->reset();

        $flowStart = count($this->fake->calls);

        /* --- 1. /start --------------------------------------------------- */
        $router->dispatch($this->message($applicant, '/start'));

        $userRow = $users->findByTelegramId($applicant);
        ok('/start created the user row', $userRow !== null);
        eq('idle', (string) ($userRow['state'] ?? ''), '/start leaves the user idle');
        ok('/start answered with a message', $this->fake->count('sendMessage') >= 1);

        $welcome = (string) ($this->fake->lastCall('sendMessage')['params']['text'] ?? '');
        ok('the welcome message is not empty', trim($welcome) !== '');
        ok('the welcome message carries a reply keyboard', isset($this->fake->lastCall('sendMessage')['params']['reply_markup']));

        /* --- 2. the language picker (config app.ask_language) ------------- */
        if ((bool) $this->app->config('app.ask_language', true)) {
            $router->dispatch($this->message($applicant, '/til'));

            $picker = $this->fake->lastCall('sendMessage')['params'] ?? [];
            $markup = json_decode((string) ($picker['reply_markup'] ?? '{}'), true);
            $languageButtons = [];

            foreach ((array) ($markup['inline_keyboard'] ?? []) as $row) {
                foreach ((array) $row as $button) {
                    if (isset($button['callback_data']) && str_starts_with((string) $button['callback_data'], Flow::CB_LANGUAGE)) {
                        $languageButtons[] = (string) $button['callback_data'];
                    }
                }
            }

            eq(2, count($languageButtons), '/til offers one button per locale');
            ok('the language buttons use the l: prefix', in_array('l:ru', $languageButtons, true));

            $router->dispatch($this->callback($applicant, 'l:ru'));
            eq('ru', (string) ($users->findByTelegramId($applicant)['locale'] ?? ''), 'the picked language is stored');

            $router->dispatch($this->callback($applicant, 'l:uz'));
            eq('uz', (string) ($users->findByTelegramId($applicant)['locale'] ?? ''), 'switching back to uz works');
        } else {
            skip('the language picker', 'app.ask_language is disabled');
        }

        /* --- 3. the questionnaire ---------------------------------------- */
        $router->dispatch($this->message($applicant, '/register'));

        // With more than one locale configured and app.ask_language on, the
        // questionnaire opens with the language picker. It collects no
        // application data, so it carries no progress number and is not part of
        // sequence(); answering it drops straight into the first real question.
        if ((bool) $this->app->config('app.ask_language', true) && count($this->app->locales()) > 1) {
            eq(Step::Language->stateKey(), $users->state($applicant), 'the wizard opens with the language picker');

            $langPrompt = (string) ($this->fake->lastCall('sendMessage')['params']['text'] ?? '');
            ok('the language prompt carries no progress line', !str_contains($langPrompt, '1/6'));

            $router->dispatch($this->callback($applicant, 'l:uz'));
        }

        eq(Step::FullName->stateKey(), $users->state($applicant), 'the wizard asks for the full name first');

        // Answering the picker rewrites the picker message in place, so the
        // prompt may arrive as an edit rather than a fresh message.
        $promptCall = $this->fake->lastCall('sendMessage');
        $editCall   = $this->fake->lastCall('editMessageText');
        $prompt     = (string) ($promptCall['params']['text'] ?? '');
        $edited     = (string) ($editCall['params']['text'] ?? '');

        ok(
            'the first prompt shows the progress line',
            str_contains($prompt, '1/6') || str_contains($edited, '1/6')
        );

        // A rejected answer must not advance the machine.
        $router->dispatch($this->message($applicant, 'Ali'));
        eq(Step::FullName->stateKey(), $users->state($applicant), 'a one word name is rejected');

        $router->dispatch($this->message($applicant, 'Ali Valiyev'));
        eq(Step::Phone->stateKey(), $users->state($applicant), 'a valid name moves on to the phone step');
        eq('Ali Valiyev', $users->stateData($applicant)['full_name'] ?? null, 'the name is kept in state_data');

        $phonePrompt = $this->fake->lastCall('sendMessage')['params'] ?? [];
        $replyMarkup = json_decode((string) ($phonePrompt['reply_markup'] ?? '{}'), true);
        $asksForContact = false;

        foreach ((array) ($replyMarkup['keyboard'] ?? []) as $row) {
            foreach ((array) $row as $button) {
                if (is_array($button) && ($button['request_contact'] ?? false) === true) {
                    $asksForContact = true;
                }
            }
        }

        ok('the phone step offers a request_contact button', $asksForContact);

        // A contact that belongs to somebody else is refused.
        $router->dispatch($this->contactMessage($applicant, '+998911112233', 4242));
        eq(Step::Phone->stateKey(), $users->state($applicant), 'a foreign contact card is refused');

        $router->dispatch($this->contactMessage($applicant, '+998 90 123 45 67', $applicant));
        eq(Step::BirthYear->stateKey(), $users->state($applicant), 'a shared contact moves on to the birth year');
        eq('+998901234567', $users->stateData($applicant)['phone'] ?? null, 'the shared phone is normalised');

        $router->dispatch($this->message($applicant, '1800'));
        eq(Step::BirthYear->stateKey(), $users->state($applicant), 'an impossible birth year is rejected');

        $router->dispatch($this->message($applicant, '2005'));
        eq(Step::District->stateKey(), $users->state($applicant), 'a valid birth year moves on to the district');
        eq(2005, $users->stateData($applicant)['birth_year'] ?? null, 'the birth year is stored as an int');

        $router->dispatch($this->callback($applicant, 't:not_a_district'));
        eq(Step::District->stateKey(), $users->state($applicant), 'an unknown district key is refused');

        $router->dispatch($this->callback($applicant, 't:andijon_city'));
        eq(Step::Direction->stateKey(), $users->state($applicant), 'picking a district moves on to the directions');
        eq('andijon_city', $users->stateData($applicant)['district'] ?? null, 'the district key is stored');

        // Multi-select: toggle on, toggle off, toggle on again.
        $router->dispatch($this->callback($applicant, 'd:ai_ml'));
        eq(['ai_ml'], $users->stateData($applicant)['directions'] ?? null, 'a direction toggles on');

        $router->dispatch($this->callback($applicant, 'd:web'));
        eq(['ai_ml', 'web'], $users->stateData($applicant)['directions'] ?? null, 'a second direction is added');

        $router->dispatch($this->callback($applicant, 'd:web'));
        eq(['ai_ml'], $users->stateData($applicant)['directions'] ?? null, 'tapping a selected direction removes it');

        $router->dispatch($this->callback($applicant, 'r:dok'));
        eq(Step::Portfolio->stateKey(), $users->state($applicant), 'confirming the directions moves on to the portfolio');

        $router->dispatch($this->message($applicant, 'Loyihalarim: https://github.com/ali/ai-bot'));
        eq(Step::Confirm->stateKey(), $users->state($applicant), 'the portfolio answer moves on to the summary');

        $summary = (string) ($this->fake->lastCall('sendMessage')['params']['text'] ?? '');
        ok('the summary shows the name', str_contains($summary, 'Ali Valiyev'));
        ok('the summary shows the phone number', str_contains($summary, '90 123 45 67'));
        ok('the summary shows the district', str_contains($summary, 'Andijon'));

        $confirmMarkup = json_decode(
            (string) ($this->fake->lastCall('sendMessage')['params']['reply_markup'] ?? '{}'),
            true
        );
        $confirmButtons = [];

        foreach ((array) ($confirmMarkup['inline_keyboard'] ?? []) as $row) {
            foreach ((array) $row as $button) {
                $confirmButtons[] = (string) ($button['callback_data'] ?? '');
            }
        }

        ok('the summary offers a confirm button', in_array('r:confirm', $confirmButtons, true));
        ok('the summary offers an edit button', in_array('r:edit', $confirmButtons, true));
        ok('the summary offers a cancel button', in_array('r:cancel', $confirmButtons, true));

        /* --- 4. confirm --------------------------------------------------- */
        $beforeConfirm = count($this->fake->calls);
        $router->dispatch($this->callback($applicant, 'r:confirm'));

        eq('idle', $users->state($applicant), 'the state is cleared after a successful submission');
        eq([], $users->stateData($applicant), 'the collected answers are dropped after the submission');

        $row = $registrations->findByTelegramId($applicant);
        ok('a row landed in registrations', $row !== null);

        if ($row === null) {
            return;
        }

        $this->flowRegistrationId = (int) $row['id'];

        eq('Ali Valiyev', $row['full_name'], 'the stored full name is correct');
        eq('+998901234567', $row['phone'], 'the stored phone is normalised to E.164');
        eq(2005, $row['birth_year'], 'the stored birth year is correct');
        eq('andijon_city', $row['district'], 'the stored district key is correct');
        eq(['ai_ml'], $row['directions'], 'the stored directions are decoded correctly');
        eq('pending', $row['status'], 'a fresh application is pending');
        eq('bot', $row['source'], 'the application was recorded as coming from the bot');
        eq($applicant, $row['telegram_id'], 'the telegram id is stored');
        eq((int) ($users->findByTelegramId($applicant)['id'] ?? 0), $row['user_id'], 'the application links to the user row');
        ok('the portfolio text is stored', str_contains((string) $row['portfolio'], 'github.com/ali/ai-bot'));
        eq(['https://github.com/ali/ai-bot'], $row['portfolio_links'], 'the portfolio link was extracted');
        ok('created_at was written', trim((string) $row['created_at']) !== '');

        /* --- 5. the admin notification ------------------------------------ */
        $adminCalls = [];

        foreach (array_slice($this->fake->calls, $beforeConfirm) as $call) {
            if (strtolower($call['method']) === 'sendmessage'
                && (int) ($call['params']['chat_id'] ?? 0) === self::ADMIN_ID) {
                $adminCalls[] = $call;
            }
        }

        ok('the administrator received a notification', $adminCalls !== []);

        if ($adminCalls !== []) {
            $card = (string) ($adminCalls[0]['params']['text'] ?? '');
            ok('the admin card names the applicant', str_contains($card, 'Ali Valiyev'));
            ok('the admin card shows the application number', str_contains($card, (string) $this->flowRegistrationId));
            eq('HTML', $adminCalls[0]['params']['parse_mode'] ?? null, 'the admin card is sent as HTML');

            $cardMarkup = json_decode((string) ($adminCalls[0]['params']['reply_markup'] ?? '{}'), true);
            $decisions = [];

            foreach ((array) ($cardMarkup['inline_keyboard'] ?? []) as $rowButtons) {
                foreach ((array) $rowButtons as $button) {
                    $decisions[] = (string) ($button['callback_data'] ?? '');
                }
            }

            ok('the admin card offers an approve button', in_array('a:ok:' . $this->flowRegistrationId, $decisions, true));
            ok('the admin card offers a reject button', in_array('a:no:' . $this->flowRegistrationId, $decisions, true));
        }

        /* --- 6. the applicant was told about the submission --------------- */
        $applicantTexts = [];

        foreach (array_slice($this->fake->calls, $beforeConfirm) as $call) {
            if (strtolower($call['method']) === 'sendmessage'
                && (int) ($call['params']['chat_id'] ?? 0) === $applicant) {
                $applicantTexts[] = (string) ($call['params']['text'] ?? '');
            }
        }

        ok('the applicant received a confirmation', $applicantTexts !== []);
        ok(
            'the confirmation carries the application number',
            str_contains(implode("\n", $applicantTexts), (string) $this->flowRegistrationId)
        );

        /* --- 7. every message really went out as escaped HTML ------------- */
        $badParseMode = [];

        foreach (array_slice($this->fake->calls, $flowStart) as $call) {
            if (strtolower($call['method']) !== 'sendmessage') {
                continue;
            }

            if (($call['params']['parse_mode'] ?? null) !== 'HTML') {
                $badParseMode[] = (string) ($call['params']['text'] ?? '');
            }
        }

        eq([], $badParseMode, 'every message of the flow is sent with parse_mode=HTML');

        /* --- 8. a second /register offers an update instead of blocking --- */
        $router->dispatch($this->message($applicant, '/register'));
        $offer = (string) ($this->fake->lastCall('sendMessage')['params']['text'] ?? '');
        ok('a returning applicant is told about the existing application', str_contains($offer, (string) $this->flowRegistrationId));
        eq('idle', $users->state($applicant), 'the wizard does not restart behind the applicant\'s back');

        // Keep the recorded calls for the callback-budget suite.
        $this->flowCalls = array_slice($this->fake->calls, $flowStart);
        note(count($this->flowCalls) . ' Telegram calls recorded during the flow');
    }

    /* =====================================================================
     | Callback data budget
     ===================================================================== */

    private function callbackBudgetSuite(): void
    {
        if ($this->flowCalls === []) {
            skip('the callback data budget', 'the flow suite recorded no calls');

            return;
        }

        $payloads = [];

        foreach ($this->flowCalls as $call) {
            $markup = $call['params']['reply_markup'] ?? null;

            if (!is_string($markup) || $markup === '') {
                continue;
            }

            $decoded = json_decode($markup, true);

            if (!is_array($decoded)) {
                continue;
            }

            foreach ((array) ($decoded['inline_keyboard'] ?? []) as $row) {
                foreach ((array) $row as $button) {
                    if (is_array($button) && isset($button['callback_data']) && is_string($button['callback_data'])) {
                        $payloads[$button['callback_data']] = true;
                    }
                }
            }
        }

        $payloads = array_keys($payloads);

        ok('the flow emitted inline buttons', $payloads !== []);
        note(count($payloads) . ' distinct callback payloads emitted');

        $tooLong = [];
        $empty = 0;

        foreach ($payloads as $payload) {
            if ($payload === '') {
                $empty++;

                continue;
            }

            if (strlen($payload) > Keyboard::CALLBACK_DATA_LIMIT) {
                $tooLong[] = $payload . ' (' . strlen($payload) . ' bytes)';
            }
        }

        eq([], $tooLong, 'every emitted callback_data stays within 64 bytes');
        eq(0, $empty, 'no button carries an empty callback_data');

        // Every payload must belong to one of the documented prefixes.
        $prefixes = [Flow::CB_REG, Flow::CB_DIRECTION, Flow::CB_DISTRICT, Flow::CB_LANGUAGE, Flow::CB_EDIT, 'a:', 'sub:'];
        $foreign = [];

        foreach ($payloads as $payload) {
            $known = false;

            foreach ($prefixes as $prefix) {
                if (str_starts_with($payload, $prefix)) {
                    $known = true;
                    break;
                }
            }

            if (!$known) {
                $foreign[] = $payload;
            }
        }

        eq([], $foreign, 'every callback payload uses a documented prefix');
    }

    /* =====================================================================
     | Router guards
     ===================================================================== */

    private function routerSuite(): void
    {
        $router = new Router($this->app);
        $users = $this->app->users();
        $stranger = 4440001;

        // A group chat is ignored without a word.
        $this->fake->reset();
        $router->dispatch(new Update([
            'update_id' => ++$this->updateId,
            'message' => [
                'message_id' => ++$this->messageId,
                'from' => ['id' => $stranger, 'is_bot' => false, 'first_name' => 'Guruh'],
                'chat' => ['id' => -100123, 'type' => 'supergroup'],
                'date' => time(),
                'text' => '/start',
            ],
        ]));

        eq(0, $this->fake->count(), 'a group message is ignored silently');
        eq(null, $users->findByTelegramId($stranger), 'a group message does not create a user row');

        // An unknown update kind is ignored.
        $this->fake->reset();
        $router->dispatch(new Update(['update_id' => ++$this->updateId, 'poll' => ['id' => '1']]));
        eq(0, $this->fake->count(), 'an unsupported update kind is ignored');

        // An unknown command gets a friendly hint.
        $this->fake->reset();
        $router->dispatch($this->message($stranger, '/definitely_not_a_command'));
        ok('an unknown command is answered', $this->fake->count('sendMessage') >= 1);
        eq(
            Lang::t('error.unknown_command', 'uz'),
            (string) ($this->fake->lastCall('sendMessage')['params']['text'] ?? ''),
            'the answer is the unknown-command string'
        );

        // Plain chatter outside the wizard gets the unknown-input hint.
        $this->fake->reset();
        $router->dispatch($this->message($stranger, 'salom bot'));
        eq(
            Lang::t('error.unknown_input', 'uz'),
            (string) ($this->fake->lastCall('sendMessage')['params']['text'] ?? ''),
            'unknown chatter gets the unknown-input hint'
        );

        // my_chat_member: blocking and unblocking the bot.
        $this->fake->reset();
        $router->dispatch(new Update([
            'update_id' => ++$this->updateId,
            'my_chat_member' => [
                'chat' => ['id' => $stranger, 'type' => 'private'],
                'from' => ['id' => $stranger, 'is_bot' => false, 'first_name' => 'Sardor'],
                'date' => time(),
                'old_chat_member' => ['status' => 'member', 'user' => ['id' => 1, 'is_bot' => true]],
                'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 1, 'is_bot' => true]],
            ],
        ]));

        eq(1, (int) ($users->findByTelegramId($stranger)['is_blocked'] ?? 0), 'blocking the bot sets is_blocked');
        eq(0, $this->fake->count(), 'a block notification produces no outgoing message');

        // A blocked user is ignored.
        $this->fake->reset();
        $router->dispatch($this->message($stranger, '/start'));
        eq(0, $this->fake->count(), 'a blocked user gets no answer');

        $router->dispatch(new Update([
            'update_id' => ++$this->updateId,
            'my_chat_member' => [
                'chat' => ['id' => $stranger, 'type' => 'private'],
                'from' => ['id' => $stranger, 'is_bot' => false, 'first_name' => 'Sardor'],
                'date' => time(),
                'old_chat_member' => ['status' => 'kicked', 'user' => ['id' => 1, 'is_bot' => true]],
                'new_chat_member' => ['status' => 'member', 'user' => ['id' => 1, 'is_bot' => true]],
            ],
        ]));

        eq(0, (int) ($users->findByTelegramId($stranger)['is_blocked'] ?? 1), 'unblocking clears is_blocked');

        // A stale callback query is answered so the client stops spinning.
        $this->fake->reset();
        $router->dispatch($this->callback($stranger, 'r:nonsense'));
        ok('a stale callback query is answered', $this->fake->count('answerCallbackQuery') >= 1);

        // The rate limiter drops a flood without sending anything.
        $flooder = 4440002;
        $limiter = new RateLimiter($this->app->db(), true, 500, 60);
        $limiter->reset($flooder, 'msg');
        $this->app->db()->insert('rate_limits', [
            'telegram_id' => $flooder,
            'bucket' => 'msg',
            'hits' => 100000,
            'window_started_at' => time(),
        ]);

        $this->fake->reset();
        $router->dispatch($this->message($flooder, '/start'));
        eq(0, $this->fake->count(), 'a message over the rate limit is dropped silently');
        $this->app->db()->delete('rate_limits', ['telegram_id' => $flooder]);

        // dispatch() never throws, even on garbage.
        $threw = false;

        try {
            $router->dispatch(new Update([]));
            $router->dispatch(new Update(['update_id' => 'not-a-number', 'message' => 'not-an-array']));
        } catch (\Throwable $e) {
            $threw = true;
        }

        ok('dispatch() swallows malformed updates', !$threw);
    }

    /* =====================================================================
     | BroadcastService
     ===================================================================== */

    private function broadcastSuite(): void
    {
        $service = new BroadcastService($this->app);
        $repository = $this->app->broadcasts();
        $users = $this->app->users();
        $registrations = $this->app->registrations();

        // Three recipients with an application each.
        $recipients = [3330001, 3330002, 3330003];

        foreach ($recipients as $index => $telegramId) {
            $row = $users->touch(['id' => $telegramId, 'first_name' => 'Talab ' . $index], 'private');
            $registrations->save((int) $row['id'], $telegramId, [
                'full_name' => 'Talaba Nomi' . $index,
                'phone' => '+99890000000' . $index,
                'birth_year' => 2003,
                'district' => 'shahrixon',
                'directions' => ['content'],
                'status' => 'pending',
            ]);
        }

        $audience = $service->audience(['district' => 'shahrixon']);
        eq(3, count($audience), 'the audience filter finds the three recipients');
        eq(3, $service->audienceCount(['district' => 'shahrixon']), 'audienceCount() agrees with audience()');

        throws(
            static fn () => $service->prepare(null, '   '),
            InvalidArgumentException::class,
            'prepare() refuses an empty message'
        );

        $this->fake->reset();
        $broadcastId = $service->prepare(self::ADMIN_ID, 'Assalomu alaykum, <b>tanlov</b> boshlandi!', ['district' => 'shahrixon']);
        ok('prepare() created a broadcast', $broadcastId > 0);

        $broadcast = $repository->find($broadcastId);
        eq('running', $broadcast['status'] ?? null, 'a prepared broadcast is running');
        eq(3, (int) ($broadcast['total'] ?? 0), 'the recipient count was frozen');
        eq(3, $repository->countTargets($broadcastId, 'pending'), 'every recipient is queued');

        $progress = $service->progress($broadcastId);

        foreach (['total', 'sent', 'failed', 'remaining', 'percent', 'status'] as $key) {
            ok('progress() exposes "' . $key . '"', array_key_exists($key, $progress));
        }

        // First batch: two recipients.
        $first = $service->runBatch($broadcastId, 2);
        eq(2, $first['sent'], 'the first batch delivered two messages');
        eq(0, $first['failed'], 'nothing failed in the first batch');
        eq(1, $first['remaining'], 'one recipient is still queued');
        eq(false, $first['done'], 'the campaign is not finished yet');
        eq(2, $this->fake->count('sendMessage'), 'exactly two messages left the building');

        $sentTo = [];

        foreach ($this->fake->callsOf('sendMessage') as $call) {
            $sentTo[] = (int) ($call['params']['chat_id'] ?? 0);
            eq('HTML', $call['params']['parse_mode'] ?? null, 'a broadcast message is sent as HTML');
        }

        eq(2, count(array_unique($sentTo)), 'each recipient was written to once');

        // Second batch: the last recipient is blocked.
        $this->fake->reset();
        $this->fake->pushError(403, 'Forbidden: bot was blocked by the user');

        $second = $service->runBatch($broadcastId, 10);
        eq(0, $second['sent'], 'the blocked recipient does not count as sent');
        eq(1, $second['failed'], 'the blocked recipient is recorded as failed');
        eq(0, $second['remaining'], 'the queue is empty afterwards');
        eq(true, $second['done'], 'the campaign is done');

        $broadcast = $repository->find($broadcastId);
        eq('done', $broadcast['status'] ?? null, 'a finished campaign is marked done');
        eq(2, (int) ($broadcast['sent'] ?? 0), 'the counters recorded two deliveries');
        eq(1, (int) ($broadcast['failed'] ?? 0), 'the counters recorded one failure');
        ok('the blocked recipient was flagged in the users table', $users->findByTelegramId(3330003) !== null);

        $blockedFlag = 0;

        foreach ($recipients as $telegramId) {
            $blockedFlag += (int) ($users->findByTelegramId($telegramId)['is_blocked'] ?? 0);
        }

        eq(1, $blockedFlag, 'exactly one recipient was marked as blocked');

        // Running a finished campaign again is a no-op.
        $this->fake->reset();
        $third = $service->runBatch($broadcastId, 10);
        eq(true, $third['done'], 'a finished campaign stays done');
        eq(0, $this->fake->count('sendMessage'), 'a finished campaign sends nothing more');

        // Cancelling a campaign stops it.
        $this->fake->reset();
        $cancelId = $service->prepare(self::ADMIN_ID, 'Ikkinchi xabar', ['district' => 'shahrixon']);
        $service->cancel($cancelId);
        $cancelled = $service->runBatch($cancelId, 10);
        eq(true, $cancelled['done'], 'a cancelled campaign refuses to run');
        eq(0, $this->fake->count('sendMessage'), 'a cancelled campaign sends nothing');

        $progress = $service->progress($cancelId);
        ok('the cancelled campaign reports a non-running status', (string) $progress['status'] !== 'running');

        ok('paginate() lists the campaigns', count($repository->paginate(10, 0)) >= 2);
        ok('countAll() counts the campaigns', $repository->countAll() >= 2);
        ok('deleteById() removes a campaign', $repository->deleteById($cancelId));
        eq(0, $repository->countTargets($cancelId), 'deleting a campaign removes its targets');

        // An empty audience still produces a (finished) campaign.
        $emptyId = $service->prepare(self::ADMIN_ID, 'Hech kimga', ['district' => 'a_district_that_does_not_exist']);
        eq('done', $repository->find($emptyId)['status'] ?? null, 'a campaign without recipients goes straight to done');
    }

    /* =====================================================================
     | Admin panel — the harness drives admin/index.php itself
     |======================================================================
     | The bot half of the product was covered from the first day; the panel
     | was not, and three "Array to string conversion" warnings shipped on
     | ordinary, reachable panel screens while this file stayed green. These
     | suites close that hole: every page is rendered and every controller
     | action is executed through the real front controller, under the very
     | error handler that decides the verdict at the end of the run.
     |
     | Two mechanisms are used, and the reason for the split is `exit`.
     |
     |  1. IN PROCESS — everything that returns normally (all page renders).
     |     `admin/index.php` is simply required with $_GET/$_POST/$_SESSION
     |     prepared by hand and the output captured with ob_start(). This is
     |     the fast path, it shares the harness's error handler, and because
     |     nothing has been written to the real output yet (the runner prints
     |     through fwrite(STDOUT), which does not count as output), headers are
     |     "not sent" and http_response_code() reports the status the panel
     |     really chose.
     |
     |  2. IN A CHILD PROCESS — everything that ends in `exit`. Every mutating
     |     action answers with Request::redirect() or Request::json(), both of
     |     them declared `never`, so an in-process call would take the whole
     |     test run down with it. Those cases are therefore handed to a small
     |     generated worker (tests/tmp/panel/worker.php) through proc_open():
     |     the child installs the same error handler, runs exactly one request
     |     and writes a JSON report — output, status, diagnostics, the session
     |     it ended with and the Telegram calls it made — from a shutdown
     |     function, which `exit` cannot skip. The parent reads that report,
     |     asserts on it and feeds the child's diagnostics into the run-wide
     |     collector, so a warning raised inside a child fails the build in the
     |     closing "PHP diagnostics" suite exactly like an in-process one.
     |
     |     The child deliberately writes one byte to the real output before it
     |     dispatches. That makes headers_sent() true, which is what makes
     |     Request::redirect() print its target instead of sending a Location
     |     header the CLI would silently discard — the redirect target is the
     |     interesting half of a mutating action's answer. Cases that care
     |     about the status code instead (JSON endpoints, 404, 419) ask for the
     |     opposite by leaving `sent` unset.
     |
     | Sessions: session_start() cannot run twice and Auth::start() refuses to
     | run at all under CLI, so the harness starts exactly one session for the
     | whole run — cookie-less, with its files inside tests/tmp — signs in once
     | through the real Auth::attempt(), and keeps the resulting $_SESSION as a
     | snapshot. Every later request (in process and in every child) starts
     | from a copy of that snapshot, which gives each case a fresh flash bag,
     | a fresh old-input bag and the same CSRF token without ever logging in
     | again. The login and logout screens are exercised for real on top of it.
     ===================================================================== */

    /* ---------------------------------------------------------------------
     | Pages
     */

    private function panelPageSuite(): void
    {
        $this->panelBoot();

        $registration = $this->panelIds['pending'] ?? 0;

        $pages = [
            'dashboard'     => [['p' => 'dashboard'], ['panel-i18n', 'stat__value', '<svg']],
            'registrations' => [['p' => 'registrations'], ['Panel Pending', '<table']],
            'registration'  => [['p' => 'registration', 'id' => $registration], ['Panel Pending', 'tg://user?id=']],
            'users'         => [['p' => 'users'], ['name="blocked"', 'Panel Blocked', '<table']],
            'broadcast'     => [['p' => 'broadcast'], ['name="text"', 'data-audience']],
            'broadcasts'    => [['p' => 'broadcasts'], ['<table']],
            'settings'      => [['p' => 'settings'], ['name="required_channel"', 'name="welcome_extra"']],
            'logs'          => [['p' => 'logs'], ['panel harness log line']],
            'audit'         => [['p' => 'audit'], ['panel.login.success']],
            'export'        => [['p' => 'export'], ['a=download']],
        ];

        foreach ($pages as $page => $expectation) {
            [$query, $needles] = $expectation;

            $this->panelPage('?p=' . $page, $query, $needles);
        }

        // The login screen is only reachable while nobody is signed in: with a
        // session it redirects, which is a child-process case of its own.
        $anonymous = $this->panelSession;
        unset($anonymous[Auth::KEY_AUTH]);

        $this->panelPage('?p=login (signed out)', ['p' => 'login'], ['name="username"', 'name="password"', '_token'], $anonymous);

        // A pager that really pages: the fixtures are wider than one page.
        $this->panelPage('?p=registrations&per_page=25&page=2', ['p' => 'registrations', 'per_page' => '25', 'page' => '2'], []);
        $this->panelPage('?p=users&sort=telegram_id&dir=asc', ['p' => 'users', 'sort' => 'telegram_id', 'dir' => 'asc'], []);
        $this->panelPage('?p=audit&per_page=100', ['p' => 'audit', 'per_page' => '100'], []);
        $this->panelPage('?p=logs&level=info&lines=500', ['p' => 'logs', 'level' => 'info', 'lines' => '500'], []);
        $this->panelPage(
            '?p=registrations (filtered)',
            [
                'p'         => 'registrations',
                'q'         => 'Panel',
                'status'    => 'approved',
                'district'  => 'asaka',
                'direction' => 'ai_ml',
                'date_from' => '2000-01-01',
                'date_to'   => date('Y-m-d'),
                'sort'      => 'full_name',
                'dir'       => 'asc',
            ],
            []
        );
        $this->panelPage(
            '?p=broadcast (filtered audience)',
            ['p' => 'broadcast', 'audience' => 'district', 'district' => 'andijon_city'],
            []
        );

        note($this->panelRequests . ' panel requests driven so far');
    }

    /* ---------------------------------------------------------------------
     | Hostile input
     */

    private function panelHostileSuite(): void
    {
        $this->panelBoot();

        $db = $this->app->db();
        $before = [
            'users'         => $db->count('users'),
            'registrations' => $db->count('registrations'),
            'settings'      => $db->count('settings'),
            'broadcasts'    => $db->count('broadcasts'),
        ];
        $schema = $this->panelSchema();

        /**
         * One entry per hostile shape. Every key the panel reads appears at
         * least once as an array, because that is the exact shape that shipped
         * three "Array to string conversion" warnings past this file.
         */
        $variants = [
            'array values' => array_fill_keys(
                [
                    'q', 'status', 'district', 'direction', 'date_from', 'date_to', 'sort', 'dir',
                    'page', 'per_page', 'id', 'tid', 'lang', 'level', 'date', 'lines', 'actor',
                    'action', 'target', 'ip', 'audience', 'blocked', 'registered', 'locale',
                    'admin', 'batch', 'days', 'format', 'json', 'text', 'note', 'ids', 'bulk', 'url',
                ],
                ['injected']
            ),
            'nested arrays'       => ['q' => ['a' => ['b' => ['c' => ['d' => 'deep']]]], 'status' => [['x']], 'sort' => [[[['y']]]]],
            'page out of range'   => ['page' => '99999999', 'per_page' => '1000000', 'lines' => '999999', 'days' => '999999'],
            'negative ids'        => ['id' => '-5', 'tid' => '-12345', 'page' => '-3', 'per_page' => '-1', 'days' => '-90'],
            'non numeric ids'     => ['id' => 'abc', 'tid' => '1e3', 'page' => '2abc', 'per_page' => '25.0', 'days' => 'yesterday'],
            'unknown sort column' => ['sort' => 'id) UNION SELECT password_hash FROM users --', 'dir' => 'RANDOM()'],
            'traversal dates'     => ['date_from' => '../../../etc/passwd', 'date_to' => '2026-13-45', 'date' => '../../../../etc/passwd'],
            'oversized search'    => ['q' => str_repeat('ぁ', 4000), 'actor' => str_repeat('z', 5000)],
            'control characters'  => ['q' => "a\0b\x1fc", 'status' => "pending\0", 'district' => "asaka\r\nSet-Cookie: x=1"],
            'quotes and markup'   => ['q' => '"><script>alert(1)</script>', 'actor' => "' OR '1'='1", 'level' => '<img src=x>'],
        ];

        $mark = count(TestRunner::instance()->diagnostics());
        $broken = [];
        $requests = 0;

        foreach (self::PANEL_PAGES as $page) {
            foreach ($variants as $name => $query) {
                $query['p'] = $page;

                // The detail screen redirects (and therefore exits) when the id
                // does not resolve; hostile ids for it are child cases below.
                if ($page === 'registration') {
                    $query['id'] = $this->panelIds['pending'] ?? 0;
                }

                $result = $this->panelRender($query);
                $requests++;

                if ($result['error'] !== null) {
                    $broken[] = '?p=' . $page . ' [' . $name . ']: ' . $result['error'];

                    continue;
                }

                if ($result['code'] !== 200) {
                    $broken[] = '?p=' . $page . ' [' . $name . ']: HTTP ' . $result['code'];

                    continue;
                }

                if (strlen($result['html']) < 2000) {
                    $broken[] = '?p=' . $page . ' [' . $name . ']: ' . strlen($result['html']) . ' bytes only';

                    continue;
                }

                // Reflected markup would be a stored/reflected XSS on the panel.
                if (str_contains($result['html'], '<script>alert(1)</script>')) {
                    $broken[] = '?p=' . $page . ' [' . $name . ']: reflected unescaped markup';
                }
            }
        }

        note($requests . ' hostile requests across ' . count(self::PANEL_PAGES) . ' pages');

        eq([], $broken, 'every page survives every hostile parameter shape');
        $this->panelQuiet($mark, 'the hostile-input pass');

        // Nothing reached SQL: the schema and every row count are untouched.
        eq($schema, $this->panelSchema(), 'the hostile pass did not change the database schema');

        foreach ($before as $table => $count) {
            eq($count, $db->count($table), 'the hostile pass left "' . $table . '" untouched (' . $count . ' rows)');
        }

        ok('the users table is still queryable afterwards', $db->fetchAll('SELECT 1 FROM ' . $db->quoteIdent('users') . ' LIMIT 1') !== null);
    }

    /* ---------------------------------------------------------------------
     | Pathological database rows
     */

    private function panelPathologicalSuite(): void
    {
        $this->panelBoot();

        $db = $this->app->db();
        $now = App::now();
        $telegramId = self::PANEL_BASE_ID + 900;

        // A user whose every nullable column is NULL and whose state_data is
        // not JSON at all.
        $db->query(
            'INSERT INTO ' . $db->quoteIdent('users')
            . ' (telegram_id, username, first_name, last_name, locale, state, state_data,'
            . ' is_admin, is_blocked, last_seen_at, created_at, updated_at)'
            . " VALUES (?, NULL, NULL, NULL, 'zz', 'reg:???', ?, 0, 0, NULL, ?, ?)",
            [$telegramId, '{not json at all', $now, $now]
        );

        $userId = (int) $db->fetchColumn(
            'SELECT ' . $db->quoteIdent('id') . ' FROM ' . $db->quoteIdent('users')
            . ' WHERE ' . $db->quoteIdent('telegram_id') . ' = ?',
            [$telegramId]
        );

        // An application with broken JSON, unknown catalogue keys, an unknown
        // status, an over-long name and a very long portfolio.
        $db->query(
            'INSERT INTO ' . $db->quoteIdent('registrations')
            . ' (user_id, telegram_id, full_name, phone, birth_year, district, directions,'
            . ' direction_other, portfolio, portfolio_links, status, admin_note, reviewed_by,'
            . ' reviewed_at, source, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, NULL, ?, ?, NULL, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?)',
            [
                $userId,
                $telegramId,
                str_repeat('Ў', 400),
                '',
                'a_district_that_is_not_in_the_catalogue',
                '{"broken": ',
                str_repeat("very long portfolio line\n", 400),
                'not json either',
                'archived',
                'import',
                $now,
                $now,
            ]
        );

        $pathologicalId = (int) $db->fetchColumn(
            'SELECT ' . $db->quoteIdent('id') . ' FROM ' . $db->quoteIdent('registrations')
            . ' WHERE ' . $db->quoteIdent('telegram_id') . ' = ?',
            [$telegramId]
        );

        ok('the pathological application was inserted', $pathologicalId > 0);

        // A campaign whose `filters` is broken JSON, and one whose audience
        // filter holds a LIST where the history screen expects a string. The
        // latter is the exact row shape that raised "Array to string
        // conversion" once per row on every view of ?p=broadcasts.
        $brokenJsonCampaign = $db->insert('broadcasts', [
            'admin_id'   => null,
            'text'       => 'Nosoz filtrli kampaniya',
            'parse_mode' => 'HTML',
            'filters'    => '{"audience": "status", ',
            'status'     => 'failed',
            'total'      => 0,
            'sent'       => 0,
            'failed'     => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $listFilterCampaign = $db->insert('broadcasts', [
            'admin_id'   => null,
            'text'       => "Ro'yxatdan o'tganlarga",
            'parse_mode' => 'HTML',
            'filters'    => (string) json_encode([
                'audience'  => 'status',
                'status'    => ['pending', 'approved'],
                'district'  => ['asaka'],
                'direction' => ['web', 'ai_ml'],
            ]),
            'status'      => 'done',
            'total'       => 3,
            'sent'        => 2,
            'failed'      => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
            'finished_at' => $now,
        ]);

        // An audit row whose meta column is not JSON, and one with NULLs.
        $db->insert('audit_log', [
            'actor'      => 'panel:admin',
            'action'     => 'harness.pathological',
            'target'     => 'registration:' . $pathologicalId,
            'meta'       => '{"unterminated": ',
            'ip'         => null,
            'created_at' => $now,
        ]);

        $db->insert('audit_log', [
            'actor'      => 'tg:0',
            'action'     => 'harness.nulls',
            'target'     => null,
            'meta'       => null,
            'ip'         => null,
            'created_at' => $now,
        ]);

        // A settings row holding invalid JSON must not take the screen down.
        // The repository caches its rows, so the raw INSERT needs a flush to
        // be seen at all — otherwise the pass would prove nothing.
        $db->insert('settings', ['name' => 'welcome_extra', 'value' => '{"broken"', 'updated_at' => $now]);
        $this->app->settings()->flush();

        $mark = count(TestRunner::instance()->diagnostics());
        $broken = [];

        foreach (self::PANEL_PAGES as $page) {
            $query = ['p' => $page];

            if ($page === 'registration') {
                $query['id'] = $pathologicalId;
            }

            $result = $this->panelRender($query);

            if ($result['error'] !== null) {
                $broken[] = '?p=' . $page . ': ' . $result['error'];

                continue;
            }

            if ($result['code'] !== 200 || strlen($result['html']) < 2000) {
                $broken[] = '?p=' . $page . ': HTTP ' . $result['code'] . ', ' . strlen($result['html']) . ' bytes';

                continue;
            }

            $this->panelMarkup('?p=' . $page . ' (pathological)', $result['html']);
        }

        eq([], $broken, 'every page renders against the pathological database');
        $this->panelQuiet($mark, 'the pathological-data pass');

        // The pass only proves something if the broken rows were really on the
        // screens: a campaign that fell off page one tests nothing at all.
        $history = $this->panelRender(['p' => 'broadcasts']);
        ok('the campaign with the broken filter JSON is on the history screen', str_contains($history['html'], 'Nosoz filtrli kampaniya'));
        ok('the campaign with list-shaped filters is on the history screen', str_contains($history['html'], "Ro&#039;yxatdan o&#039;tganlarga"));

        // The detail screen really did show the broken row rather than a stub.
        $detail = $this->panelRender(['p' => 'registration', 'id' => $pathologicalId]);
        ok('the pathological application is shown, not skipped', str_contains($detail['html'], 'Ў'));
        ok(
            'no array ever reaches the markup as the literal string "Array"',
            !str_contains($detail['html'], '>Array<') && !str_contains($detail['html'], '"Array"')
        );
        ok(
            'the unknown status value does not break the badge',
            str_contains($detail['html'], 'badge')
        );

        // Clean up so the later suites work on the deliberate fixtures only.
        $db->delete('registrations', ['id' => $pathologicalId]);
        $db->delete('users', ['telegram_id' => $telegramId]);
        $db->delete('settings', ['name' => 'welcome_extra']);
        $db->delete('audit_log', ['action' => 'harness.pathological']);
        $db->delete('audit_log', ['action' => 'harness.nulls']);
        $db->query(
            'DELETE FROM ' . $db->quoteIdent('broadcasts') . ' WHERE ' . $db->quoteIdent('id') . ' IN (?, ?)',
            [$brokenJsonCampaign, $listFilterCampaign]
        );
        $this->app->settings()->flush();
    }

    /* ---------------------------------------------------------------------
     | Controller actions (child processes)
     */

    private function panelActionSuite(): void
    {
        $this->panelBoot();

        $registrations = $this->app->registrations();
        $users = $this->app->users();
        $ids = $this->panelIds;

        /* -- routing, authentication and CSRF ---------------------------- */

        $guard = $this->panelRun('an anonymous request', [
            'get'     => ['p' => 'dashboard'],
            'session' => [],
            'sent'    => true,
        ]);
        ok('an anonymous request is sent to the login screen', str_contains($guard['output'], 'p=login'));

        $notFound = $this->panelRun('?p=does_not_exist', ['get' => ['p' => 'does_not_exist']]);
        eq(404, $notFound['status'], 'an unknown page answers 404');
        ok('the 404 page renders the error template', str_contains($notFound['output'], 'badge--warn'));
        ok('the 404 page is a complete document', str_contains($notFound['output'], '</html>'));
        $this->panelMarkup('the 404 page', $notFound['output']);

        $csrf = $this->panelRun('a POST with a stale CSRF token', [
            'method' => 'POST',
            'get'    => ['p' => 'users', 'a' => 'block'],
            'post'   => ['tid' => (string) ($ids['user'] ?? 0)],
            'csrf'   => 'invalid',
        ]);
        eq(419, $csrf['status'], 'a stale CSRF token answers 419');
        ok('the 419 page explains itself', str_contains($csrf['output'], '419'));

        $login = $this->panelRun('?p=login (correct credentials)', [
            'method'  => 'POST',
            'get'     => ['p' => 'login'],
            'post'    => ['username' => self::PANEL_USER, 'password' => self::PANEL_PASSWORD],
            'session' => [],
            'sent'    => true,
        ]);
        ok('a correct login lands on the dashboard', str_contains($login['output'], 'p=dashboard'));
        ok('a correct login fills the session', isset($login['session'][Auth::KEY_AUTH]['user']));

        $badLogin = $this->panelRun('?p=login (wrong password)', [
            'method'  => 'POST',
            'get'     => ['p' => 'login'],
            'post'    => ['username' => self::PANEL_USER, 'password' => 'definitely-not-it'],
            'session' => [],
        ]);
        eq(200, $badLogin['status'], 'a refused login re-renders the form');
        ok('a refused login does not open a session', !isset($badLogin['session'][Auth::KEY_AUTH]));
        ok('a refused login does not say which half was wrong', !str_contains(strtolower($badLogin['output']), 'password is'));

        $loginWhenIn = $this->panelRun('?p=login (already signed in)', ['get' => ['p' => 'login'], 'sent' => true]);
        ok('the login screen redirects a signed-in operator', str_contains($loginWhenIn['output'], 'p=dashboard'));

        $logoutGet = $this->panelRun('?p=logout over GET', ['get' => ['p' => 'logout'], 'sent' => true]);
        ok('GET logout is refused (forced-logout hole)', str_contains($logoutGet['output'], 'p=dashboard'));

        $logout = $this->panelRun('?p=logout over POST', [
            'method' => 'POST',
            'get'    => ['p' => 'logout'],
            'sent'   => true,
        ]);
        ok('POST logout returns to the login screen', str_contains($logout['output'], 'p=login'));
        ok('POST logout empties the identity', !isset($logout['session'][Auth::KEY_AUTH]));

        $search = $this->panelRun('?p=dashboard&a=search', [
            'get'  => ['p' => 'dashboard', 'a' => 'search', 'q' => 'Panel'],
            'sent' => true,
        ]);
        ok('the quick search forwards to the list', str_contains($search['output'], 'p=registrations'));
        ok('the quick search carries the term', str_contains($search['output'], 'q=Panel'));

        $emptySearch = $this->panelRun('?p=dashboard&a=search (empty)', [
            'get'  => ['p' => 'dashboard', 'a' => 'search', 'q' => '   '],
            'sent' => true,
        ]);
        ok('an empty quick search just opens the list', !str_contains($emptySearch['output'], 'q='));

        /* -- registrations ----------------------------------------------- */

        foreach (['missing' => ['id' => '999999'], 'array' => ['id' => ['1']], 'negative' => ['id' => '-1']] as $shape => $query) {
            $detail = $this->panelRun('?p=registration with an ' . $shape . ' id', [
                'get'  => array_merge(['p' => 'registration'], $query),
                'sent' => true,
            ]);
            ok('a registration detail with an ' . $shape . ' id redirects to the list', str_contains($detail['output'], 'p=registrations'));
        }

        $approve = $this->panelRun('?p=registrations&a=approve', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'approve'],
            'post'   => ['id' => (string) $ids['approve'], 'note' => 'Tasdiqlandi (harness)'],
            'sent'   => true,
        ]);
        ok('approve redirects back to the list', str_contains($approve['output'], 'p=registrations'));
        eq('approved', (string) ($registrations->findById($ids['approve'])['status'] ?? ''), 'approve writes the new status');
        eq('Tasdiqlandi (harness)', (string) ($registrations->findById($ids['approve'])['admin_note'] ?? ''), 'approve stores the note');
        ok('approve notifies the applicant through the bot', in_array('sendMessage', $approve['calls'], true));

        $reject = $this->panelRun('?p=registrations&a=reject', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'reject'],
            'post'   => ['id' => (string) $ids['reject']],
            'sent'   => true,
        ]);
        ok('reject redirects back to the list', str_contains($reject['output'], 'p=registrations'));
        eq('rejected', (string) ($registrations->findById($ids['reject'])['status'] ?? ''), 'reject writes the new status');

        $approveGet = $this->panelRun('?p=registrations&a=approve over GET', [
            'get'  => ['p' => 'registrations', 'a' => 'approve', 'id' => (string) $ids['note']],
            'sent' => true,
        ]);
        ok('a moderation action refuses GET', str_contains($approveGet['output'], 'p=registrations'));
        eq('pending', (string) ($registrations->findById($ids['note'])['status'] ?? ''), 'the GET attempt changed nothing');

        $note = $this->panelRun('?p=registration&a=note', [
            'method' => 'POST',
            'get'    => ['p' => 'registration', 'a' => 'note', 'id' => (string) $ids['note']],
            'post'   => ['id' => (string) $ids['note'], 'note' => "Ichki izoh\nikkinchi qator"],
            'sent'   => true,
        ]);
        ok('saving a note returns to the detail screen', str_contains($note['output'], 'p=registration'));
        ok('the note is stored', str_contains((string) ($registrations->findById($ids['note'])['admin_note'] ?? ''), 'Ichki izoh'));

        $noteStatus = $this->panelRun('?p=registration&a=note (with a status change)', [
            'method' => 'POST',
            'get'    => ['p' => 'registration', 'a' => 'note', 'id' => (string) $ids['note']],
            'post'   => ['id' => (string) $ids['note'], 'status' => 'approved', 'note' => 'Status bilan'],
            'sent'   => true,
        ]);
        ok('the status form redirects back', str_contains($noteStatus['output'], 'p=registration'));
        eq('approved', (string) ($registrations->findById($ids['note'])['status'] ?? ''), 'the status form changes the status');

        $message = $this->panelRun('?p=registration&a=message', [
            'method' => 'POST',
            'get'    => ['p' => 'registration', 'a' => 'message', 'id' => (string) $ids['message']],
            'post'   => ['id' => (string) $ids['message'], 'message' => "Salom <b>Ali</b>\nyangilik bor"],
            'sent'   => true,
        ]);
        ok('sending a message returns to the detail screen', str_contains($message['output'], 'p=registration'));
        ok('the message really went through the bot', in_array('sendMessage', $message['calls'], true));

        $emptyMessage = $this->panelRun('?p=registration&a=message (empty)', [
            'method' => 'POST',
            'get'    => ['p' => 'registration', 'a' => 'message', 'id' => (string) $ids['message']],
            'post'   => ['id' => (string) $ids['message'], 'message' => "   \n  "],
            'sent'   => true,
        ]);
        ok('an empty message is refused', !in_array('sendMessage', $emptyMessage['calls'], true));

        $arrayMessage = $this->panelRun('?p=registration&a=message (array body)', [
            'method' => 'POST',
            'get'    => ['p' => 'registration', 'a' => 'message', 'id' => (string) $ids['message']],
            'post'   => ['id' => (string) $ids['message'], 'message' => ['injected']],
            'sent'   => true,
        ]);
        ok('an array message body is refused, not stringified', !in_array('sendMessage', $arrayMessage['calls'], true));

        $bulkApprove = $this->panelRun('?p=registrations&a=bulk (approve)', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'bulk'],
            'post'   => ['bulk' => 'approve', 'ids' => [(string) $ids['bulk_a'], (string) $ids['bulk_b'], 'not-an-id', ['nested']]],
            'sent'   => true,
        ]);
        ok('a bulk approve redirects back to the list', str_contains($bulkApprove['output'], 'p=registrations'));
        eq('approved', (string) ($registrations->findById($ids['bulk_a'])['status'] ?? ''), 'the first bulk row is approved');
        eq('approved', (string) ($registrations->findById($ids['bulk_b'])['status'] ?? ''), 'the second bulk row is approved');

        $bulkReject = $this->panelRun('?p=registrations&a=bulk (reject)', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'bulk'],
            'post'   => ['bulk' => 'reject', 'ids' => [(string) $ids['bulk_b']]],
            'sent'   => true,
        ]);
        eq('rejected', (string) ($registrations->findById($ids['bulk_b'])['status'] ?? ''), 'a bulk reject writes the status');

        $bulkNone = $this->panelRun('?p=registrations&a=bulk (nothing selected)', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'bulk'],
            'post'   => ['bulk' => 'approve', 'ids' => []],
            'sent'   => true,
        ]);
        ok('an empty bulk selection is refused with a message', str_contains($bulkNone['output'], 'p=registrations'));

        $bulkUnknown = $this->panelRun('?p=registrations&a=bulk (unknown operation)', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'bulk'],
            'post'   => ['bulk' => 'drop-table', 'ids' => [(string) $ids['bulk_a']]],
            'sent'   => true,
        ]);
        ok('an unknown bulk operation is refused', str_contains($bulkUnknown['output'], 'p=registrations'));
        eq('approved', (string) ($registrations->findById($ids['bulk_a'])['status'] ?? ''), 'the refused bulk operation changed nothing');

        $bulkDelete = $this->panelRun('?p=registrations&a=bulk (delete)', [
            'method' => 'POST',
            'get'    => ['p' => 'registrations', 'a' => 'bulk'],
            'post'   => ['bulk' => 'delete', 'ids' => [(string) $ids['bulk_c']]],
            'sent'   => true,
        ]);
        ok('a bulk delete redirects back to the list', str_contains($bulkDelete['output'], 'p=registrations'));
        eq(null, $registrations->findById($ids['bulk_c']), 'the bulk deleted application is gone');

        $delete = $this->panelRun('?p=registration&a=delete', [
            'method' => 'POST',
            'get'    => ['p' => 'registration', 'a' => 'delete', 'id' => (string) $ids['delete']],
            'post'   => ['id' => (string) $ids['delete']],
            'sent'   => true,
        ]);
        ok('deleting an application returns to the list, never to the detail screen', str_contains($delete['output'], 'p=registrations'));
        ok('the deleted detail screen is not offered again', !str_contains($delete['output'], 'p=registration&'));
        eq(null, $registrations->findById($ids['delete']), 'the deleted application is gone');

        /* -- users -------------------------------------------------------- */

        $block = $this->panelRun('?p=users&a=block', [
            'method' => 'POST',
            'get'    => ['p' => 'users', 'a' => 'block'],
            'post'   => ['tid' => (string) $ids['user']],
            'sent'   => true,
        ]);
        ok('blocking redirects back to the user list', str_contains($block['output'], 'p=users'));
        eq(1, (int) ($users->findByTelegramId($ids['user'])['is_blocked'] ?? 0), 'the user is blocked');

        $unblock = $this->panelRun('?p=users&a=unblock', [
            'method' => 'POST',
            'get'    => ['p' => 'users', 'a' => 'unblock'],
            'post'   => ['tid' => (string) $ids['user']],
            'sent'   => true,
        ]);
        ok('unblocking redirects back to the user list', str_contains($unblock['output'], 'p=users'));
        eq(0, (int) ($users->findByTelegramId($ids['user'])['is_blocked'] ?? 1), 'the user is unblocked again');

        $grant = $this->panelRun('?p=users&a=admin', [
            'method' => 'POST',
            'get'    => ['p' => 'users', 'a' => 'admin'],
            'post'   => ['tid' => (string) $ids['user']],
            'sent'   => true,
        ]);
        ok('granting the admin flag redirects back', str_contains($grant['output'], 'p=users'));
        eq(1, (int) ($users->findByTelegramId($ids['user'])['is_admin'] ?? 0), 'the bot admin flag is set');

        $revoke = $this->panelRun('?p=users&a=revoke', [
            'method' => 'POST',
            'get'    => ['p' => 'users', 'a' => 'revoke'],
            'post'   => ['tid' => (string) $ids['user']],
            'sent'   => true,
        ]);
        ok('revoking the admin flag redirects back', str_contains($revoke['output'], 'p=users'));
        eq(0, (int) ($users->findByTelegramId($ids['user'])['is_admin'] ?? 1), 'the bot admin flag is cleared');

        $unknownUser = $this->panelRun('?p=users&a=block (unknown user)', [
            'method' => 'POST',
            'get'    => ['p' => 'users', 'a' => 'block'],
            'post'   => ['tid' => '123456789'],
            'sent'   => true,
        ]);
        ok('blocking an unknown user is refused', str_contains($unknownUser['output'], 'p=users'));

        $blockGet = $this->panelRun('?p=users&a=block over GET', [
            'get'  => ['p' => 'users', 'a' => 'block', 'tid' => (string) $ids['user']],
            'sent' => true,
        ]);
        ok('a user action refuses GET', str_contains($blockGet['output'], 'p=users'));
        eq(0, (int) ($users->findByTelegramId($ids['user'])['is_blocked'] ?? 1), 'the GET attempt changed nothing');

        /* -- settings ----------------------------------------------------- */

        $save = $this->panelRun('?p=settings&a=save', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'save'],
            'post'   => [
                'registration_open' => '1',
                'ask_language'      => 'on',
                'required_channel'  => 'https://t.me/andijon_ai_talents',
                'welcome_extra'     => "Qo'shimcha xabar",
            ],
            'sent' => true,
        ]);
        ok('saving the settings redirects back to the screen', str_contains($save['output'], 'p=settings'));

        // The repository caches its rows for the length of a request; this
        // process has been reading them since the first dashboard render.
        $this->app->settings()->flush();

        eq('@andijon_ai_talents', (string) $this->app->settings()->get('required_channel'), 'the t.me link is normalised to a @username');
        eq("Qo'shimcha xabar", (string) $this->app->settings()->get('welcome_extra'), 'the extra welcome text is stored');
        eq(true, $this->app->settings()->get('registration_open'), 'the registration switch is stored as a boolean');

        $arraySave = $this->panelRun('?p=settings&a=save (array values)', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'save'],
            'post'   => [
                'registration_open' => ['1'],
                'ask_language'      => ['on'],
                'required_channel'  => ['@evil'],
                'welcome_extra'     => ['injected'],
            ],
            'sent' => true,
        ]);
        ok('an array-valued settings POST still redirects', str_contains($arraySave['output'], 'p=settings'));

        $this->app->settings()->flush();

        ok(
            'an array welcome text is never stored as the literal "Array"',
            (string) $this->app->settings()->get('welcome_extra') !== 'Array'
        );
        ok(
            'an array checkbox is never truthy',
            $this->app->settings()->get('registration_open') === false
        );

        $badChannel = $this->panelRun('?p=settings&a=save (impossible channel)', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'save'],
            'post'   => ['required_channel' => '@@@', 'welcome_extra' => 'x'],
            'sent'   => true,
        ]);
        ok('an impossible channel is refused', str_contains($badChannel['output'], 'p=settings'));

        $this->app->settings()->flush();

        ok('the refused channel was not stored', (string) $this->app->settings()->get('required_channel') !== '@@@');

        $saveGet = $this->panelRun('?p=settings&a=save over GET', [
            'get'  => ['p' => 'settings', 'a' => 'save'],
            'sent' => true,
        ]);
        ok('saving the settings refuses GET', str_contains($saveGet['output'], 'p=settings'));

        $webhook = $this->panelRun('?p=settings&a=webhook_set', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'webhook_set'],
            'post'   => ['url' => 'https://tests.invalid/bot/index.php', 'drop_pending' => '1'],
            'queue'  => ['{"ok":true,"result":true,"description":"Webhook was set"}'],
            'sent'   => true,
        ]);
        ok('setting the webhook redirects back', str_contains($webhook['output'], 'p=settings'));
        ok('setWebhook was called', in_array('setWebhook', $webhook['calls'], true));

        $badWebhook = $this->panelRun('?p=settings&a=webhook_set (http url)', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'webhook_set'],
            'post'   => ['url' => 'http://insecure.invalid/hook'],
            'sent'   => true,
        ]);
        ok('a plain-http webhook URL is refused', !in_array('setWebhook', $badWebhook['calls'], true));

        $deleteHook = $this->panelRun('?p=settings&a=webhook_delete', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'webhook_delete'],
            'post'   => ['drop_pending' => '1'],
            'queue'  => ['{"ok":true,"result":true}'],
            'sent'   => true,
        ]);
        ok('deleting the webhook redirects back', str_contains($deleteHook['output'], 'p=settings'));
        ok('deleteWebhook was called', in_array('deleteWebhook', $deleteHook['calls'], true));

        $testBot = $this->panelRun('?p=settings&a=test_bot', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'test_bot'],
            'queue'  => ['{"ok":true,"result":{"id":1,"is_bot":true,"username":"AndijonAiTalentsTestBot"}}'],
            'sent'   => true,
        ]);
        ok('the bot health check redirects back', str_contains($testBot['output'], 'p=settings'));
        ok('getMe was called', in_array('getMe', $testBot['calls'], true));

        $clearLogs = $this->panelRun('?p=settings&a=clear_logs', [
            'method' => 'POST',
            'get'    => ['p' => 'settings', 'a' => 'clear_logs'],
            'sent'   => true,
        ]);
        ok('clearing the logs redirects back', str_contains($clearLogs['output'], 'p=settings'));
        eq([], glob($this->tmpDir . '/logs/bot-*.log') ?: [], 'every rotated log file was removed');

        /* -- broadcasts --------------------------------------------------- */

        $count = $this->panelRun('?p=broadcast&a=count', [
            'get' => ['p' => 'broadcast', 'a' => 'count', 'audience' => 'all', 'format' => 'json'],
        ]);
        eq(200, $count['status'], 'the audience counter answers 200');
        $countBody = json_decode($count['output'], true);
        ok('the audience counter answers JSON', is_array($countBody) && ($countBody['ok'] ?? null) === true);
        ok('the audience counter reports a number', is_int($countBody['count'] ?? null) && $countBody['count'] > 0);

        $start = $this->panelRun('?p=broadcast&a=start', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'start', 'format' => 'json'],
            'post'   => ['text' => 'Harness kampaniyasi', 'audience' => 'registered'],
        ]);
        eq(200, $start['status'], 'starting a campaign answers 200');
        $startBody = json_decode($start['output'], true);
        ok('starting a campaign answers JSON', is_array($startBody) && ($startBody['ok'] ?? null) === true);

        $campaign = (int) ($startBody['id'] ?? 0);
        ok('the campaign was created', $campaign > 0);
        ok('the campaign froze its audience', (int) ($startBody['total'] ?? 0) > 0);

        $emptyText = $this->panelRun('?p=broadcast&a=start (no text)', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'start', 'format' => 'json'],
            'post'   => ['text' => '   ', 'audience' => 'all'],
        ]);
        eq(422, $emptyText['status'], 'a campaign without text answers 422');

        $emptyAudience = $this->panelRun('?p=broadcast&a=start (empty audience)', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'start', 'format' => 'json'],
            'post'   => ['text' => 'Hech kimga', 'audience' => 'district', 'district' => 'ulugnor'],
        ]);
        eq(422, $emptyAudience['status'], 'a campaign without recipients answers 422');

        $runGet = $this->panelRun('?p=broadcast&a=run over GET', [
            'get' => ['p' => 'broadcast', 'a' => 'run', 'id' => (string) $campaign, 'format' => 'json'],
        ]);
        eq(405, $runGet['status'], 'the batch sender refuses GET');

        $runMissing = $this->panelRun('?p=broadcast&a=run (unknown campaign)', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'run', 'id' => '999999', 'format' => 'json'],
        ]);
        eq(404, $runMissing['status'], 'the batch sender answers 404 for an unknown campaign');

        $run = $this->panelRun('?p=broadcast&a=run', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'run', 'id' => (string) $campaign, 'batch' => '2', 'format' => 'json'],
        ]);
        eq(200, $run['status'], 'one batch answers 200');
        $runBody = json_decode($run['output'], true);
        ok('one batch reports its progress', is_array($runBody) && ($runBody['ok'] ?? null) === true);
        ok('one batch actually delivered something', (int) ($runBody['sent'] ?? 0) > 0);
        ok('one batch sent through the bot', in_array('sendMessage', $run['calls'], true));

        $pause = $this->panelRun('?p=broadcast&a=pause', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'pause', 'id' => (string) $campaign, 'format' => 'json'],
        ]);
        eq(200, $pause['status'], 'pausing a campaign answers 200');
        eq('paused', (string) ($this->app->broadcasts()->find($campaign)['status'] ?? ''), 'the campaign is paused');

        $resume = $this->panelRun('?p=broadcast&a=resume', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'resume', 'id' => (string) $campaign, 'format' => 'json'],
        ]);
        eq(200, $resume['status'], 'resuming a campaign answers 200');
        eq('running', (string) ($this->app->broadcasts()->find($campaign)['status'] ?? ''), 'the campaign is running again');

        $cancel = $this->panelRun('?p=broadcast&a=cancel (form)', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'cancel', 'id' => (string) $campaign],
            'sent'   => true,
        ]);
        ok('cancelling from a form redirects to the composer', str_contains($cancel['output'], 'p=broadcast'));

        $test = $this->panelRun('?p=broadcast&a=test', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'test'],
            'post'   => ['text' => "Sinov <b>xabari</b>"],
            'sent'   => true,
        ]);
        ok('the test send redirects back to the composer', str_contains($test['output'], 'p=broadcast'));
        ok('the test send went through the bot', in_array('sendMessage', $test['calls'], true));

        $deleteCampaign = $this->panelRun('?p=broadcast&a=delete', [
            'method' => 'POST',
            'get'    => ['p' => 'broadcast', 'a' => 'delete', 'id' => (string) $campaign, 'format' => 'json'],
        ]);
        eq(200, $deleteCampaign['status'], 'deleting a campaign answers 200');
        eq(null, $this->app->broadcasts()->find($campaign), 'the campaign is gone');
        eq(0, $this->app->broadcasts()->countTargets($campaign), 'its queued recipients went with it');

        /* -- logs, audit and export --------------------------------------- */

        $this->app->logger()->info('panel harness download probe', ['case' => 'download']);
        $logDate = (string) (($this->app->logger()->files()[0]) ?? '');

        $download = $this->panelRun('?p=logs&a=download', [
            'get'  => ['p' => 'logs', 'a' => 'download', 'date' => $logDate],
        ]);
        // LogController::download() unwinds every output buffer before it calls
        // readfile(), so the file lands on the child's real stdout.
        ok('the log download serves the file itself', str_contains($download['stdout'], 'panel harness download probe'));

        $missingLog = $this->panelRun('?p=logs&a=download (no such day)', [
            'get'  => ['p' => 'logs', 'a' => 'download', 'date' => '1999-01-01'],
            'sent' => true,
        ]);
        ok(
            'an unknown log date falls back to the newest file instead of failing',
            str_contains($missingLog['stdout'], 'panel harness download probe')
        );

        $traversalLog = $this->panelRun('?p=logs&a=download (traversal date)', [
            'get'  => ['p' => 'logs', 'a' => 'download', 'date' => '../../../../etc/passwd'],
            'sent' => true,
        ]);
        ok('a traversal-shaped log date never serves a file outside the log directory', !str_contains($traversalLog['stdout'], 'root:'));

        $purgeGet = $this->panelRun('?p=audit&a=purge over GET', [
            'get'  => ['p' => 'audit', 'a' => 'purge', 'days' => '1'],
            'sent' => true,
        ]);
        ok('the audit purge refuses GET', str_contains($purgeGet['output'], 'p=audit'));

        // An entry from a year ago must go, today's must stay.
        $this->app->db()->insert('audit_log', [
            'actor'      => 'panel:' . self::PANEL_USER,
            'action'     => 'harness.ancient',
            'target'     => null,
            'meta'       => null,
            'ip'         => '127.0.0.1',
            'created_at' => date('Y-m-d H:i:s', time() - (400 * 86400)),
        ]);

        $recentBefore = $this->app->audit()->countAll(['date_from' => date('Y-m-d')]);

        $purge = $this->panelRun('?p=audit&a=purge', [
            'method' => 'POST',
            'get'    => ['p' => 'audit', 'a' => 'purge'],
            'post'   => ['days' => '90'],
            'sent'   => true,
        ]);
        ok('the audit purge redirects back to the trail', str_contains($purge['output'], 'p=audit'));
        eq(
            0,
            $this->app->db()->count('audit_log', ['action' => 'harness.ancient']),
            'a 90 day purge removes the year-old entry'
        );
        ok(
            "a 90 day purge keeps today's entries",
            $this->app->audit()->countAll(['date_from' => date('Y-m-d')]) >= $recentBefore
        );

        $export = $this->panelRun('?p=export&a=download', [
            'get' => ['p' => 'export', 'a' => 'download'],
        ]);
        ok('the export answers with a ZIP container (XLSX)', str_starts_with($export['output'], "PK\x03\x04"));
        ok('the export is not an empty file', strlen($export['output']) > 2000);

        $emptyExport = $this->panelRun('?p=export&a=download (filter matches nothing)', [
            'get'  => ['p' => 'export', 'a' => 'download', 'q' => 'zzzz-nothing-matches-this-zzzz'],
            'sent' => true,
        ]);
        ok('an empty export sends the operator back instead of a broken file', str_contains($emptyExport['output'], 'p=registrations'));

        note($this->panelCaseNumber . ' panel requests ran in their own process');
    }

    /* ---------------------------------------------------------------------
     | Empty database
     */

    private function panelEmptySuite(): void
    {
        $this->panelBoot();

        $database = $this->tmpDir . '/panel/empty.sqlite';

        foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $pages = [];

        foreach (self::PANEL_PAGES as $page) {
            // ?p=registration needs a row to show; with none it redirects, and
            // that is asserted by the action suite instead.
            if ($page === 'registration') {
                continue;
            }

            $pages[] = ['label' => '?p=' . $page, 'get' => ['p' => $page]];
        }

        $pages[] = ['label' => '?p=login', 'get' => ['p' => 'login'], 'session' => []];

        $result = $this->panelRun('the empty-database pass', [
            'database' => $database,
            'migrate'  => true,
            'pages'    => $pages,
        ]);

        eq(count($pages), count($result['pages']), 'every page rendered against a migrated but empty database');

        foreach ($result['pages'] as $page) {
            $label = (string) ($page['label'] ?? '?');

            if (($page['error'] ?? null) !== null) {
                ok($label . ' renders its empty state', false);

                continue;
            }

            ok($label . ' answers 200 on an empty database', (int) ($page['code'] ?? 0) === 200);
            ok($label . ' still renders a full document', str_contains((string) $page['html'], '</html>'));

            $this->panelMarkup($label . ' (empty database)', (string) $page['html']);
            $this->panelLangKeys($label . ' (empty database)', (string) $page['html']);
        }

        // A second pass in Russian: panel_locale() caches its answer per
        // process, so the only honest way to render the panel in ru is a fresh
        // one — which is exactly what the child process gives us.
        $russian = [];

        foreach (self::PANEL_PAGES as $page) {
            if ($page === 'registration') {
                continue;
            }

            $russian[] = ['label' => '?p=' . $page . '&lang=ru', 'get' => ['p' => $page, 'lang' => 'ru']];
        }

        $ru = $this->panelRun('the Russian interface pass', ['pages' => $russian]);

        eq(count($russian), count($ru['pages']), 'every page renders in Russian too');

        foreach ($ru['pages'] as $page) {
            $label = (string) ($page['label'] ?? '?');
            $html = (string) $page['html'];

            ok($label . ' answers 200', (int) ($page['code'] ?? 0) === 200);
            ok($label . ' is marked as Russian', str_contains($html, 'lang="ru"'));

            $this->panelMarkup($label, $html);
            $this->panelLangKeys($label, $html);
        }
    }

    /* ---------------------------------------------------------------------
     | The collector that decides the verdict
     */

    private function diagnosticsProbeSuite(): void
    {
        // In process: a deliberate "Array to string conversion" must be seen by
        // the harness's own error handler. probe() keeps it out of the verdict.
        $collected = TestRunner::instance()->probe(static function (): void {
            $array = ['x'];
            $ignored = 'value: ' . $array;
            unset($ignored);
        });

        eq(1, count($collected), 'an in-process PHP warning is caught by the harness error handler');
        ok(
            'the caught warning is the one that was raised',
            isset($collected[0]) && str_contains($collected[0], 'Array to string conversion')
        );

        // In a child process: the same warning must travel back in the report,
        // which is what makes a panel diagnostic fail the build.
        $probe = $this->panelRun('the child-process diagnostics probe', [
            'get'   => ['p' => 'dashboard'],
            'probe' => true,
            'quiet' => true,
        ]);

        eq(1, count($probe['diagnostics']), 'a warning raised inside a child process is reported back');
        ok(
            'the child reports the warning it raised',
            isset($probe['diagnostics'][0]) && str_contains($probe['diagnostics'][0], 'Array to string conversion')
        );

        note('a PHP warning on any panel path fails the run — proven, not assumed');
    }

    /* =====================================================================
     | Admin panel — plumbing
     ===================================================================== */

    /**
     * Start the panel session, sign in for real and seed the fixtures.
     *
     * Runs exactly once; every panel suite calls it so the order of the suites
     * inside all() stays free.
     */
    private function panelBoot(): void
    {
        if ($this->panelReady) {
            return;
        }

        $this->panelReady = true;

        $sessions = $this->tmpDir . '/sessions';

        if (!is_dir($sessions) && !@mkdir($sessions, 0775, true) && !is_dir($sessions)) {
            skip('the admin panel suites', 'cannot create ' . $sessions);

            return;
        }

        // One cookie-less session for the whole run, with its files inside
        // tests/tmp. Auth::start() sees an active session and leaves it alone,
        // which is what makes the panel work under CLI at all.
        @ini_set('session.use_cookies', '0');
        @ini_set('session.cache_limiter', '');
        @ini_set('session.save_path', $sessions);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        ok('the harness opened a session for the panel', session_status() === PHP_SESSION_ACTIVE);

        foreach ($this->panelServer() as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $auth = new Auth($this->app);
        $GLOBALS['AITALENTS_PANEL_AUTH'] = $auth;

        ok('a wrong panel password is refused', !$auth->attempt(self::PANEL_USER, 'not-the-password', '127.0.0.1'));
        ok('an unknown panel user is refused', !$auth->attempt('root', self::PANEL_PASSWORD, '127.0.0.1'));
        ok('the configured panel credentials are accepted', $auth->attempt(self::PANEL_USER, self::PANEL_PASSWORD, '127.0.0.1'));
        eq(self::PANEL_USER, $auth->user(), 'the session carries the administrator name');
        ok('the session passes the authentication check', $auth->check());

        $this->panelSession = $_SESSION;

        ok('the snapshot holds an identity', isset($this->panelSession[Auth::KEY_AUTH]));

        $this->panelSeed();
    }

    /**
     * The `$_SERVER` a panel request runs with, identical in every process.
     *
     * The user agent must stay constant: Auth binds the session to a hash of it.
     *
     * @return array<string,mixed>
     */
    private function panelServer(): array
    {
        return [
            'REQUEST_METHOD'       => 'GET',
            'SCRIPT_NAME'          => '/admin/index.php',
            'PHP_SELF'             => '/admin/index.php',
            'REQUEST_URI'          => '/admin/index.php',
            'QUERY_STRING'         => '',
            'REMOTE_ADDR'          => '127.0.0.1',
            'SERVER_NAME'          => 'tests.invalid',
            'SERVER_PORT'          => 443,
            'HTTPS'                => 'on',
            'HTTP_HOST'            => 'tests.invalid',
            'HTTP_USER_AGENT'      => 'AiTalentsHarness/1.0',
            'HTTP_ACCEPT'          => 'text/html,application/xhtml+xml',
            'HTTP_X_REQUESTED_WITH' => '',
        ];
    }

    /**
     * Realistic content for the panel: an empty table hides formatting bugs.
     */
    private function panelSeed(): void
    {
        $users = $this->app->users();
        $registrations = $this->app->registrations();
        $db = $this->app->db();

        $people = [
            ['name' => 'Panel Pending',  'locale' => 'uz', 'district' => 'asaka',        'directions' => ['ai_ml', 'web'],       'status' => 'pending',  'key' => 'pending'],
            ['name' => 'Panel Approve',  'locale' => 'uz', 'district' => 'andijon_city', 'directions' => ['programming'],        'status' => 'pending',  'key' => 'approve'],
            ['name' => 'Panel Reject',   'locale' => 'ru', 'district' => 'xonobod_city', 'directions' => ['design', 'content'],  'status' => 'pending',  'key' => 'reject'],
            ['name' => 'Panel Note',     'locale' => 'uz', 'district' => 'baliqchi',     'directions' => ['robotics'],           'status' => 'pending',  'key' => 'note'],
            ['name' => 'Panel Message',  'locale' => 'ru', 'district' => 'marhamat',     'directions' => ['data_science'],       'status' => 'approved', 'key' => 'message'],
            ['name' => 'Panel Delete',   'locale' => 'uz', 'district' => 'other',        'directions' => ['other'],              'status' => 'rejected', 'key' => 'delete'],
            ['name' => 'Panel Bulk A',   'locale' => 'uz', 'district' => 'shahrixon',    'directions' => ['mobile'],             'status' => 'pending',  'key' => 'bulk_a'],
            ['name' => 'Panel Bulk B',   'locale' => 'ru', 'district' => 'qorasuv_city', 'directions' => ['cybersecurity'],      'status' => 'pending',  'key' => 'bulk_b'],
            ['name' => 'Panel Bulk C',   'locale' => 'uz', 'district' => 'izboskan',     'directions' => ['game_3d'],            'status' => 'pending',  'key' => 'bulk_c'],
        ];

        $offset = 0;

        foreach ($people as $person) {
            $offset++;
            $telegramId = self::PANEL_BASE_ID + $offset;

            $users->touch([
                'id'            => $telegramId,
                'is_bot'        => false,
                'first_name'    => $person['name'],
                'last_name'     => 'Andijoniy',
                'username'      => 'panel_user_' . $offset,
                'language_code' => $person['locale'],
            ], 'private');

            $id = $registrations->save(0, $telegramId, [
                'full_name'       => $person['name'],
                'phone'           => '+99890' . str_pad((string) (1000000 + $offset), 7, '0', STR_PAD_LEFT),
                'birth_year'      => 1998 + ($offset % 8),
                'district'        => $person['district'],
                'directions'      => $person['directions'],
                'direction_other' => $person['directions'] === ['other'] ? "O'zga yo'nalish" : null,
                'portfolio'       => "Portfolio " . $offset . "\nhttps://github.com/panel" . $offset,
                'portfolio_links' => ['https://github.com/panel' . $offset, 'javascript:alert(1)'],
                'status'          => $person['status'],
                'admin_note'      => $offset % 3 === 0 ? 'Oldingi izoh' : null,
            ]);

            $this->panelIds[$person['key']] = $id;

            // Spread the rows over the trend chart's two weeks.
            $created = date('Y-m-d H:i:s', time() - ($offset * 86400));
            $db->update('registrations', ['created_at' => $created], ['id' => $id]);
        }

        // Users without an application: blocked, admin, other locale.
        foreach ([['blocked', true, false], ['admin', false, true], ['plain', false, false]] as $index => $flavour) {
            [$label, $blocked, $admin] = $flavour;
            $telegramId = self::PANEL_BASE_ID + 100 + $index;

            $users->touch([
                'id'            => $telegramId,
                'is_bot'        => false,
                'first_name'    => 'Panel ' . ucfirst($label),
                'last_name'     => null,
                'username'      => null,
                'language_code' => $index === 2 ? 'ru' : 'uz',
            ], 'private');

            if ($blocked) {
                $users->setBlocked($telegramId, true);
            }

            if ($admin) {
                $users->setAdmin($telegramId, true);
            }

            $this->panelIds[$label] = $telegramId;
        }

        // The user the block/unblock and admin-flag actions work on.
        $this->panelIds['user'] = self::PANEL_BASE_ID + 1;

        // A finished campaign with a target list, so ?p=broadcasts has history.
        $broadcasts = $this->app->broadcasts();
        $campaign = $broadcasts->create(
            self::ADMIN_ID,
            "Andijon AI Talents — birinchi xabar",
            ['audience' => 'registered', 'status' => 'approved']
        );
        $broadcasts->addTargets($campaign, [self::PANEL_BASE_ID + 1, self::PANEL_BASE_ID + 2, self::PANEL_BASE_ID + 3]);
        $broadcasts->setStatus($campaign, 'done');
        $broadcasts->refreshCounters($campaign);
        $this->panelIds['campaign'] = $campaign;

        // Audit rows, so ?p=audit has something to paginate. Only the ones that
        // really belong to an application carry it as their target: the detail
        // screen shows that trail, and an action name such as "export.xlsx"
        // reads exactly like a leaked language key to the markup scanner.
        $trail = [
            ['registration.approve', 'registration:' . ($this->panelIds['pending'] ?? 0)],
            ['registration.note', 'registration:' . ($this->panelIds['pending'] ?? 0)],
            ['user.block', 'user:' . (self::PANEL_BASE_ID + 100)],
            ['settings.save', null],
            ['export.xlsx', null],
        ];

        foreach ($trail as $index => $entry) {
            $this->app->audit()->log(
                'panel:' . self::PANEL_USER,
                $entry[0],
                $entry[1],
                ['seeded' => true, 'n' => $index],
                '127.0.0.1'
            );
        }

        // A log file, so ?p=logs has a day to tail and a file to download.
        $this->app->logger()->info('panel harness log line', ['suite' => 'admin panel']);
        $this->app->logger()->warning('panel harness warning line', ['suite' => 'admin panel']);

        ok('the panel fixtures created applications', count($this->panelIds) >= 9);
        ok('the fixtures cover every status', $this->app->registrations()->countAll(['status' => 'approved']) > 0);
        ok('there is a log file to tail', $this->app->logger()->files() !== []);
        ok('there is an audit trail to paginate', $this->app->audit()->countAll() > 0);
    }

    /**
     * Drive one panel request in this very process and capture its output.
     *
     * Only ever used for requests that return normally — a request that calls
     * exit() would end the whole run, which is why the mutating actions go
     * through panelRun() instead. The $GLOBALS flag makes such a mistake loud:
     * the shutdown handler names the request that killed the run.
     *
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param ?array<string,mixed> $session
     *
     * @return array{html:string,code:int,error:?string}
     */
    private function panelRender(array $get, array $post = [], ?array $session = null): array
    {
        $this->panelRequests++;

        $_SESSION = $session ?? $this->panelSession;
        $_GET = $get;
        $_POST = $post;

        foreach ($this->panelServer() as $key => $value) {
            $_SERVER[$key] = $value;
        }

        $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
        $_SERVER['QUERY_STRING'] = http_build_query($get);
        $_SERVER['REQUEST_URI'] = '/admin/index.php?' . $_SERVER['QUERY_STRING'];

        http_response_code(200);

        $label = is_string($get['p'] ?? null) ? (string) $get['p'] : '(default)';
        $GLOBALS['AITALENTS_PANEL_RENDER'] = '?p=' . $label;

        ob_start();

        try {
            require $this->root . '/admin/index.php';
        } catch (\Throwable $e) {
            ob_end_clean();
            $GLOBALS['AITALENTS_PANEL_RENDER'] = null;

            return [
                'html'  => '',
                'code'  => 0,
                'error' => get_class($e) . ': ' . $e->getMessage()
                    . ' at ' . $this->relativePath($e->getFile()) . ':' . $e->getLine(),
            ];
        }

        $html = (string) ob_get_clean();
        $GLOBALS['AITALENTS_PANEL_RENDER'] = null;

        return ['html' => $html, 'code' => (int) (http_response_code() ?: 0), 'error' => null];
    }

    /**
     * Render one page and run every contract assertion the panel owes.
     *
     * @param array<string,mixed> $get
     * @param string[] $needles fragments the page must contain
     * @param ?array<string,mixed> $session
     */
    private function panelPage(string $label, array $get, array $needles = [], ?array $session = null): string
    {
        $mark = count(TestRunner::instance()->diagnostics());
        $result = $this->panelRender($get, [], $session);

        eq(null, $result['error'], $label . ' renders without throwing');

        if ($result['error'] !== null) {
            return '';
        }

        $html = $result['html'];

        eq(200, $result['code'], $label . ' answers 200');
        ok($label . ' returns a complete HTML document', str_contains($html, '<!doctype html>') && str_contains($html, '</html>'));
        ok($label . ' has non trivial content (' . strlen($html) . ' bytes)', strlen($html) > 4000);

        foreach ($needles as $needle) {
            ok($label . ' contains "' . $needle . '"', str_contains($html, $needle));
        }

        $this->panelMarkup($label, $html);
        $this->panelLangKeys($label, $html);
        $this->panelQuiet($mark, $label);

        return $html;
    }

    /**
     * The Content-Security-Policy contract every panel page depends on.
     *
     * `style-src 'self'; script-src 'self'` means a single inline <script>, a
     * <style> block, a style= attribute or an on…= handler silently stops
     * working in the browser — which is exactly the kind of bug a rendered
     * page can be asked about and a static scan cannot.
     */
    private function panelMarkup(string $label, string $html): void
    {
        if ($html === '') {
            return;
        }

        $violations = [];

        if (preg_match('/<script\b(?![^>]*\bsrc\s*=)[^>]*>/i', $html) === 1) {
            $violations[] = 'an inline <script> block';
        }

        if (preg_match('/<style\b/i', $html) === 1) {
            $violations[] = 'a <style> block';
        }

        if (preg_match('/<[a-z][a-z0-9]*\b[^>]*\sstyle\s*=/i', $html) === 1) {
            $violations[] = 'a style= attribute';
        }

        if (preg_match('/<[a-z][a-z0-9]*\b[^>]*\son[a-z]{2,}\s*=/i', $html) === 1) {
            $violations[] = 'an on…= handler attribute';
        }

        if (preg_match('/(?:href|src|action)\s*=\s*["\']\s*javascript:/i', $html) === 1) {
            $violations[] = 'a javascript: URL';
        }

        eq([], $violations, $label . ' obeys the panel CSP');
    }

    /**
     * Every language key the rendered markup mentions must resolve.
     *
     * Lang::t() returns the key itself when it is missing, so a key that made
     * it into the HTML verbatim is a missing translation. The static scanner
     * in the "Lang key coverage" suite cannot see keys a template builds at
     * runtime ('panel.broadcast_audience_' . $audience); this one can, because
     * it reads the answer rather than the source.
     */
    private function panelLangKeys(string $label, string $html): void
    {
        static $keys = null;
        static $pattern = '';

        if ($keys === null) {
            $keys = Lang::load('uz');
            $namespaces = [];

            foreach (array_keys($keys) as $key) {
                $namespaces[explode('.', (string) $key)[0]] = true;
            }

            $pattern = '/\b(?:' . implode('|', array_map('preg_quote', array_keys($namespaces)))
                . ')\.[a-z0-9_]+(?:\.[a-z0-9_]+)*/';
        }

        // ?p=logs prints the bot's own log messages and ?p=audit prints audit
        // action names; both are shaped exactly like a language key, and both
        // are data rather than markup the templates produced. The scanner is
        // about the chrome, so those regions are removed before it looks.
        if (str_contains($label, '?p=logs') || str_contains($label, '?p=audit')) {
            $html = (string) preg_replace(
                ['#<tbody\b.*?</tbody>#is', '#<option\b.*?</option>#is', '#<pre\b.*?</pre>#is'],
                ' ',
                $html
            );
        }

        if ($html === '' || preg_match_all($pattern, $html, $matches) < 1) {
            ok($label . ': no untranslated language key leaked into the markup', true);

            return;
        }

        $leaked = [];

        foreach (array_unique($matches[0]) as $token) {
            if (!array_key_exists($token, $keys)) {
                $leaked[] = $token;
            }
        }

        eq([], $leaked, $label . ': no untranslated language key leaked into the markup');
    }

    /**
     * Assert that nothing was added to the run-wide diagnostics list since $mark.
     */
    private function panelQuiet(int $mark, string $label): void
    {
        $raised = array_slice(TestRunner::instance()->diagnostics(), $mark);

        eq([], $raised, $label . ' raised no PHP warning, notice or deprecation');
    }

    /**
     * The table/index catalogue of the SQLite test database, as a fingerprint.
     *
     * @return string[]
     */
    private function panelSchema(): array
    {
        $rows = $this->app->db()->fetchAll(
            "SELECT type, name FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"
        );

        $schema = [];

        foreach ($rows as $row) {
            $schema[] = (string) ($row['type'] ?? '') . ':' . (string) ($row['name'] ?? '');
        }

        return $schema;
    }

    /* ---------------------------------------------------------------------
     | The child process
     */

    /**
     * Run one panel request in its own PHP process and read its report back.
     *
     * @param array<string,mixed> $case
     *
     * @return array{
     *     output:string, status:int, session:array<string,mixed>, calls:string[],
     *     diagnostics:string[], pages:array<int,array<string,mixed>>, stdout:string,
     *     stderr:string, exit:int, fatal:?string
     * }
     */
    private function panelRun(string $label, array $case): array
    {
        $this->panelRequests++;
        $this->panelCaseNumber++;

        $directory = $this->panelWorkspace();
        $caseFile = $directory . '/case-' . $this->panelCaseNumber . '.json';
        $resultFile = $directory . '/result-' . $this->panelCaseNumber . '.json';

        $server = $this->panelServer();

        foreach ((array) ($case['server'] ?? []) as $key => $value) {
            $server[(string) $key] = $value;
        }

        $case = array_merge(
            [
                'method'  => 'GET',
                'get'     => [],
                'post'    => [],
                'csrf'    => 'valid',
                'sent'    => false,
                'queue'   => [],
                'session' => $this->panelSession,
            ],
            $case,
            [
                'root'     => $this->root,
                'config'   => $directory . '/config.php',
                'sessions' => $this->tmpDir . '/sessions',
                'result'   => $resultFile,
                'server'   => $server,
            ]
        );

        $empty = [
            'output' => '', 'status' => 0, 'session' => [], 'calls' => [], 'diagnostics' => [],
            'pages' => [], 'stdout' => '', 'stderr' => '', 'exit' => -1, 'fatal' => null,
        ];

        $encoded = json_encode($case, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($encoded) || @file_put_contents($caseFile, $encoded) === false) {
            ok($label . ': the case could be handed to a child process', false);

            return $empty;
        }

        $pipes = [];
        $process = @proc_open(
            [PHP_BINARY, $this->panelWorker(), $caseFile],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            ok($label . ': a child process could be started', false);

            return $empty;
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        $raw = is_file($resultFile) ? (string) file_get_contents($resultFile) : '';
        $report = $raw === '' ? null : json_decode($raw, true);

        if (!is_array($report)) {
            ok(
                $label . ': the child process reported back (exit ' . $exit . ', '
                . ($stderr === '' ? 'no stderr' : trim(substr($stderr, 0, 200))) . ')',
                false
            );

            return array_merge($empty, ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit]);
        }

        $result = [
            'output'      => (string) base64_decode((string) ($report['output'] ?? ''), true),
            'status'      => (int) ($report['status'] ?? 0),
            'session'     => is_array($report['session'] ?? null) ? $report['session'] : [],
            'calls'       => array_map('strval', (array) ($report['calls'] ?? [])),
            'diagnostics' => array_map('strval', (array) ($report['diagnostics'] ?? [])),
            'pages'       => [],
            'stdout'      => $stdout,
            'stderr'      => $stderr,
            'exit'        => $exit,
            'fatal'       => isset($report['fatal']) && is_string($report['fatal']) ? $report['fatal'] : null,
        ];

        foreach ((array) ($report['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }

            $result['pages'][] = [
                'label' => (string) ($page['label'] ?? ''),
                'html'  => (string) base64_decode((string) ($page['html'] ?? ''), true),
                'code'  => (int) ($page['code'] ?? 0),
                'error' => isset($page['error']) && is_string($page['error']) ? $page['error'] : null,
            ];
        }

        eq(null, $result['fatal'], $label . ': the child process did not die on a fatal error');
        ok($label . ': the child process never loaded CurlTransport', ($report['curl'] ?? false) === false);

        // A "quiet" case raises a diagnostic on purpose (the collector probe);
        // everything else feeds the run-wide list that decides the verdict.
        if (empty($case['quiet'])) {
            foreach ($result['diagnostics'] as $diagnostic) {
                TestRunner::instance()->diagnostic($diagnostic . ' [admin panel: ' . $label . ']');
            }

            eq([], $result['diagnostics'], $label . ' raised no PHP warning, notice or deprecation');
        }

        return $result;
    }

    /** Create (once) the directory the child-process plumbing lives in. */
    private function panelWorkspace(): string
    {
        $directory = $this->tmpDir . '/panel';

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Write the child-process worker and the configuration it boots with.
     *
     * Both files live under tests/tmp, which the harness wipes at the start of
     * every run, so nothing is ever left behind in the project.
     */
    private function panelWorker(): string
    {
        if ($this->panelWorkerFile !== '') {
            return $this->panelWorkerFile;
        }

        $directory = $this->panelWorkspace();

        file_put_contents(
            $directory . '/config.php',
            "<?php\n\ndeclare(strict_types=1);\n\n// Generated by tests/run.php — the harness configuration, for child processes.\nreturn "
            . var_export($this->app->config()->all(), true) . ";\n"
        );

        file_put_contents($directory . '/worker.php', self::panelWorkerSource());

        $this->panelWorkerFile = $directory . '/worker.php';

        return $this->panelWorkerFile;
    }

    /**
     * The source of the child-process worker.
     *
     * It is generated rather than shipped so that tests/run.php stays the one
     * file the admin-panel coverage lives in. The worker does four things:
     * boot the application on the harness configuration, prepare the request
     * superglobals from the case file, dispatch through admin/index.php, and
     * write everything it saw into a JSON report from a shutdown function —
     * the one hook `exit` cannot skip, which is what makes a request that ends
     * in Request::redirect() or Request::json() observable at all.
     */
    private static function panelWorkerSource(): string
    {
        return <<<'WORKER'
<?php

declare(strict_types=1);

/**
 * Andijon AI Talents — admin panel case runner (generated by tests/run.php).
 *
 *     php tests/tmp/panel/worker.php <case.json>
 *
 * Never edit this file: it is rewritten from tests/run.php on every run and the
 * whole tests/tmp directory is wiped before the first suite starts.
 */

$caseFile = (string) ($_SERVER['argv'][1] ?? '');
$case = json_decode((string) @file_get_contents($caseFile), true);

if (!is_array($case) || !isset($case['result'], $case['root'], $case['config'])) {
    fwrite(STDERR, "panel worker: unusable case file\n");
    exit(2);
}

$report = [
    'diagnostics' => [],
    'output'      => '',
    'status'      => 0,
    'session'     => [],
    'calls'       => [],
    'pages'       => [],
    'curl'        => false,
    'fatal'       => null,
];

/* The same collector the harness itself installs, so a warning raised on a
 * panel path is reported no matter which process it happened in. */
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0) use (&$report): bool {
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    $labels = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User warning',
        E_USER_NOTICE => 'User notice',
        E_USER_DEPRECATED => 'User deprecated',
    ];

    $label = $labels[$severity] ?? ('Error(' . $severity . ')');
    $where = $file === '' ? '' : ' at ' . basename($file) . ':' . $line;
    $line = $label . ': ' . $message . $where;

    if (count($report['diagnostics']) < 50 && !in_array($line, $report['diagnostics'], true)) {
        $report['diagnostics'][] = $line;
    }

    return true;
});

/* Written even when the request ended in exit() — which every mutating panel
 * action does, because Request::redirect() and Request::json() are `never`. */
register_shutdown_function(static function () use (&$report, $case): void {
    while (ob_get_level() > 0) {
        $report['output'] .= (string) ob_get_clean();
    }

    $report['status'] = (int) (http_response_code() ?: 0);
    $report['session'] = isset($_SESSION) && is_array($_SESSION) ? $_SESSION : [];
    $report['curl'] = class_exists('AiTalents\\Telegram\\CurlTransport', false);

    $fake = $GLOBALS['PANEL_FAKE'] ?? null;

    if ($fake instanceof \AiTalents\Telegram\FakeTransport) {
        foreach ($fake->calls as $call) {
            $report['calls'][] = (string) ($call['method'] ?? '');
        }
    }

    $fatal = error_get_last();

    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $report['fatal'] = $fatal['message'] . ' at ' . basename($fatal['file']) . ':' . $fatal['line'];
    }

    // The XLSX download is binary, so the body travels base64 encoded.
    $report['output'] = base64_encode($report['output']);

    @file_put_contents(
        (string) $case['result'],
        (string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
    );
});

$GLOBALS['AITALENTS_CONFIG'] = require (string) $case['config'];

// The empty-database pass runs against a database of its own.
if (isset($case['database']) && is_string($case['database']) && $case['database'] !== '') {
    $GLOBALS['AITALENTS_CONFIG']['database']['path'] = $case['database'];
}

/** @var \AiTalents\App $app */
$app = require ((string) $case['root']) . '/bootstrap.php';

/* Nothing leaves the machine: the same guarantee the parent process gives. */
$fake = new \AiTalents\Telegram\FakeTransport();
$GLOBALS['PANEL_FAKE'] = $fake;

foreach ((array) ($case['queue'] ?? []) as $response) {
    $fake->pushRaw((string) $response);
}

$app->setApi(new \AiTalents\Telegram\Api(
    token: (string) $app->config('telegram.token', ''),
    logger: $app->logger(),
    timeout: (int) $app->config('telegram.timeout', 5),
    transport: $fake,
    apiBase: (string) $app->config('telegram.api_base', '')
));

if (!empty($case['migrate'])) {
    (new \AiTalents\Migrator($app->db()))->run();
}

/* A cookie-less session with its files inside tests/tmp; Auth::start() finds it
 * already active and leaves it alone, which is what makes the panel work here. */
@ini_set('session.use_cookies', '0');
@ini_set('session.cache_limiter', '');
@ini_set('session.save_path', (string) $case['sessions']);
@session_start();

foreach ((array) ($case['server'] ?? []) as $key => $value) {
    $_SERVER[(string) $key] = $value;
}

$_SESSION = (array) ($case['session'] ?? []);
$_GET = (array) ($case['get'] ?? []);
$_POST = (array) ($case['post'] ?? []);
$_SERVER['REQUEST_METHOD'] = (string) ($case['method'] ?? 'GET');
$_SERVER['QUERY_STRING'] = http_build_query($_GET);
$_SERVER['REQUEST_URI'] = '/admin/index.php?' . $_SERVER['QUERY_STRING'];

$csrf = (string) ($case['csrf'] ?? 'valid');

if ($csrf === 'valid') {
    $_POST[\AiTalents\Admin\Csrf::FIELD_NAME] = \AiTalents\Admin\Csrf::token();
} elseif ($csrf === 'invalid') {
    $_POST[\AiTalents\Admin\Csrf::FIELD_NAME] = str_repeat('0', 64);
}

if (!empty($case['probe'])) {
    // Deliberate "Array to string conversion": the harness checks that a PHP
    // warning raised inside a child really does travel back in this report.
    $probeArray = ['x'];
    $probeText = 'value: ' . $probeArray;
    unset($probeText);
}

/* Batch mode: several pages that all return normally, one process. */
if (isset($case['pages']) && is_array($case['pages'])) {
    // admin/index.php declares $page, $action, $view and $app in the scope it is
    // required from, so nothing this loop needs afterwards may use those names.
    foreach ($case['pages'] as $panelCase) {
        if (!is_array($panelCase)) {
            continue;
        }

        $panelLabel = (string) ($panelCase['label'] ?? '');

        $_GET = (array) ($panelCase['get'] ?? []);
        $_POST = [];
        $_SESSION = array_key_exists('session', $panelCase)
            ? (array) $panelCase['session']
            : (array) ($case['session'] ?? []);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = http_build_query($_GET);
        $_SERVER['REQUEST_URI'] = '/admin/index.php?' . $_SERVER['QUERY_STRING'];

        http_response_code(200);

        $panelError = null;
        $panelHtml = '';

        ob_start();

        try {
            require ((string) $case['root']) . '/admin/index.php';
            $panelHtml = (string) ob_get_clean();
        } catch (\Throwable $panelThrown) {
            ob_end_clean();
            $panelError = get_class($panelThrown) . ': ' . $panelThrown->getMessage()
                . ' at ' . basename($panelThrown->getFile()) . ':' . $panelThrown->getLine();
        }

        $report['pages'][] = [
            'label' => $panelLabel,
            'html'  => base64_encode($panelHtml),
            'code'  => (int) (http_response_code() ?: 0),
            'error' => $panelError,
        ];
    }

    exit(0);
}

/*
 * One byte of real output turns headers_sent() on, which is what makes
 * Request::redirect() print its target instead of sending a Location header
 * that the CLI SAPI would silently drop. Cases that want the status code
 * instead simply leave `sent` unset.
 */
if (empty($case['sent'])) {
    // CLI starts without a status code at all; seed the "nothing went wrong"
    // answer so a page that never touches http_response_code() reports 200.
    http_response_code(200);
} else {
    echo ' ';
    flush();
}

ob_start();

require ((string) $case['root']) . '/admin/index.php';

WORKER;
    }

    /* =====================================================================
     | PHP 8.1 compatibility scan
     ===================================================================== */

    private function compatibilitySuite(): void
    {
        $files = project_php_files($this->root, ['src', 'admin']);

        ok('there are PHP sources to scan', $files !== []);
        note(count($files) . ' PHP files scanned');

        // Constructs that only exist in PHP 8.2 and newer.
        $forbidden = [
            'readonly class'  => '/\breadonly\s+class\b/i',
            '#[Override]'     => '/#\[\s*\\\\?Override\b/i',
            'json_validate()' => '/\bjson_validate\s*\(/i',
            'mb_str_pad()'    => '/\bmb_str_pad\s*\(/i',
            'str_increment()' => '/\bstr_increment\s*\(/i',
            'str_decrement()' => '/\bstr_decrement\s*\(/i',
            'array_find()'    => '/\barray_find\s*\(/i',
            'array_any()'     => '/\barray_any\s*\(/i',
            'array_all()'     => '/\barray_all\s*\(/i',
            'utf8_encode()'   => '/\butf8_encode\s*\(/i',
            'utf8_decode()'   => '/\butf8_decode\s*\(/i',
        ];

        // Not a type: these words may legally sit in front of "$x = null".
        $notATypeName = [
            'mixed', 'static', 'global', 'var', 'public', 'private', 'protected',
            'readonly', 'const', 'return', 'case', 'default', 'echo', 'print',
            'new', 'clone', 'and', 'or', 'xor', 'yield', 'instanceof', 'fn',
            'function', 'as', 'use', 'else', 'elseif', 'endif', 'do',
        ];

        $violations = [];
        $implicitNullable = [];

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            try {
                $code = php_code_only($source);
            } catch (\Throwable $e) {
                $violations[] = $this->relativePath($file) . ': could not be tokenised — ' . $e->getMessage();

                continue;
            }

            foreach ($forbidden as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = $this->relativePath($file) . ': uses ' . $label;
                }
            }

            // Implicit nullable parameters: "string $x = null" instead of "?string $x = null".
            // The lookbehind also rejects a preceding backslash so that the
            // "PDO" of an already correct "?\PDO $pdo = null" cannot match.
            $pattern = '/(?<![?\w\\\\])'
                . '(\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*'
                . '(?:\s*\|\s*\\\\?(?:[A-Za-z_][A-Za-z0-9_]*\\\\)*[A-Za-z_][A-Za-z0-9_]*)*)'
                . '\s+\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*null\b/i';

            if (preg_match_all($pattern, $code, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $type = strtolower(trim($match[1]));

                    if (in_array($type, $notATypeName, true) || str_contains($type, 'null')) {
                        continue;
                    }

                    $implicitNullable[] = $this->relativePath($file)
                        . ': "' . $match[1] . ' $' . $match[2] . ' = null" should be "?' . $match[1] . '"';
                }
            }
        }

        eq([], $violations, 'no source file uses PHP 8.2+ only syntax or removed functions');
        eq([], $implicitNullable, 'no source file declares an implicit nullable parameter');

        // Every file must open with the mandated prologue.
        $badPrologue = [];

        foreach ($files as $file) {
            $head = (string) file_get_contents($file, false, null, 0, 200);

            if (!str_starts_with($head, '<?php')) {
                $badPrologue[] = $this->relativePath($file) . ': does not start with <?php';

                continue;
            }

            if (!str_contains($head, 'declare(strict_types=1);')) {
                $badPrologue[] = $this->relativePath($file) . ': misses declare(strict_types=1)';
            }
        }

        eq([], $badPrologue, 'every PHP file opens with <?php and declare(strict_types=1)');

        // The project must stay Composer free.
        ok('there is no composer.json', !is_file($this->root . '/composer.json'));
        ok('there is no vendor/ directory', !is_dir($this->root . '/vendor'));
    }

    /* =====================================================================
     | Synthetic Telegram updates
     ===================================================================== */

    /**
     * The Telegram `User` object of a simulated sender.
     *
     * @return array<string,mixed>
     */
    private function sender(int $telegramId): array
    {
        return [
            'id' => $telegramId,
            'is_bot' => false,
            'first_name' => 'Ali',
            'last_name' => 'Valiyev',
            'username' => 'ali_valiyev',
            'language_code' => 'uz',
        ];
    }

    /** A private text message. */
    private function message(int $telegramId, string $text): Update
    {
        return new Update([
            'update_id' => ++$this->updateId,
            'message' => [
                'message_id' => ++$this->messageId,
                'from' => $this->sender($telegramId),
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'date' => time(),
                'text' => $text,
            ],
        ]);
    }

    /** A private message carrying a shared contact card. */
    private function contactMessage(int $telegramId, string $phone, ?int $owner = null): Update
    {
        return new Update([
            'update_id' => ++$this->updateId,
            'message' => [
                'message_id' => ++$this->messageId,
                'from' => $this->sender($telegramId),
                'chat' => ['id' => $telegramId, 'type' => 'private'],
                'date' => time(),
                'contact' => [
                    'phone_number' => $phone,
                    'first_name' => 'Ali',
                    'user_id' => $owner ?? $telegramId,
                ],
            ],
        ]);
    }

    /** An inline button press. */
    private function callback(int $telegramId, string $data): Update
    {
        return new Update([
            'update_id' => ++$this->updateId,
            'callback_query' => [
                'id' => 'cb-' . $this->updateId,
                'from' => $this->sender($telegramId),
                'chat_instance' => 'test-instance',
                'data' => $data,
                'message' => [
                    'message_id' => ++$this->messageId,
                    'from' => ['id' => 1, 'is_bot' => true, 'first_name' => 'AI Talents Bot'],
                    'chat' => ['id' => $telegramId, 'type' => 'private'],
                    'date' => time(),
                    'text' => 'previous prompt',
                ],
            ],
        ]);
    }

    /** Shorten an absolute path for a report line. */
    private function relativePath(string $path): string
    {
        return str_starts_with($path, $this->root . '/') ? substr($path, strlen($this->root) + 1) : $path;
    }
}

/* =========================================================================
 | 5. Bootstrap the harness
 ========================================================================= */

if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "tests/run.php may only be run from the command line.\n";
    exit(1);
}

$argvList = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$wantsColour = !in_array('--no-color', $argvList, true)
    && (in_array('--color', $argvList, true) || (function_exists('stream_isatty') && @stream_isatty(STDOUT)));

TestRunner::instance()->configure($wantsColour, in_array('--quiet', $argvList, true));

$root = dirname(__DIR__);
$tmpDir = __DIR__ . '/tmp';

if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Cannot create the temporary directory " . $tmpDir . "\n");
    exit(1);
}

// A run always starts from an empty tests/tmp.
try {
    purge_directory($tmpDir, $tmpDir);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot clean ' . $tmpDir . ': ' . $e->getMessage() . "\n");
    exit(1);
}

if (!is_file($tmpDir . '/.gitkeep')) {
    @file_put_contents($tmpDir . '/.gitkeep', '');
}

/**
 * The configuration the harness runs on. Deliberately built in memory: running
 * the tests must never require the developer to create a config.php first.
 */
$GLOBALS['AITALENTS_CONFIG'] = [
    'telegram' => [
        'token'          => '111111111:AAHtest-token-for-the-harness-only',
        'bot_username'   => 'AndijonAiTalentsTestBot',
        'webhook_secret' => 'harness-webhook-secret',
        'admin_ids'      => [TestSuites::ADMIN_ID],
        'admin_chat_id'  => null,
        'timeout'        => 5,
        // The discard port on the loopback interface: even a bug cannot reach Telegram.
        'api_base'       => 'http://127.0.0.1:9/aitalents-offline',
    ],
    'database' => [
        'driver'   => 'sqlite',
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'aitalents_test',
        'username' => 'root',
        'password' => '',
        'charset'  => 'utf8mb4',
        'prefix'   => '',
        'path'     => $tmpDir . '/test.sqlite',
    ],
    'app' => [
        'name'              => 'Andijon AI Talents (tests)',
        'timezone'          => 'Asia/Tashkent',
        'default_locale'    => 'uz',
        'locales'           => ['uz', 'ru'],
        'ask_language'      => true,
        'registration_open' => true,
        'required_channel'  => null,
        'allow_edit'        => true,
        'steps'             => [
            'district'   => true,
            'birth_year' => true,
            'portfolio'  => true,
        ],
        'base_url'          => 'https://tests.invalid/bot',
    ],
    'security' => [
        'setup_key'  => 'harness-setup-key',
        // Generous on purpose: the flow suite sends dozens of updates in a row.
        'rate_limit' => ['enabled' => true, 'max' => 5000, 'per_seconds' => 60],
        'admin_panel' => [
            'enabled'          => true,
            'username'         => TestSuites::PANEL_USER,
            // A throw-away fixture hashed at the cheapest bcrypt cost on purpose:
            // the admin-panel suites verify this password dozens of times (once
            // per child process), and PASSWORD_DEFAULT would spend a quarter of
            // a second on every single one of them.
            'password_hash'    => password_hash(TestSuites::PANEL_PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]),
            'session_lifetime' => 7200,
            // Generous: the panel suites sign in from every child process.
            'max_attempts'     => 500,
            'lockout_seconds'  => 900,
        ],
    ],
    'log' => [
        'enabled'   => true,
        'level'     => 'info',
        'dir'       => $tmpDir . '/logs',
        'max_files' => 3,
    ],
];

/** @var App $app */
$app = require $root . '/bootstrap.php';

// Everything the bot says goes into memory instead of onto the wire.
$fakeTransport = new FakeTransport();
$fakeTransport->defaultResponse = json_encode([
    'ok' => true,
    'result' => [
        'message_id' => 4242,
        'date' => time(),
        'chat' => ['id' => TestSuites::APPLICANT_ID, 'type' => 'private'],
        'text' => 'ok',
    ],
], JSON_UNESCAPED_UNICODE) ?: '{"ok":true,"result":true}';

$app->setApi(new Api(
    token: (string) $app->config('telegram.token', ''),
    logger: $app->logger(),
    timeout: (int) $app->config('telegram.timeout', 5),
    transport: $fakeTransport,
    apiBase: (string) $app->config('telegram.api_base', '')
));

// Collect PHP warnings / notices / deprecations raised anywhere during the run.
set_error_handler(static function (int $severity, string $message, string $file = '', int $line = 0): bool {
    // Respect the @ operator and the configured error_reporting mask.
    if ((error_reporting() & $severity) === 0) {
        return false;
    }

    $labels = [
        E_WARNING => 'Warning',
        E_NOTICE => 'Notice',
        E_DEPRECATED => 'Deprecated',
        E_USER_WARNING => 'User warning',
        E_USER_NOTICE => 'User notice',
        E_USER_DEPRECATED => 'User deprecated',
    ];

    $label = $labels[$severity] ?? ('Error(' . $severity . ')');
    $where = $file === '' ? '' : ' at ' . basename($file) . ':' . $line;

    TestRunner::instance()->diagnostic($label . ': ' . $message . $where);

    return true; // handled: keep the console readable
});

register_shutdown_function(static function (): void {
    $fatal = error_get_last();

    if ($fatal !== null && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "\n  FATAL  " . $fatal['message'] . ' at ' . $fatal['file'] . ':' . $fatal['line'] . "\n\n");
    }

    // The admin-panel suites render pages in this very process. A panel request
    // that answers with Request::redirect()/json() calls exit(), which would
    // take the whole run down silently — so say which request it was.
    $render = $GLOBALS['AITALENTS_PANEL_RENDER'] ?? null;

    if (is_string($render) && $render !== '') {
        fwrite(
            STDERR,
            "\n  ABORTED  the run ended inside the in-process panel render of " . $render . ".\n"
            . "           That request calls exit(); drive it through panelRun() instead.\n\n"
        );
    }
});

/* =========================================================================
 | 6. Run
 ========================================================================= */

fwrite(STDOUT, "\n  Andijon AI Talents — test suite\n");
fwrite(STDOUT, '  database: ' . $tmpDir . '/test.sqlite' . "\n");
fwrite(STDOUT, '  transport: ' . FakeTransport::class . " (no network)\n");

$suites = new TestSuites($app, $fakeTransport, $root, $tmpDir);

foreach ($suites->all() as $name => $callback) {
    suite($name);

    try {
        $callback();
    } catch (\Throwable $e) {
        TestRunner::instance()->crashed($e);
    }

    // Guard rail: nothing may swap the fake transport for a real one mid-run.
    $transport = $app->api()->transport();

    if (!$transport instanceof FakeTransport) {
        ok(
            'GUARD: the Telegram client still uses the FakeTransport (a real ' . get_class($transport) . ' appeared!)',
            false
        );

        // Put the fake back so the remaining suites cannot reach the network either.
        $app->api()->setTransport($fakeTransport);
    }
}

/* The closing network guard: CurlTransport must never even have been loaded. */
suite('Network guard (final)');
ok(
    'the Telegram client ends the run on the FakeTransport',
    $app->api()->transport() instanceof FakeTransport
);
ok(
    'CurlTransport was never loaded during the whole run',
    !class_exists('AiTalents\\Telegram\\CurlTransport', false)
);

/*
 * PHP diagnostics collected along the way.
 *
 * This is the assertion the whole harness exists for, so it has to be able to
 * speak for the whole product. It used to certify the bot half only: not one
 * suite touched admin/, which is how three "Array to string conversion"
 * warnings shipped on ordinary panel screens while the run stayed green.
 *
 * The admin-panel suites now render every page and run every controller action
 * — in this process for the requests that return, and in a child process for
 * the ones that end in exit() — and every diagnostic either half raises ends
 * up in the very same list. The guard below refuses to call the run green if
 * the panel was not exercised at all, so the coverage cannot quietly vanish
 * again.
 */
suite('PHP diagnostics');
$diagnostics = TestRunner::instance()->diagnostics();

if ($diagnostics === []) {
    ok('the run raised no PHP warnings, notices or deprecations', true);
} else {
    foreach ($diagnostics as $diagnostic) {
        ok('no PHP diagnostic: ' . $diagnostic, false);
    }
}

$panelRequests = $suites->panelRequests();

ok(
    'the verdict covers the admin panel as well as the bot (' . $panelRequests . ' panel requests)',
    $panelRequests > 100
);

restore_error_handler();

exit(TestRunner::instance()->summary());
