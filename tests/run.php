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

    private App $app;
    private FakeTransport $fake;
    private string $root;
    private string $tmpDir;

    /** Increasing ids for the synthetic updates. */
    private int $updateId = 100000;
    private int $messageId = 500;

    /** The registration created by the simulated flow. */
    private int $flowRegistrationId = 0;

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
            'PHP 8.1 compatibility scan'     => $this->compatibilitySuite(...),
        ];
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
            'username'         => 'admin',
            'password_hash'    => password_hash('harness-password', PASSWORD_DEFAULT),
            'session_lifetime' => 7200,
            'max_attempts'     => 5,
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

/* PHP diagnostics collected along the way. */
suite('PHP diagnostics');
$diagnostics = TestRunner::instance()->diagnostics();

if ($diagnostics === []) {
    ok('the run raised no PHP warnings, notices or deprecations', true);
} else {
    foreach ($diagnostics as $diagnostic) {
        ok('no PHP diagnostic: ' . $diagnostic, false);
    }
}

restore_error_handler();

exit(TestRunner::instance()->summary());
