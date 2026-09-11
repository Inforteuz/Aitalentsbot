<?php

declare(strict_types=1);

/**
 * Andijon AI Talents — application bootstrap.
 *
 * Usage:
 *     $app = require __DIR__ . '/bootstrap.php';
 *
 * Responsibilities:
 *  - define AITALENTS_ROOT / AITALENTS_VERSION;
 *  - register the PSR-4 style autoloader (AiTalents\Foo\Bar => src/Foo/Bar.php);
 *  - load config.php (with a friendly install hint when it is missing);
 *  - set the timezone and the mbstring internal encoding;
 *  - boot and return the \AiTalents\App singleton.
 *
 * The file is safe to require more than once: the booted application instance is
 * remembered and returned again instead of being rebuilt.
 *
 * Escape hatches used by the test harness / tooling (both optional):
 *  - $GLOBALS['AITALENTS_CONFIG']            an already built configuration array;
 *  - define('AITALENTS_CONFIG_FILE', $path)  an alternative configuration file.
 */

use AiTalents\App;

if (!defined('AITALENTS_ROOT')) {
    define('AITALENTS_ROOT', dirname(__FILE__));
}
if (!defined('AITALENTS_VERSION')) {
    define('AITALENTS_VERSION', '1.0.0');
}

/* -------------------------------------------------------------------------
 | Autoloader
 |--------------------------------------------------------------------------
 | Maps the AiTalents\ namespace onto src/. Classes of foreign namespaces are
 | ignored silently so other autoloaders keep working and no warning is raised
 | for a class file that does not exist here.
 */
if (!defined('AITALENTS_AUTOLOADER')) {
    define('AITALENTS_AUTOLOADER', true);

    spl_autoload_register(static function (string $class): void {
        $prefix = 'AiTalents\\';
        $length = strlen($prefix);

        // Not our namespace — leave it to the next autoloader.
        if (strncmp($class, $prefix, $length) !== 0) {
            return;
        }

        $relative = substr($class, $length);

        // Refuse anything that could escape the src/ directory.
        if ($relative === '' || strpos($relative, '.') !== false) {
            return;
        }

        $file = AITALENTS_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

/* -------------------------------------------------------------------------
 | Installation hint helper
 |--------------------------------------------------------------------------
 | Declared conditionally so that requiring this file twice never triggers a
 | "cannot redeclare" fatal error.
 */
if (!function_exists('aitalents_boot_fail')) {
    /**
     * Print a plain-text installation hint and stop the request.
     */
    function aitalents_boot_fail(string $message): never
    {
        $message = "\n" . $message . "\n";

        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            fwrite(STDERR, $message);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }

        echo $message;
        exit(1);
    }
}

error_reporting(E_ALL);

/*
 * Report everything, but never render it. A PHP notice printed into a webhook
 * response or a panel page leaks absolute paths and, worse, breaks the JSON and
 * redirect responses the panel relies on. The CLI keeps its output so that
 * cli.php and tools/seed.php stay debuggable in a terminal.
 */
if (PHP_SAPI !== 'cli') {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
}

@ini_set('log_errors', '1');

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

/* -------------------------------------------------------------------------
 | Already booted? Return the very same application instance.
 */
if (isset($GLOBALS['AITALENTS_APP']) && $GLOBALS['AITALENTS_APP'] instanceof App) {
    return $GLOBALS['AITALENTS_APP'];
}

/* -------------------------------------------------------------------------
 | Configuration
 */
$aitalentsConfig = null;

if (isset($GLOBALS['AITALENTS_CONFIG']) && is_array($GLOBALS['AITALENTS_CONFIG'])) {
    $aitalentsConfig = $GLOBALS['AITALENTS_CONFIG'];
} else {
    $aitalentsConfigFile = defined('AITALENTS_CONFIG_FILE')
        ? (string) AITALENTS_CONFIG_FILE
        : AITALENTS_ROOT . '/config.php';

    if (!is_file($aitalentsConfigFile)) {
        aitalents_boot_fail(
            "Andijon AI Talents is not configured yet.\n\n"
            . "config.php was not found at:\n    " . $aitalentsConfigFile . "\n\n"
            . "Fix it in two steps:\n"
            . "  1) copy config.example.php to config.php\n"
            . "  2) fill in the Telegram bot token and the database credentials\n\n"
            . "O'zbekcha: config.example.php faylidan config.php nusxasini yarating "
            . "va bot tokeni hamda ma'lumotlar bazasi sozlamalarini to'ldiring.\n"
        );
    }

    /** @var mixed $aitalentsLoaded */
    $aitalentsLoaded = require $aitalentsConfigFile;

    if (!is_array($aitalentsLoaded)) {
        aitalents_boot_fail(
            "config.php must return a PHP array.\n"
            . "Compare it with config.example.php and make sure the file ends with a return statement.\n"
        );
    }

    $aitalentsConfig = $aitalentsLoaded;
    unset($aitalentsLoaded, $aitalentsConfigFile);
}

/* -------------------------------------------------------------------------
 | Boot
 */
$aitalentsApp = App::boot($aitalentsConfig);
unset($aitalentsConfig);

// Timezone: everything stored by the app uses date('Y-m-d H:i:s') in this zone.
$aitalentsTimezone = (string) $aitalentsApp->config('app.timezone', 'Asia/Tashkent');
if ($aitalentsTimezone === '' || !in_array($aitalentsTimezone, DateTimeZone::listIdentifiers(), true)) {
    $aitalentsTimezone = 'Asia/Tashkent';
}
date_default_timezone_set($aitalentsTimezone);
unset($aitalentsTimezone);

$GLOBALS['AITALENTS_APP'] = $aitalentsApp;

return $aitalentsApp;
