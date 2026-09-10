<?php

declare(strict_types=1);

namespace AiTalents\Admin;

use AiTalents\App;
use AiTalents\Lang;

/**
 * Session and login handling for the web admin panel.
 *
 * The panel has exactly one account, configured in `config.php` under
 * `security.admin_panel` (a username and a `password_hash()` digest). This class
 * owns everything around it:
 *
 *  - a hardened session cookie (HttpOnly, SameSite=Lax, Secure over HTTPS,
 *    a dedicated cookie name and a path scoped to the panel directory);
 *  - throttling: after `max_attempts` failures from one IP inside
 *    `lockout_seconds`, further attempts are refused (table `login_attempts`);
 *  - session id regeneration on login and every 15 minutes afterwards;
 *  - an absolute session lifetime taken from `session_lifetime`;
 *  - an audit entry for every successful login, failed login and logout.
 *
 * Nothing here throws: a missing `login_attempts` table (fresh install) degrades
 * into "no throttling" instead of a fatal error on the login screen.
 */
final class Auth
{
    /** Cookie name — deliberately different from the default PHPSESSID. */
    public const SESSION_NAME = 'aitalents_panel';

    /** Session key holding the authenticated identity. */
    public const KEY_AUTH = '_auth';

    /** Session key holding the flash message bag (see the flash() helper). */
    public const KEY_FLASH = '_flash';

    /** Session key holding the previous request's input (see the old() helper). */
    public const KEY_OLD = '_old_input';

    /** Session key holding the chosen panel interface language. */
    public const KEY_LOCALE = '_panel_locale';

    /** How often the session id is rotated while the admin keeps working. */
    private const REGENERATE_EVERY = 900;

    /** Defaults mirroring config.example.php. */
    private const DEFAULT_LIFETIME = 7200;
    private const DEFAULT_MAX_ATTEMPTS = 5;
    private const DEFAULT_LOCKOUT = 900;

    /** Login attempts older than this are pruned on a successful login. */
    private const ATTEMPT_RETENTION_DAYS = 7;

    /** Human readable reason of the last refused {@see self::attempt()}. */
    private ?string $lastError = null;

    public function __construct(private App $app)
    {
    }

    /* --------------------------------------------------------------------
     | Session
     */

    /**
     * Start the panel session with hardened cookie parameters.
     *
     * Safe to call repeatedly: an already running session is left untouched.
     */
    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Sessions are meaningless on the CLI and headers cannot be sent late.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        $lifetime = $this->lifetime();

        // Harden the session module itself before the cookie is created.
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_name(self::SESSION_NAME);
        session_cache_limiter('nocache');

        session_set_cookie_params([
            // A session cookie: the absolute lifetime is enforced server side.
            'lifetime' => 0,
            'path'     => $this->cookiePath(),
            'domain'   => '',
            'secure'   => $this->isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * True when a valid, unexpired administrator session is present.
     *
     * Also performs the periodic session id rotation, so every page view keeps
     * the session fresh without any extra call from the controllers.
     */
    public function check(): bool
    {
        $this->start();

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $auth = $_SESSION[self::KEY_AUTH] ?? null;

        if (!is_array($auth) || trim((string) ($auth['user'] ?? '')) === '') {
            return false;
        }

        $now = time();
        $loginAt = (int) ($auth['login_at'] ?? 0);

        // Absolute expiry — a session cannot outlive session_lifetime.
        if ($loginAt <= 0 || ($now - $loginAt) > $this->lifetime()) {
            $this->forget();
            $this->flash('error', Lang::t('panel.session_expired', $this->locale()));

            return false;
        }

        // Bind the session to the browser that created it.
        if (!hash_equals((string) ($auth['fingerprint'] ?? ''), $this->fingerprint())) {
            $this->forget();
            $this->flash('error', Lang::t('panel.session_expired', $this->locale()));

            return false;
        }

        $regeneratedAt = (int) ($auth['regenerated_at'] ?? 0);

        if ($regeneratedAt <= 0 || ($now - $regeneratedAt) >= self::REGENERATE_EVERY) {
            if (!headers_sent()) {
                session_regenerate_id(true);
            }

            $auth['regenerated_at'] = $now;
        }

        $auth['seen_at'] = $now;
        $_SESSION[self::KEY_AUTH] = $auth;

        return true;
    }

    /**
     * The signed-in administrator's username, or null when nobody is signed in.
     */
    public function user(): ?string
    {
        $this->start();

        $auth = $_SESSION[self::KEY_AUTH] ?? null;

        if (!is_array($auth)) {
            return null;
        }

        $user = trim((string) ($auth['user'] ?? ''));

        return $user === '' ? null : $user;
    }

    /**
     * Redirect to the login screen unless the request is authenticated.
     */
    public function requireAuth(): void
    {
        if ($this->check()) {
            return;
        }

        Request::redirect(View::link(['p' => 'login']));
    }

    /* --------------------------------------------------------------------
     | Login / logout
     */

    /**
     * Verify credentials and open a session.
     *
     * Returns false for every failure mode; {@see self::lastError()} explains
     * which one it was, in the panel's language, without ever telling the caller
     * whether the username or the password was the wrong half.
     */
    public function attempt(string $user, string $pass, string $ip): bool
    {
        $this->start();
        $this->lastError = null;

        $locale = $this->locale();
        $username = trim($user);
        $ip = $this->normalizeIp($ip);

        // The panel as a whole can be switched off in config.php.
        if (!$this->isEnabled()) {
            $this->lastError = Lang::t('panel.login_disabled', $locale);
            $this->audit('panel.login.disabled', $username, $ip);

            return false;
        }

        if ($this->isLockedOut($ip)) {
            $minutes = max(1, (int) ceil($this->remainingLockout($ip) / 60));
            $this->lastError = Lang::t('panel.login_locked', $locale, ['minutes' => $minutes]);
            $this->audit('panel.login.locked', $username, $ip, ['minutes' => $minutes]);

            return false;
        }

        $hash = (string) $this->app->config('security.admin_panel.password_hash', '');

        if ($hash === '') {
            // Nothing to verify against: say so loudly instead of pretending the
            // credentials were wrong, otherwise the installer is unreachable.
            $this->lastError = Lang::t('panel.login_disabled', $locale)
                . ' (security.admin_panel.password_hash — setup.php)';
            $this->flash('error', $this->lastError);
            $this->audit('panel.login.unconfigured', $username, $ip);

            return false;
        }

        $expectedUser = trim((string) $this->app->config('security.admin_panel.username', 'admin'));

        // Both halves are always evaluated so the answer takes the same path
        // regardless of which one is wrong.
        $userOk = $expectedUser !== '' && hash_equals($expectedUser, $username);
        $passOk = password_verify($pass, $hash);
        $ok = $userOk && $passOk;

        $this->recordAttempt($ip, $username, $ok);

        if (!$ok) {
            $this->lastError = Lang::t('panel.login_error', $locale);
            $this->audit('panel.login.fail', $username, $ip);

            return false;
        }

        $this->establishSession($expectedUser, $ip);
        $this->clearAttempts($ip);
        $this->pruneAttempts();
        $this->audit('panel.login.success', $expectedUser, $ip);

        return true;
    }

    /**
     * End the session, keeping it alive (with a brand new id) so the redirect
     * that follows can still carry a flash message.
     */
    public function logout(): void
    {
        $this->start();

        $user = $this->user();

        if ($user !== null) {
            $this->audit('panel.logout', $user, Request::ip());
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];

        if (!headers_sent()) {
            // Destroys the old session file and hands out a fresh id.
            session_regenerate_id(true);
        }

        Csrf::rotate();
    }

    /* --------------------------------------------------------------------
     | Throttling
     */

    /**
     * True when this IP has burned through its allowed attempts.
     */
    public function isLockedOut(string $ip): bool
    {
        $max = $this->maxAttempts();
        $ip = $this->normalizeIp($ip);

        if ($max <= 0 || $ip === '') {
            return false;
        }

        return $this->failedAttempts($ip) >= $max;
    }

    /**
     * Seconds left before this IP may try again (0 when it is not locked out).
     */
    public function remainingLockout(string $ip): int
    {
        $ip = $this->normalizeIp($ip);

        if ($ip === '' || !$this->isLockedOut($ip)) {
            return 0;
        }

        $lockout = $this->lockoutSeconds();

        try {
            $db = $this->app->db();

            $oldest = $db->fetchColumn(
                'SELECT MIN(' . $db->quoteIdent('created_at') . ') FROM ' . $this->attemptsTable()
                . ' WHERE ' . $db->quoteIdent('ip') . ' = ?'
                . ' AND ' . $db->quoteIdent('success') . ' = 0'
                . ' AND ' . $db->quoteIdent('created_at') . ' >= ?',
                [$ip, $this->windowStart()]
            );
        } catch (\Throwable $e) {
            return $lockout;
        }

        if (!is_string($oldest) || $oldest === '') {
            return $lockout;
        }

        $timestamp = strtotime($oldest);

        if ($timestamp === false) {
            return $lockout;
        }

        // The lock lifts as soon as the oldest failure inside the window ages out.
        $remaining = ($timestamp + $lockout) - time();

        return $remaining > 0 ? $remaining : 0;
    }

    /* --------------------------------------------------------------------
     | Diagnostics used by the login screen
     */

    /**
     * Why the last {@see self::attempt()} was refused, ready to be displayed.
     */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * False when `security.admin_panel.password_hash` is still empty, i.e. the
     * panel has never been through setup.php.
     */
    public function isConfigured(): bool
    {
        return (string) $this->app->config('security.admin_panel.password_hash', '') !== '';
    }

    /**
     * The `security.admin_panel.enabled` switch.
     */
    public function isEnabled(): bool
    {
        return (bool) $this->app->config('security.admin_panel.enabled', true);
    }

    /* --------------------------------------------------------------------
     | Internals — session
     */

    /**
     * Install the authenticated identity in a freshly minted session.
     */
    private function establishSession(string $user, string $ip): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        // A new id for a new privilege level, and no leftovers from before.
        if (!headers_sent()) {
            session_regenerate_id(true);
        }

        $flash = $_SESSION[self::KEY_FLASH] ?? null;
        $locale = $_SESSION[self::KEY_LOCALE] ?? null;

        $_SESSION = [];

        if (is_array($flash)) {
            $_SESSION[self::KEY_FLASH] = $flash;
        }

        if (is_string($locale)) {
            $_SESSION[self::KEY_LOCALE] = $locale;
        }

        $now = time();

        $_SESSION[self::KEY_AUTH] = [
            'user'           => $user,
            'ip'             => $ip,
            'login_at'       => $now,
            'seen_at'        => $now,
            'regenerated_at' => $now,
            'fingerprint'    => $this->fingerprint(),
        ];

        // The pre-login token must not stay valid after the privilege change.
        Csrf::rotate();
        Csrf::token();
    }

    /**
     * Drop the identity but keep the session (flash messages must survive).
     */
    private function forget(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::KEY_AUTH]);
        }
    }

    /**
     * A cheap browser fingerprint: enough to invalidate a stolen cookie replayed
     * from another client, cheap enough to never hit the database.
     */
    private function fingerprint(): string
    {
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        return substr(hash('sha256', self::SESSION_NAME . '|' . $agent), 0, 32);
    }

    /**
     * Cookie path scoped to the directory the panel runs in, so the cookie is
     * never sent to the rest of the site.
     */
    private function cookiePath(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $path = $script === '' ? '/' : rtrim(dirname($script), '/');

        if ($path === '' || $path[0] !== '/') {
            $path = '/' . ltrim($path, '/');
        }

        return rtrim($path, '/') . '/';
    }

    private function isHttps(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));

        if ($https !== '' && $https !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        // Only meaningful behind a proxy the deployment chose to trust.
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));

        return $proto === 'https' && Request::ip() !== (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /* --------------------------------------------------------------------
     | Internals — login attempts
     */

    /**
     * Number of failed attempts from this IP inside the lockout window.
     */
    private function failedAttempts(string $ip): int
    {
        try {
            $db = $this->app->db();

            return (int) $db->fetchColumn(
                'SELECT COUNT(*) FROM ' . $this->attemptsTable()
                . ' WHERE ' . $db->quoteIdent('ip') . ' = ?'
                . ' AND ' . $db->quoteIdent('success') . ' = 0'
                . ' AND ' . $db->quoteIdent('created_at') . ' >= ?',
                [$ip, $this->windowStart()]
            );
        } catch (\Throwable $e) {
            // No table yet (fresh install) or the database is down: do not lock
            // the administrator out of the panel because of it.
            return 0;
        }
    }

    private function recordAttempt(string $ip, string $username, bool $success): void
    {
        try {
            $this->app->db()->insert('login_attempts', [
                'ip'         => $ip === '' ? '0.0.0.0' : mb_substr($ip, 0, 45, 'UTF-8'),
                'username'   => $username === '' ? null : mb_substr($username, 0, 64, 'UTF-8'),
                'success'    => $success ? 1 : 0,
                'created_at' => App::now(),
            ]);
        } catch (\Throwable $e) {
            // Throttling is best effort; never break the login because of it.
        }
    }

    private function clearAttempts(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        try {
            $this->app->db()->delete('login_attempts', ['ip' => $ip]);
        } catch (\Throwable $e) {
            // Ignored on purpose.
        }
    }

    /**
     * Housekeeping so the table cannot grow forever on a public panel.
     */
    private function pruneAttempts(): void
    {
        try {
            $db = $this->app->db();

            $db->query(
                'DELETE FROM ' . $this->attemptsTable()
                . ' WHERE ' . $db->quoteIdent('created_at') . ' < ?',
                [date('Y-m-d H:i:s', time() - (self::ATTEMPT_RETENTION_DAYS * 86400))]
            );
        } catch (\Throwable $e) {
            // Ignored on purpose.
        }
    }

    /** Quoted, prefixed `login_attempts` table name. */
    private function attemptsTable(): string
    {
        $db = $this->app->db();

        return $db->quoteIdent($db->table('login_attempts'));
    }

    /** Start of the throttling window as a comparable timestamp string. */
    private function windowStart(): string
    {
        return date('Y-m-d H:i:s', time() - $this->lockoutSeconds());
    }

    /* --------------------------------------------------------------------
     | Internals — misc
     */

    private function audit(string $action, string $user, string $ip, array $meta = []): void
    {
        $actor = 'panel:' . ($user === '' ? 'anonymous' : $user);

        $this->app->audit()->log($actor, $action, null, $meta, $ip === '' ? null : $ip);
    }

    /**
     * Push a message into the flash bag used by the panel's flash() helper.
     *
     * @param string $type success|error|warning|info
     */
    private function flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $message === '') {
            return;
        }

        $bag = $_SESSION[self::KEY_FLASH] ?? [];

        if (!is_array($bag)) {
            $bag = [];
        }

        $bag[] = ['type' => $type, 'message' => $message];
        $_SESSION[self::KEY_FLASH] = array_slice($bag, -10);
    }

    /** Interface language of the panel. */
    private function locale(): string
    {
        if (function_exists('panel_locale')) {
            return panel_locale();
        }

        return $this->app->defaultLocale();
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return mb_substr($ip, 0, 45, 'UTF-8');
    }

    private function lifetime(): int
    {
        $lifetime = (int) $this->app->config('security.admin_panel.session_lifetime', self::DEFAULT_LIFETIME);

        // Between five minutes and one week.
        return max(300, min(604800, $lifetime));
    }

    private function maxAttempts(): int
    {
        return max(0, (int) $this->app->config('security.admin_panel.max_attempts', self::DEFAULT_MAX_ATTEMPTS));
    }

    private function lockoutSeconds(): int
    {
        $seconds = (int) $this->app->config('security.admin_panel.lockout_seconds', self::DEFAULT_LOCKOUT);

        return max(30, min(86400, $seconds));
    }
}
