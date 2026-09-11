<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Auth;
use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Lang;

/**
 * Login and logout of the web admin panel.
 *
 * The controller answers two pages of the front controller: `?p=login` (the
 * form and its POST handler) and `?p=logout`. Everything security relevant —
 * throttling, the constant-time credential comparison, session hardening and
 * the audit entries — lives in {@see Auth}; this class only turns its answers
 * into a screen or a redirect.
 *
 * A failed attempt re-renders the form instead of redirecting, so the message
 * is visible even when the login screen uses a layout without a flash area.
 */
final class AuthController
{
    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        $page = $this->view->currentPage();

        if ($page === 'logout' || $action === 'logout') {
            $this->logout();

            return;
        }

        $this->login();
    }

    /* --------------------------------------------------------------------
     | Actions
     */

    /**
     * Show the login form, or verify the credentials it submitted.
     */
    public function login(): void
    {
        $auth = $this->auth();

        // Already signed in: there is nothing to log into.
        if ($auth->check()) {
            Request::redirect(View::link(['p' => 'dashboard']));
        }

        $ip = Request::ip();
        $username = $this->rememberedUsername();
        $error = null;

        if (Request::isPost()) {
            // The CSRF token was verified by the front controller.
            $username = Request::str('username');

            if ($auth->attempt($username, $this->submittedPassword(), $ip)) {
                $this->forgetOld();

                Request::redirect(View::link(['p' => 'dashboard']));
            }

            $error = $auth->lastError() ?? $this->t('panel.login_error');

            // Keep the username in the field, never the password.
            $this->rememberOld(['username' => $username]);
        }

        $lockedOut = $auth->isLockedOut($ip);
        $remaining = $lockedOut ? $auth->remainingLockout($ip) : 0;

        $data = [
            'title'           => $this->t('panel.login_title'),
            'subtitle'        => $this->t('panel.login_subtitle'),
            'error'           => $error,
            'configured'      => $auth->isConfigured(),
            'enabled'         => $auth->isEnabled(),
            'locked'          => $lockedOut,
            'lockoutSeconds'  => $remaining,
            'lockoutMinutes'  => $remaining > 0 ? (int) ceil($remaining / 60) : 0,
            'username'        => $username,
        ];

        // A dedicated bare layout is used when the view agent provides one.
        $layout = $this->view->exists('layout_login') ? 'layout_login' : 'layout';

        $this->view->render('login', $data, $layout);

        $this->forgetOld();
    }

    /**
     * Destroy the session and send the operator back to the login screen.
     */
    public function logout(): void
    {
        // GET logout is a cross-site forced-logout hole: any page could embed
        // <img src=".../admin/index.php?p=logout"> and sign the operator out.
        // The front controller verifies the CSRF token on POST, so requiring a
        // POST here is what actually protects the action.
        if (!Request::isPost()) {
            Request::redirect(View::link(['p' => 'dashboard']));
        }

        $auth = $this->auth();
        $wasSignedIn = $auth->user() !== null;

        // Auth::logout() writes its own audit entry and rotates the CSRF token.
        $auth->logout();

        if ($wasSignedIn) {
            $this->flash('success', $this->t('panel.logout_done'));
        }

        Request::redirect(View::link(['p' => 'login']));
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * The Auth instance created by admin/bootstrap.php (a fresh one in tests).
     */
    private function auth(): Auth
    {
        if (function_exists('panel_auth')) {
            $auth = panel_auth();

            if ($auth instanceof Auth) {
                return $auth;
            }
        }

        return new Auth($this->app);
    }

    /**
     * The submitted password, untrimmed.
     *
     * Request::str() would strip leading and trailing spaces, which are perfectly
     * legal password characters, so the raw body value is read instead (control
     * characters are still removed by Request::post()).
     */
    private function submittedPassword(): string
    {
        $value = Request::post('password', '');

        return is_string($value) ? $value : '';
    }

    /**
     * The username to prefill the form with after a failed attempt.
     */
    private function rememberedUsername(): string
    {
        if (!function_exists('old')) {
            return '';
        }

        $value = old('username', '');

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @param array<string,mixed> $input
     */
    private function rememberOld(array $input): void
    {
        if (function_exists('remember_old')) {
            remember_old($input);
        }
    }

    private function forgetOld(): void
    {
        if (function_exists('forget_old')) {
            forget_old();
        }
    }

    /**
     * Queue a flash message for the next request.
     */
    private function flash(string $type, string $message): void
    {
        if (function_exists('flash') && $message !== '') {
            flash($type, $message);
        }
    }

    /**
     * Translate a key in the panel's interface language.
     *
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
