<?php

declare(strict_types=1);

/**
 * Andijon AI Talents — admin panel bootstrap.
 *
 * Usage (from admin/index.php):
 *     $app = require __DIR__ . '/bootstrap.php';
 *
 * Responsibilities, in this order:
 *  1. boot the application (root bootstrap.php: autoloader, config, timezone);
 *  2. send the panel's security headers;
 *  3. refuse to serve anything when `security.admin_panel.enabled` is false;
 *  4. start the hardened session (see {@see \AiTalents\Admin\Auth::start()});
 *  5. define the global helpers every template uses — e(), t(), asset(), url(),
 *     flash(), old(), panel_locale();
 *  6. return the \AiTalents\App instance.
 *
 * The file is safe to require more than once: the second call returns the very
 * same application instance without repeating any of the work above.
 */

use AiTalents\Admin\Auth;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Lang;

if (isset($GLOBALS['AITALENTS_PANEL_APP']) && $GLOBALS['AITALENTS_PANEL_APP'] instanceof App) {
    return $GLOBALS['AITALENTS_PANEL_APP'];
}

/** @var App $aitalentsPanelApp */
$aitalentsPanelApp = require dirname(__DIR__) . '/bootstrap.php';

/* =========================================================================
 | Global helpers
 |==========================================================================
 | Templates are plain PHP, so a handful of short functions keeps them
 | readable. Every one of them is defined defensively: requiring this file
 | twice (or from a test harness that already declared them) must not fatal.
 */

if (!function_exists('e')) {
    /**
     * Escape a value for HTML output.
     *
     * The documented contract is `e(?string $s): string`; the parameter is typed
     * `mixed` on purpose so that integers coming straight out of PDO (ids,
     * counters, telegram ids) do not raise a TypeError inside a template that
     * declares strict_types. Anything that cannot be turned into a string
     * renders as an empty string instead of breaking the page.
     */
    function e(mixed $s = null): string
    {
        if ($s === null) {
            return '';
        }

        if (is_bool($s)) {
            $s = $s ? '1' : '0';
        } elseif (is_array($s)) {
            $s = '';
        } elseif (is_object($s)) {
            $s = $s instanceof \Stringable ? (string) $s : '';
        }

        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
    /**
     * Translate a key in the panel's interface language.
     *
     * @param array<string,mixed> $p values for the `:name` placeholders
     */
    function t(string $key, array $p = []): string
    {
        return Lang::t($key, panel_locale(), $p);
    }
}

if (!function_exists('asset')) {
    /**
     * URL of a file in admin/assets, cache-busted with the application version.
     *
     * `asset('app.css')` and `asset('assets/app.css')` both produce
     * `assets/app.css?v=1.0.0`; directory traversal is stripped.
     */
    function asset(string $file): string
    {
        $name = basename(str_replace('\\', '/', trim($file)));

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'app.css';
        }

        $version = defined('AITALENTS_VERSION') ? (string) AITALENTS_VERSION : '1.0.0';

        return 'assets/' . rawurlencode($name) . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('url')) {
    /**
     * Build a panel URL: `url(['p' => 'registrations', 'status' => 'pending'])`
     * returns `index.php?p=registrations&status=pending`.
     *
     * The result is URL encoded but not HTML encoded — print it through e().
     *
     * @param array<string,mixed> $params
     */
    function url(array $params = []): string
    {
        return View::link($params);
    }
}

if (!function_exists('flash')) {
    /**
     * Read or write the session flash bag.
     *
     * - `flash('success', t('panel.flash_saved'))` queues a message;
     * - `flash()` returns every queued message and empties the bag;
     * - `flash('error')` returns (and removes) only the messages of that type.
     *
     * Messages are `['type' => string, 'message' => string]` pairs; the type is
     * one of success|error|warning|info and is used as a CSS modifier by the
     * toast partial.
     *
     * @return array<int,array{type:string,message:string}> empty when queuing
     */
    function flash(?string $type = null, ?string $msg = null): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }

        $bag = $_SESSION[Auth::KEY_FLASH] ?? [];

        if (!is_array($bag)) {
            $bag = [];
        }

        // Write mode.
        if ($type !== null && $msg !== null) {
            $clean = trim($msg);

            if ($clean !== '') {
                $bag[] = ['type' => panel_flash_type($type), 'message' => $clean];
                // Keep the bag bounded: a redirect loop must not fill the session.
                $_SESSION[Auth::KEY_FLASH] = array_slice($bag, -10);
            }

            return [];
        }

        // Read mode — normalise, then consume.
        $messages = [];
        $keep = [];

        foreach ($bag as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $entryType = panel_flash_type((string) ($entry['type'] ?? 'info'));
            $entryText = trim((string) ($entry['message'] ?? ''));

            if ($entryText === '') {
                continue;
            }

            if ($type !== null && $entryType !== panel_flash_type($type)) {
                $keep[] = ['type' => $entryType, 'message' => $entryText];

                continue;
            }

            $messages[] = ['type' => $entryType, 'message' => $entryText];
        }

        if ($keep === []) {
            unset($_SESSION[Auth::KEY_FLASH]);
        } else {
            $_SESSION[Auth::KEY_FLASH] = $keep;
        }

        return $messages;
    }
}

if (!function_exists('panel_flash_type')) {
    /** Reduce a flash type to the four the stylesheet knows about. */
    function panel_flash_type(string $type): string
    {
        $type = strtolower(trim($type));

        return match ($type) {
            'success', 'ok', 'done' => 'success',
            'error', 'danger', 'fail' => 'error',
            'warning', 'warn' => 'warning',
            default => 'info',
        };
    }
}

if (!function_exists('old')) {
    /**
     * Repopulate a form field.
     *
     * The current request body wins (a form re-rendered after a failed
     * validation), then the values stashed by {@see remember_old()} before a
     * redirect. Values are returned raw — print them through e().
     */
    function old(string $key, mixed $default = ''): mixed
    {
        if ($key !== '' && array_key_exists($key, $_POST)) {
            $value = $_POST[$key];

            return is_string($value) ? str_replace("\0", '', $value) : $value;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $default;
        }

        $bag = $_SESSION[Auth::KEY_OLD] ?? null;

        if (!is_array($bag) || !array_key_exists($key, $bag)) {
            return $default;
        }

        return $bag[$key];
    }
}

if (!function_exists('remember_old')) {
    /**
     * Stash the submitted input for the next request, so a controller that
     * redirects after a validation error can still refill the form with old().
     *
     * Passing null remembers the whole POST body. Secrets and the CSRF token are
     * never stored.
     *
     * @param ?array<string,mixed> $input
     */
    function remember_old(?array $input = null): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $input ??= $_POST;
        $skip = ['_token', 'password', 'pass', 'password_confirmation', 'new_password'];
        $bag = [];

        foreach ($input as $key => $value) {
            if (!is_string($key) || $key === '' || in_array($key, $skip, true)) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || is_array($value)) {
                $bag[$key] = $value;
            }
        }

        if ($bag === []) {
            unset($_SESSION[Auth::KEY_OLD]);

            return;
        }

        $_SESSION[Auth::KEY_OLD] = $bag;
    }
}

if (!function_exists('forget_old')) {
    /** Drop the stashed input (called once the form was rendered again). */
    function forget_old(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[Auth::KEY_OLD]);
        }
    }
}

if (!function_exists('panel_locale')) {
    /**
     * Interface language of the panel.
     *
     * Resolution order: an explicit `?lang=` switch, the language stored in the
     * session, then the application default. Only locales enabled in config.php
     * are accepted, so the parameter cannot be used to probe the file system.
     */
    function panel_locale(): string
    {
        static $resolved = null;

        $sessionActive = session_status() === PHP_SESSION_ACTIVE;

        if ($resolved !== null && $sessionActive) {
            return $resolved;
        }

        try {
            $app = App::instance();
            $available = $app->locales();
            $locale = $app->defaultLocale();
        } catch (\Throwable $e) {
            $available = [Lang::FALLBACK];
            $locale = Lang::FALLBACK;
        }

        if ($sessionActive) {
            $stored = $_SESSION[Auth::KEY_LOCALE] ?? null;

            if (is_string($stored) && in_array($stored, $available, true)) {
                $locale = $stored;
            }
        }

        // ?lang[]=ru would otherwise reach the string cast; this helper runs on
        // every request, including the ones that answer with JSON.
        $requestedRaw = $_GET['lang'] ?? '';
        $requested    = is_string($requestedRaw) ? strtolower(trim($requestedRaw)) : '';

        if ($requested !== '' && in_array($requested, $available, true)) {
            $locale = $requested;
        }

        if ($sessionActive) {
            $_SESSION[Auth::KEY_LOCALE] = $locale;
            $resolved = $locale;
        }

        return $locale;
    }
}

if (!function_exists('panel_app')) {
    /** The booted application instance. */
    function panel_app(): App
    {
        return App::instance();
    }
}

if (!function_exists('panel_auth')) {
    /** The shared Auth instance created by this bootstrap. */
    function panel_auth(): Auth
    {
        $auth = $GLOBALS['AITALENTS_PANEL_AUTH'] ?? null;

        if (!$auth instanceof Auth) {
            $auth = new Auth(App::instance());
            $GLOBALS['AITALENTS_PANEL_AUTH'] = $auth;
        }

        return $auth;
    }
}

/* =========================================================================
 | Security headers
 |==========================================================================
 | The panel is a closed back office: it must never be framed, never be sniffed,
 | never leak its URLs through a Referer header and never load a third party
 | asset. The CSP is what forbids inline <script>/<style>, which is why all
 | behaviour lives in admin/assets/app.js and all styling in app.css.
 */

if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header(
        "Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; "
        . "script-src 'self'; form-action 'self'; base-uri 'self'; frame-ancestors 'none'"
    );
    header('X-Robots-Tag: noindex, nofollow');
    // Administrative pages contain personal data — keep them out of every cache.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

/* =========================================================================
 | Kill switch
 |==========================================================================
 | security.admin_panel.enabled = false takes the whole panel off the air, which
 | is the recommended state for a deployment that only runs the bot.
 */

if (!(bool) $aitalentsPanelApp->config('security.admin_panel.enabled', true)) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Retry-After: 3600');
    }

    echo t('panel.login_disabled') . "\n";
    exit;
}

/* =========================================================================
 | Session
 |==========================================================================
 | Auth::start() applies the hardened cookie parameters (HttpOnly, SameSite=Lax,
 | Secure over HTTPS, a dedicated cookie name scoped to the panel directory).
 */

$GLOBALS['AITALENTS_PANEL_AUTH'] = new Auth($aitalentsPanelApp);
$GLOBALS['AITALENTS_PANEL_AUTH']->start();

$GLOBALS['AITALENTS_PANEL_APP'] = $aitalentsPanelApp;

return $aitalentsPanelApp;
