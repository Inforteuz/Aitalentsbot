<?php

declare(strict_types=1);

namespace AiTalents\Admin;

/**
 * A very small read-only wrapper around the PHP superglobals.
 *
 * The admin panel is a classic server-rendered application, so there is no need
 * for a request object graph: a handful of static accessors that always return a
 * predictable type is enough, and it keeps controllers free of `isset()` noise.
 *
 * Everything that leaves this class is sanitised at least once:
 *  - NUL bytes and C0/C1 control characters never survive a string read;
 *  - {@see self::int()} only accepts a genuine integer literal;
 *  - {@see self::redirect()} refuses absolute URLs, so a crafted query string
 *    can neither inject a header nor turn the panel into an open redirector.
 */
final class Request
{
    /**
     * Server keys that may hold the real client address behind a reverse proxy.
     * They are only consulted when the proxy is explicitly trusted.
     */
    private const PROXY_KEYS = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CF_CONNECTING_IP'];

    /**
     * Environment variables that switch proxy support on. Set one of them in
     * .htaccess (`SetEnv AITALENTS_TRUST_PROXY 1`) when the panel really does run
     * behind Cloudflare or an nginx front end — the default is "do not trust".
     */
    private const TRUST_PROXY_KEYS = ['AITALENTS_TRUST_PROXY', 'REDIRECT_AITALENTS_TRUST_PROXY'];

    /** Fallback used when REMOTE_ADDR is missing (CLI, broken SAPI). */
    private const UNKNOWN_IP = '0.0.0.0';

    /** Characters that must never appear inside a value read from the request. */
    private const CONTROL_PATTERN = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/';

    /**
     * The HTTP verb in upper case; always a plain word, never attacker controlled
     * punctuation.
     */
    public static function method(): string
    {
        $method = strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')));

        return preg_match('/^[A-Z]{3,10}$/', $method) === 1 ? $method : 'GET';
    }

    /**
     * A value from the query string.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $_GET)) {
            return $default;
        }

        return self::sanitize($_GET[$key]);
    }

    /**
     * A value from the request body.
     */
    public static function post(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, $_POST)) {
            return $default;
        }

        return self::sanitize($_POST[$key]);
    }

    /**
     * An integer from the body, falling back to the query string.
     *
     * Only a real integer literal is accepted: "12abc", "1e3" and "" all yield
     * the default, so an id can never silently become 0 or 1.
     */
    public static function int(string $key, int $default = 0): int
    {
        $value = self::input($key);

        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            return is_finite($value) ? (int) $value : $default;
        }

        if (is_string($value) && preg_match('/^[+-]?\d{1,18}$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $default;
    }

    /**
     * A trimmed string from the body, falling back to the query string.
     *
     * Line breaks survive (broadcast texts and notes need them); control
     * characters do not. A missing or non-scalar value returns the default.
     */
    public static function str(string $key, string $default = ''): string
    {
        $value = self::input($key);

        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $default;
    }

    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /**
     * The client address.
     *
     * REMOTE_ADDR is the only source that can be trusted, because any header can
     * be forged by the client. Proxy headers are read exclusively when the
     * deployment opted in via the AITALENTS_TRUST_PROXY environment variable.
     */
    public static function ip(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

        if (self::trustProxy()) {
            $forwarded = self::forwardedIp();

            if ($forwarded !== null) {
                return $forwarded;
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) === false ? self::UNKNOWN_IP : $remote;
    }

    /**
     * True when the caller expects JSON (the broadcast progress poller does).
     */
    public static function wantsJson(): bool
    {
        $requestedWith = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')));

        if ($requestedWith === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

        if ($accept !== '' && str_contains($accept, 'application/json') && !str_contains($accept, 'text/html')) {
            return true;
        }

        return strtolower(self::str('format')) === 'json' || self::str('json') === '1';
    }

    /**
     * Send a 302 to a panel-local target and stop the request.
     *
     * Absolute URLs, protocol-relative URLs and anything carrying a newline are
     * rejected and replaced by the panel entry point.
     */
    public static function redirect(string $to): never
    {
        $location = self::safeLocation($to);

        if (!headers_sent()) {
            header('Location: ' . $location, true, 302);
            header('Cache-Control: no-store, no-cache, must-revalidate');
        } else {
            // Headers are gone: give the browser something clickable instead.
            echo '<p><a href="' . htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '</a></p>';
        }

        exit;
    }

    /**
     * Emit a JSON document and stop the request.
     *
     * @param array<string,mixed> $data
     */
    public static function json(array $data, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        $json = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        echo is_string($json) ? $json : '{"ok":false,"error":"encoding_failed"}';

        exit;
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Body first, query string second — POST forms usually keep their routing
     * parameters (`?p=users&a=block`) in the URL.
     */
    private static function input(string $key): mixed
    {
        if (array_key_exists($key, $_POST)) {
            return self::sanitize($_POST[$key]);
        }

        if (array_key_exists($key, $_GET)) {
            return self::sanitize($_GET[$key]);
        }

        return null;
    }

    /**
     * Strip control characters from strings, recursively for arrays.
     * Nesting is capped so a hand-crafted payload cannot exhaust the stack.
     */
    private static function sanitize(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            $clean = preg_replace(self::CONTROL_PATTERN, '', $value);

            // preg_replace() returns null on a malformed subject: fall back to
            // dropping NUL bytes only, which is the part that really matters.
            return is_string($clean) ? $clean : str_replace("\0", '', $value);
        }

        if (is_array($value)) {
            if ($depth >= 3) {
                return [];
            }

            $clean = [];

            foreach ($value as $key => $item) {
                $clean[$key] = self::sanitize($item, $depth + 1);
            }

            return $clean;
        }

        return $value;
    }

    /**
     * Whether a reverse proxy in front of the panel may dictate the client IP.
     */
    private static function trustProxy(): bool
    {
        foreach (self::TRUST_PROXY_KEYS as $key) {
            if (!isset($_SERVER[$key])) {
                continue;
            }

            $flag = strtolower(trim((string) $_SERVER[$key]));

            if (in_array($flag, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The left-most valid address of the proxy chain, or null when none is usable.
     */
    private static function forwardedIp(): ?string
    {
        foreach (self::PROXY_KEYS as $key) {
            $raw = trim((string) ($_SERVER[$key] ?? ''));

            if ($raw === '') {
                continue;
            }

            foreach (explode(',', $raw) as $candidate) {
                $candidate = trim($candidate);

                // "[::1]:1234" and "1.2.3.4:1234" forms used by some proxies.
                if (preg_match('/^\[(.+)\](?::\d+)?$/', $candidate, $m) === 1) {
                    $candidate = $m[1];
                } elseif (substr_count($candidate, ':') === 1 && strpos($candidate, '.') !== false) {
                    $candidate = (string) strstr($candidate, ':', true);
                }

                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Reduce a redirect target to something that can only point inside the panel.
     */
    private static function safeLocation(string $to): string
    {
        $to = str_replace(["\r", "\n", "\0", "\t"], '', trim($to));

        if ($to === '') {
            return 'index.php';
        }

        // Protocol-relative ("//evil.tld") or absolute ("https://evil.tld").
        if (str_starts_with($to, '//') || preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*:#', $to) === 1) {
            return 'index.php';
        }

        // A root-relative path is fine, anything trying to climb out is not.
        if (str_contains($to, '..')) {
            return 'index.php';
        }

        return $to;
    }
}
