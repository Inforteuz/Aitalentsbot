<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — admin panel layout.
 *
 * Every page rendered by {@see \AiTalents\Admin\View::render()} lands here: the
 * template itself is captured first and handed over as `$content`.
 *
 * The layout knows two shapes:
 *  - the full dashboard chrome (sidebar + topbar + content + footer);
 *  - a bare, centred shell used by the login screen, which has no navigation
 *    because the visitor is not authenticated yet. AuthController asks for the
 *    `layout_login` template and falls back to this file, so the switch below is
 *    what honours that intent.
 *
 * The panel's Content-Security-Policy forbids inline CSS and JavaScript, so this
 * document contains neither a <style> nor an inline <script>: all styling lives
 * in assets/app.css and all behaviour in assets/app.js. The favicon is an inline
 * data: URI, which `img-src 'self' data:` explicitly allows.
 *
 * Available variables (see View::layoutData() and admin/index.php):
 *   $content, $slot, $view_name, $page, $action, $title, $subtitle,
 *   $auth, $admin_user, $panel_locale, $panel_version, $view, $app
 */

use AiTalents\Admin\Csrf;

/** @var \AiTalents\Admin\View $view */
/** @var \AiTalents\App $app */

$layoutPage     = isset($page) && is_string($page) && $page !== '' ? $page : $view->currentPage();
$layoutView     = isset($view_name) && is_string($view_name) ? $view_name : '';
$layoutTitle    = isset($title) && is_string($title) && $title !== '' ? $title : t('panel.title');
$layoutSubtitle = isset($subtitle) && is_string($subtitle) ? $subtitle : '';
$layoutBody     = isset($content) && is_string($content) ? $content : '';
$layoutLocale   = isset($panel_locale) && is_string($panel_locale) && $panel_locale !== ''
    ? $panel_locale
    : panel_locale();
$layoutVersion  = isset($panel_version) && is_string($panel_version) && $panel_version !== ''
    ? $panel_version
    : (defined('AITALENTS_VERSION') ? (string) AITALENTS_VERSION : '1.0.0');
$layoutUser     = isset($admin_user) && is_string($admin_user) ? $admin_user : null;

// The login screen (and anything else explicitly asking for it) is rendered
// without the navigation chrome.
$layoutBare = $layoutView === 'login' || $layoutPage === 'login';

/*
 * Favicon: a small "AI" monogram on the campaign gradient, embedded as a base64
 * data: URI so the panel never reaches out for an external file. Base64 avoids
 * having to percent-encode the `#` of every colour.
 */
$layoutFavicon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
    . '<defs><linearGradient id="b" x1="0" y1="0" x2="1" y2="1">'
    . '<stop offset="0" stop-color="#0a1b3d"/><stop offset="1" stop-color="#1273d4"/>'
    . '</linearGradient></defs>'
    . '<rect width="64" height="64" rx="14" fill="url(#b)"/>'
    . '<path d="M15 47 L24 17 L30 17 L39 47" fill="none" stroke="#7cf3ff" stroke-width="5"'
    . ' stroke-linecap="round" stroke-linejoin="round"/>'
    . '<path d="M20 38 H34" fill="none" stroke="#7cf3ff" stroke-width="5" stroke-linecap="round"/>'
    . '<rect x="43" y="17" width="5" height="30" rx="2.5" fill="#7cf3ff"/>'
    . '</svg>';

?>
<!doctype html>
<html lang="<?= e($layoutLocale) ?>" data-page="<?= e($layoutPage) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
<?php
// Strings that admin/assets/app.js needs at runtime. The CSP forbids inline
// script, so they travel as JSON in a meta tag instead of a <script> block.
$layoutJsStrings = [];
foreach ([
    'panel.copied',
    'panel.flash_error',
    'panel.confirm_leave',
    'panel.bulk_none_selected',
    'panel.broadcast_empty_text',
    'panel.broadcast_confirm_start',
    'panel.broadcast_recipients',
    'panel.broadcast_paused',
    'panel.broadcast_done',
] as $layoutJsKey) {
    $layoutJsStrings[$layoutJsKey] = t($layoutJsKey);
}
?>
    <meta name="panel-i18n" content="<?= e((string) json_encode($layoutJsStrings, JSON_UNESCAPED_UNICODE)) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <meta name="theme-color" content="#0d2137">
    <meta name="color-scheme" content="light">
    <meta name="description" content="<?= e(t('panel.title')) ?>">
    <title><?= e($layoutTitle) ?> — <?= e(t('panel.brand')) ?></title>
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,<?= e(base64_encode($layoutFavicon)) ?>">
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <script src="<?= e(asset('app.js')) ?>" defer></script>
</head>
<body class="<?= $layoutBare ? 'is-login' : 'is-panel' ?>" data-page="<?= e($layoutPage) ?>">
<?php if ($layoutBare) { ?>
    <main class="login" id="main">
        <?php $view->partial('flash'); ?>
        <?= $layoutBody ?>
        <footer class="meta">
            <div class="meta__row"><?= e(t('panel.footer')) ?></div>
            <div class="meta__row"><?= e(t('panel.version', ['version' => $layoutVersion])) ?></div>
        </footer>
    </main>
<?php } else { ?>
    <a class="skip-link btn btn--sm" href="#main"><?= e(t('panel.skip_to_content')) ?></a>
    <div class="app">
        <?php $view->partial('nav', ['page' => $layoutPage]); ?>
        <div class="main">
            <?php
            $view->partial('topbar', [
                'page'          => $layoutPage,
                'title'         => $layoutTitle,
                'subtitle'      => $layoutSubtitle,
                'admin_user'    => $layoutUser,
                'panel_locale'  => $layoutLocale,
            ]);
            ?>
            <main class="content" id="main">
                <?php $view->partial('flash'); ?>
                <?= $layoutBody ?>
            </main>
            <footer class="meta">
                <div class="meta__row">
                    <span><?= e(t('panel.footer')) ?></span>
                    <span class="chip"><?= e(t('panel.version', ['version' => $layoutVersion])) ?></span>
                </div>
            </footer>
        </div>
        <div class="app__backdrop" data-close="sidebar" aria-hidden="true"></div>
    </div>
<?php } ?>
</body>
</html>
