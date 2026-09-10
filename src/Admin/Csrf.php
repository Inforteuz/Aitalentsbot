<?php

declare(strict_types=1);

namespace AiTalents\Admin;

use AiTalents\App;
use AiTalents\Lang;

/**
 * Cross-site request forgery protection for the admin panel.
 *
 * One random token per session is generated lazily, rendered into every form by
 * {@see self::field()} and verified on every POST by the front controller. The
 * token is rotated when a session gains privileges (see {@see Auth::attempt()}),
 * which is what makes session fixation useless to an attacker.
 */
final class Csrf
{
    /** Session key holding the token. */
    public const SESSION_KEY = '_csrf';

    /** Name of the hidden form field and of the JSON header. */
    public const FIELD_NAME = '_token';

    /** Server key of the header alternative, used by fetch() calls. */
    public const HEADER_KEY = 'HTTP_X_CSRF_TOKEN';

    /** HTTP status used for a rejected token (Laravel's de-facto standard). */
    public const REJECT_STATUS = 419;

    /** Token length in raw bytes; the rendered token is twice as long (hex). */
    private const BYTES = 32;

    /**
     * Token used when there is no session at all (CLI rendering, tests). It only
     * lives for the current request, which is exactly what it is meant to do.
     */
    private static ?string $fallback = null;

    /**
     * The token for the current session, generated on first use.
     */
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return self::$fallback ??= self::generate();
        }

        $token = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_string($token) || preg_match('/^[a-f0-9]{' . (self::BYTES * 2) . '}$/', $token) !== 1) {
            $token = self::generate();
            $_SESSION[self::SESSION_KEY] = $token;
        }

        return $token;
    }

    /**
     * The hidden input every form must contain.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '">';
    }

    /**
     * Constant-time comparison against the session token.
     */
    public static function check(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        $expected = session_status() === PHP_SESSION_ACTIVE
            ? ($_SESSION[self::SESSION_KEY] ?? null)
            : self::$fallback;

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /**
     * Verify the token of the current request or stop the request with 419.
     *
     * The token is taken from the `_token` field, or from the `X-CSRF-Token`
     * header when the panel's JavaScript posts through fetch().
     */
    public static function verifyOrFail(): void
    {
        if (self::check(self::submittedToken())) {
            return;
        }

        self::audit();

        $message = self::message();

        if (Request::wantsJson()) {
            Request::json(['ok' => false, 'error' => $message], self::REJECT_STATUS);
        }

        if (!headers_sent()) {
            http_response_code(self::REJECT_STATUS);
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // Deliberately style-free: the panel's CSP forbids inline CSS and this
        // page must render even when the stylesheet cannot be reached.
        echo '<!doctype html><html lang="uz"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::REJECT_STATUS . '</title></head><body>'
            . '<h1>' . self::REJECT_STATUS . '</h1><p>' . $safe . '</p>'
            . '<p><a href="index.php">index.php</a></p>'
            . '</body></html>';

        exit;
    }

    /**
     * Forget the current token so the next call mints a fresh one.
     * Called after a successful login, when the privilege level changes.
     */
    public static function rotate(): void
    {
        self::$fallback = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_KEY]);
        }
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * The token sent with the current request, if any.
     */
    private static function submittedToken(): ?string
    {
        $posted = $_POST[self::FIELD_NAME] ?? null;

        if (is_string($posted) && $posted !== '') {
            return trim($posted);
        }

        $header = $_SERVER[self::HEADER_KEY] ?? null;

        if (is_string($header) && $header !== '') {
            return trim($header);
        }

        return null;
    }

    private static function generate(): string
    {
        try {
            return bin2hex(random_bytes(self::BYTES));
        } catch (\Throwable $e) {
            // random_bytes() only fails when the platform has no CSPRNG at all;
            // this keeps the panel usable instead of throwing a 500 at login.
            return hash('sha256', uniqid('aitalents', true) . microtime(true) . (string) mt_rand());
        }
    }

    /**
     * The localised "invalid token" sentence, with a safe fallback.
     */
    private static function message(): string
    {
        $locale = function_exists('panel_locale') ? panel_locale() : Lang::FALLBACK;

        return Lang::t('panel.csrf_invalid', $locale);
    }

    /**
     * Leave a trace: a rejected token is either a bug or an attack.
     */
    private static function audit(): void
    {
        try {
            App::instance()->audit()->log(
                'panel',
                'panel.csrf.fail',
                null,
                ['uri' => (string) ($_SERVER['REQUEST_URI'] ?? '')],
                Request::ip()
            );
        } catch (\Throwable $e) {
            // The application may not even be booted here — never mind.
        }
    }
}
