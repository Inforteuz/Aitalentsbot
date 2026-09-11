<?php

declare(strict_types=1);

/**
 * Andijon AI Talents — admin panel front controller.
 *
 * Every panel URL goes through this file:
 *
 *     index.php?p=<page>&a=<action>&...
 *
 * `p` selects the controller, `a` the action inside it. The router itself only
 * does four things — resolve the page, enforce authentication, verify the CSRF
 * token of POST requests and dispatch — everything else lives in the
 * controllers under src/Admin/Controller/.
 */

use AiTalents\Admin\Auth;
use AiTalents\Admin\Csrf;
use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;

/** @var App $app */
$app = require __DIR__ . '/bootstrap.php';

/* -------------------------------------------------------------------------
 | Routing table: ?p=<page> => controller class in AiTalents\Admin\Controller
 */
$panelRoutes = [
    'login'         => 'AuthController',
    'logout'        => 'AuthController',
    'dashboard'     => 'DashboardController',
    'registrations' => 'RegistrationController',
    'registration'  => 'RegistrationController',
    'users'         => 'UserController',
    'broadcast'     => 'BroadcastController',
    'broadcasts'    => 'BroadcastController',
    'settings'      => 'SettingsController',
    'logs'          => 'LogController',
    'audit'         => 'LogController',
    'export'        => 'ExportController',
];

/** Pages reachable without a session. Everything else requires a login. */
$panelPublicPages = ['login'];

if (!function_exists('panel_error_page')) {
    /**
     * Render a friendly error page (or a JSON envelope for fetch() callers).
     *
     * The stack trace never reaches the browser: it goes to the log file only.
     */
    function panel_error_page(View $view, int $status, string $title, string $message): void
    {
        if (Request::wantsJson()) {
            Request::json(['ok' => false, 'error' => $message, 'status' => $status], $status);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $data = [
            'code'    => $status,
            'status'  => $status,
            'title'   => $title,
            'message' => $message,
        ];

        if ($view->exists('error')) {
            try {
                $view->render('error', $data);

                return;
            } catch (\Throwable $e) {
                // Fall through to the dependency-free page below.
            }
        }

        // Last resort: no template, no stylesheet, no JavaScript — but valid HTML.
        echo '<!doctype html><html lang="' . e(panel_locale()) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . e((string) $status . ' — ' . $title) . '</title>'
            . '<link rel="stylesheet" href="' . e(asset('app.css')) . '"></head><body class="panel-error">'
            . '<h1>' . e((string) $status) . '</h1>'
            . '<h2>' . e($title) . '</h2>'
            . '<p>' . e($message) . '</p>'
            . '<p><a href="' . e(url(['p' => 'dashboard'])) . '">' . e(t('panel.nav_dashboard')) . '</a></p>'
            . '</body></html>';
    }
}

/* -------------------------------------------------------------------------
 | Request
 */
$auth = panel_auth();
$view = new View($app, __DIR__ . '/views');

$page = strtolower(trim(Request::str('p')));
$page = (string) preg_replace('/[^a-z0-9_]/', '', $page);
$page = $page === '' ? View::DEFAULT_PAGE : substr($page, 0, 32);

$action = strtolower(trim(Request::str('a')));
$action = substr((string) preg_replace('/[^a-z0-9_\-]/', '', $action), 0, 40);

$view->setPage($page);
$view->share([
    'page'          => $page,
    'action'        => $action,
    'auth'          => $auth,
    'admin_user'    => $auth->user(),
    'panel_locale'  => panel_locale(),
    'panel_version' => defined('AITALENTS_VERSION') ? (string) AITALENTS_VERSION : '1.0.0',
]);

/* -------------------------------------------------------------------------
 | Unknown page => 404
 */
if (!isset($panelRoutes[$page])) {
    panel_error_page($view, 404, t('error.not_found'), t('panel.flash_not_found'));
    exit;
}

/* -------------------------------------------------------------------------
 | Authentication, then CSRF for every state-changing request
 |--------------------------------------------------------------------------
 | The order matters: an expired session is answered with a redirect to the
 | login screen (helpful) instead of a bare 419 (confusing).
 */
if (!in_array($page, $panelPublicPages, true)) {
    $auth->requireAuth();
}

if (Request::isPost()) {
    Csrf::verifyOrFail();
}

/* -------------------------------------------------------------------------
 | Dispatch
 */
try {
    $controller = 'AiTalents\\Admin\\Controller\\' . $panelRoutes[$page];

    if (!class_exists($controller) || !method_exists($controller, 'handle')) {
        throw new \RuntimeException('Admin controller ' . $controller . ' is missing or has no handle() method.');
    }

    $instance = new $controller($app, $view);
    $instance->handle($action);
} catch (\Throwable $e) {
    // The operator sees a sentence, the log file keeps the details.
    try {
        $app->logger()->error('panel.dispatch_failed', [
            'page'      => $page,
            'action'    => $action,
            'method'    => Request::method(),
            'ip'        => Request::ip(),
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
    } catch (\Throwable $loggingFailure) {
        // A broken log directory must not replace the error page with a fatal.
    }

    panel_error_page($view, 500, t('common.error'), t('error.generic'));
}
