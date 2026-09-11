<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — sidebar navigation.
 *
 * Rendered once per page by admin/views/layout.php. The icons are hand written
 * inline SVG (no icon font, no sprite file, no external request) drawn with
 * `currentColor` so the stylesheet controls their colour in both the dark and
 * the light theme.
 *
 * The highlighted entry comes from {@see \AiTalents\Admin\View::active()}, which
 * also knows that the registration detail page belongs to the "Arizalar" entry.
 *
 * Variables: $view, $app (the layout also passes $page for completeness).
 */

/** @var \AiTalents\Admin\View $view */

/**
 * One 24×24 stroke icon.
 *
 * The SVG is decorative — the readable label sits next to it — so it is hidden
 * from assistive technology and kept out of the tab order.
 */
$navIcon = static function (string $name): string {
    $paths = [
        'dashboard'     => '<path d="M4 12 12 4l8 8"/><path d="M6.5 10.5V19a1 1 0 0 0 1 1h3v-5h3v5h3a1 1 0 0 0 1-1v-8.5"/>',
        'registrations' => '<rect x="5" y="3.5" width="14" height="17" rx="2.5"/><path d="M9 8h6M9 12h6M9 16h4"/>',
        'users'         => '<circle cx="9" cy="9" r="3.2"/><path d="M3.5 19.5a5.5 5.5 0 0 1 11 0"/>'
            . '<path d="M16 6.2a3.2 3.2 0 0 1 0 6"/><path d="M17.2 14.6a5.5 5.5 0 0 1 3.3 4.9"/>',
        'broadcast'     => '<path d="M4 10v4a1 1 0 0 0 1 1h2.5L14 19.5v-15L7.5 9H5a1 1 0 0 0-1 1z"/>'
            . '<path d="M17.5 8.5a5 5 0 0 1 0 7"/>',
        'broadcasts'    => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'settings'      => '<circle cx="12" cy="12" r="3"/>'
            . '<path d="M12 3.5v2M12 18.5v2M20.5 12h-2M5.5 12h-2M18 6l-1.4 1.4M7.4 16.6 6 18M18 18l-1.4-1.4M7.4 7.4 6 6"/>',
        'logs'          => '<path d="M6 3.5h8l4 4V20a.5.5 0 0 1-.5.5h-11A.5.5 0 0 1 6 20z"/>'
            . '<path d="M14 3.5v4h4"/><path d="M9 13h6M9 16.5h4"/>',
        'audit'         => '<path d="M12 3.5 19 6v5.5c0 4-3 7.2-7 9-4-1.8-7-5-7-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'export'        => '<path d="M12 4v10"/><path d="m8 10.5 4 4 4-4"/><path d="M5 17.5v1.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-1.5"/>',
        'logout'        => '<path d="M14 5.5H6.5a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1H14"/>'
            . '<path d="M17 8.5 20.5 12 17 15.5"/><path d="M20 12h-9"/>',
    ];

    $body = $paths[$name] ?? $paths['dashboard'];

    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
};

/**
 * The navigation entries, in sidebar order: page key => language key.
 *
 * @var array<int,array{page:string,icon:string,label:string}> $navItems
 */
$navItems = [
    ['page' => 'dashboard',     'icon' => 'dashboard',     'label' => 'panel.nav_dashboard'],
    ['page' => 'registrations', 'icon' => 'registrations', 'label' => 'panel.nav_registrations'],
    ['page' => 'users',         'icon' => 'users',         'label' => 'panel.nav_users'],
    ['page' => 'broadcast',     'icon' => 'broadcast',     'label' => 'panel.nav_broadcast'],
    ['page' => 'broadcasts',    'icon' => 'broadcasts',    'label' => 'panel.nav_broadcasts'],
    ['page' => 'export',        'icon' => 'export',        'label' => 'panel.nav_export'],
    ['page' => 'settings',      'icon' => 'settings',      'label' => 'panel.nav_settings'],
    ['page' => 'logs',          'icon' => 'logs',          'label' => 'panel.nav_logs'],
    ['page' => 'audit',         'icon' => 'audit',         'label' => 'panel.nav_audit'],
];

?>
<aside class="sidebar" id="sidebar" data-sidebar>
    <a class="sidebar__brand" href="<?= e(url(['p' => 'dashboard'])) ?>">
        <span class="avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" focusable="false">
                <path d="M5 19 10 5h2l5 14"/><path d="M7.2 14.5h7.6"/><path d="M20 5v14"/>
            </svg>
        </span>
        <span class="meta">
            <span class="meta__row"><?= e(t('panel.brand')) ?></span>
            <span class="meta__row"><?= e(t('panel.subtitle')) ?></span>
        </span>
    </a>

    <nav class="sidebar__nav" aria-label="<?= e(t('panel.menu')) ?>">
        <?php foreach ($navItems as $navItem) { ?>
            <?php $navActive = $view->active($navItem['page']); ?>
            <a class="nav__item <?= e($navActive) ?>"
               href="<?= e(url(['p' => $navItem['page']])) ?>"
               <?= $navActive !== '' ? 'aria-current="page"' : '' ?>>
                <?= $navIcon($navItem['icon']) ?>
                <span><?= e(t($navItem['label'])) ?></span>
            </a>
        <?php } ?>
    </nav>

    <nav class="sidebar__nav" aria-label="<?= e(t('panel.nav_logout')) ?>">
        <form class="nav__form" method="post" action="<?= e(url(['p' => 'logout'])) ?>">
            <?= \AiTalents\Admin\Csrf::field() ?>
            <button class="nav__item" type="submit">
                <?= $navIcon('logout') ?>
                <span><?= e(t('panel.nav_logout')) ?></span>
            </button>
        </form>
    </nav>
</aside>
<?php unset($navIcon, $navItems); ?>
