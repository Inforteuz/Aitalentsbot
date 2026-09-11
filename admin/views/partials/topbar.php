<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — sticky top bar.
 *
 * Holds the sidebar toggle (the sidebar collapses on narrow screens), the page
 * title, the interface-language switch and the signed-in operator's menu.
 *
 * The toggle is a plain <button> carrying `data-sidebar-toggle`; assets/app.js
 * binds it and keeps `aria-expanded` in sync. No inline handler is used —
 * the panel's CSP would refuse it.
 *
 * Variables: $title, $subtitle, $admin_user, $page, $panel_locale, $view, $app.
 */

use AiTalents\Lang;

/** @var \AiTalents\Admin\View $view */
/** @var \AiTalents\App $app */

$barPage     = isset($page) && is_string($page) && $page !== '' ? $page : $view->currentPage();
$barTitle    = isset($title) && is_string($title) && $title !== '' ? $title : t('panel.title');
$barSubtitle = isset($subtitle) && is_string($subtitle) ? trim($subtitle) : '';
$barUser     = isset($admin_user) && is_string($admin_user) ? trim($admin_user) : '';
$barLocale   = isset($panel_locale) && is_string($panel_locale) && $panel_locale !== ''
    ? $panel_locale
    : panel_locale();

// The operator's initial, used as the avatar glyph.
$barInitial = $barUser === '' ? '·' : mb_strtoupper(mb_substr($barUser, 0, 1, 'UTF-8'), 'UTF-8');

// Locales enabled in config.php, with their native names from Lang::available().
$barLocales = [];

foreach ($app->locales() as $barCode) {
    if (!is_string($barCode) || $barCode === '') {
        continue;
    }

    $barLocales[$barCode] = Lang::available()[$barCode] ?? strtoupper($barCode);
}

?>
<header class="topbar">
    <button class="btn btn--ghost btn--sm sidebar__toggle" type="button"
            data-sidebar-toggle aria-controls="sidebar" aria-expanded="false"
            aria-label="<?= e(t('panel.toggle_menu')) ?>" title="<?= e(t('panel.toggle_menu')) ?>">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">
            <path d="M4 7h16M4 12h16M4 17h16"/>
        </svg>
    </button>

    <div class="topbar__title">
        <h1><?= e($barTitle) ?></h1>
        <?php if ($barSubtitle !== '') { ?>
            <p class="meta"><?= e($barSubtitle) ?></p>
        <?php } ?>
    </div>

    <?php if (count($barLocales) > 1) { ?>
        <div class="filters" role="group" aria-label="<?= e(t('common.language')) ?>">
            <?php foreach ($barLocales as $barCode => $barName) { ?>
                <a class="chip <?= $barCode === $barLocale ? 'is-active' : '' ?>"
                   href="<?= e(url(['p' => $barPage, 'lang' => $barCode])) ?>"
                   hreflang="<?= e($barCode) ?>"
                   <?= $barCode === $barLocale ? 'aria-current="true"' : '' ?>><?= e($barName) ?></a>
            <?php } ?>
        </div>
    <?php } ?>

    <div class="meta">
        <?php if ($barUser !== '') { ?>
            <span class="avatar" aria-hidden="true"><?= e($barInitial) ?></span>
            <span class="meta__row"><?= e(t('panel.signed_in_as', ['user' => $barUser])) ?></span>
        <?php } ?>
        <form class="topbar__form" method="post" action="<?= e(url(['p' => 'logout'])) ?>">
            <?= \AiTalents\Admin\Csrf::field() ?>
        <button class="btn btn--ghost btn--sm" type="submit">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" focusable="false">
                <path d="M14 5.5H6.5a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1H14"/>
                <path d="M17 8.5 20.5 12 17 15.5"/><path d="M20 12h-9"/>
            </svg>
            <span><?= e(t('panel.nav_logout')) ?></span>
        </button>
        </form>
    </div>
</header>
<?php unset($barLocales, $barCode, $barName, $barInitial); ?>
