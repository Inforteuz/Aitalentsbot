<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — bot users.
 *
 * Everyone who ever wrote to the bot, with the two switches the operator needs:
 * block / unblock (the same flag the broadcast sender sets when Telegram says
 * "bot was blocked by the user") and the in-Telegram administrator flag.
 *
 * Administrators listed in config.php cannot be revoked from here — the row
 * says so through the title of its button instead of pretending otherwise.
 *
 * Every action posts a form of its own, so nothing here can be triggered by a
 * crafted GET link; the CSRF token is verified by the front controller.
 *
 * Variables handed over by UserController::list():
 *   $title, $subtitle, $rows, $total, $page, $pages, $perPage, $perPageOptions,
 *   $filters, $sort, $dir, $sortable, $locales, $configAdminIds, $blockedCount,
 *   $query, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */

$usRows           = isset($rows) && is_array($rows) ? $rows : [];
$usFilters        = isset($filters) && is_array($filters) ? $filters : [];
$usQuery          = isset($query) && is_array($query) ? $query : ['p' => 'users'];
$usSortable       = isset($sortable) && is_array($sortable) ? $sortable : [];
$usLocales        = isset($locales) && is_array($locales) ? $locales : [];
$usPerPageOptions = isset($perPageOptions) && is_array($perPageOptions) ? $perPageOptions : [25, 50, 100];

$usSort         = isset($sort) && is_string($sort) && $sort !== '' ? $sort : 'created_at';
$usDir          = isset($dir) && is_string($dir) && strtolower($dir) === 'asc' ? 'asc' : 'desc';
$usTotal        = isset($total) && is_numeric($total) ? max(0, (int) $total) : count($usRows);
$usPages        = isset($pages) && is_numeric($pages) ? max(1, (int) $pages) : 1;
$usPage         = isset($page) && is_numeric($page) ? max(1, (int) $page) : 1;
$usPerPage      = isset($perPage) && is_numeric($perPage) ? max(1, (int) $perPage) : (int) $usPerPageOptions[0];
$usBlockedCount = isset($blockedCount) && is_numeric($blockedCount) ? max(0, (int) $blockedCount) : 0;
$usFiltered     = $usFilters !== [];

/** Telegram ids compiled into config.php — the panel cannot revoke them. */
$usConfigAdmins = [];

foreach (isset($configAdminIds) && is_array($configAdminIds) ? $configAdminIds : [] as $usAdminId) {
    if (is_numeric($usAdminId)) {
        $usConfigAdmins[] = (int) $usAdminId;
    }
}

/** Interface languages offered by the locale filter. */
$usLocaleOptions = [];

foreach ($usLocales as $usLocaleOption) {
    if (!is_array($usLocaleOption)) {
        continue;
    }

    $usValue = trim((string) ($usLocaleOption['value'] ?? ''));

    if ($usValue !== '') {
        $usLocaleOptions[] = ['value' => $usValue, 'label' => (string) ($usLocaleOption['label'] ?? $usValue)];
    }
}

/** Page-size selector options. */
$usPerPageChoices = [];

foreach ($usPerPageOptions as $usOption) {
    if (is_numeric($usOption)) {
        $usPerPageChoices[] = ['value' => (string) (int) $usOption, 'label' => (string) (int) $usOption];
    }
}

/** Yes / no options shared by the "blocked" and "registered" selectors. */
$usFlagOptions = [
    ['value' => '1', 'label' => t('common.yes')],
    ['value' => '0', 'label' => t('common.no')],
];

/** POST target of an action, current filters preserved. */
$usAction = static function (string $action) use ($usQuery): string {
    return url(array_merge($usQuery, ['a' => $action]));
};

/** The link behind a sortable column header. */
$usSortLink = static function (string $column) use ($usQuery, $usSort, $usDir): string {
    if ($usSort === $column) {
        $next = $usDir === 'asc' ? 'desc' : 'asc';
    } else {
        $next = 'desc';
    }

    return url(array_merge($usQuery, ['sort' => $column, 'dir' => $next, 'page' => null]));
};

/** One header cell — a sort link when the repository can order by that column. */
$usHeader = static function (string $column, string $label) use ($usSortable, $usSort, $usDir, $usSortLink): string {
    if (!in_array($column, $usSortable, true)) {
        return '<th scope="col">' . e($label) . '</th>';
    }

    $isActive = $usSort === $column;
    $class = $isActive ? ' class="is-sorted-' . ($usDir === 'asc' ? 'asc' : 'desc') . '"' : '';
    $aria = $isActive ? ($usDir === 'asc' ? 'ascending' : 'descending') : 'none';

    return '<th scope="col" aria-sort="' . $aria . '">'
        . '<a' . $class . ' data-sync-query data-sync-drop="page"'
        . ' href="' . e($usSortLink($column)) . '"'
        . ' title="' . e(t('panel.sort_by')) . '">' . e($label) . '</a>'
        . '</th>';
};

?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('common.filter')) ?></h2>
        <div class="card__actions">
            <span class="chip"><?= e(t('common.total')) ?>: <?= e(number_format((float) $usTotal, 0, ',', ' ')) ?></span>
            <span class="chip"><?= e(t('panel.card_blocked')) ?>: <?= e((string) $usBlockedCount) ?></span>
        </div>
    </div>
    <div class="card__body">
        <?php
        $view->partial('filters', [
            'action' => 'users',
            'values' => $usFilters,
            'hidden' => ['sort' => $usSort, 'dir' => $usDir],
            'fields' => [
                [
                    'type'        => 'search',
                    'name'        => 'q',
                    'label'       => t('common.search'),
                    'placeholder' => t('panel.quick_search_placeholder'),
                ],
                [
                    'type'    => 'select',
                    'name'    => 'blocked',
                    'label'   => t('panel.filter_blocked'),
                    'options' => $usFlagOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'registered',
                    'label'   => t('panel.filter_registered'),
                    'options' => $usFlagOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'locale',
                    'label'   => t('panel.filter_locale'),
                    'options' => $usLocaleOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'per_page',
                    'label'   => t('panel.per_page'),
                    'value'   => (string) $usPerPage,
                    'empty'   => false,
                    'options' => $usPerPageChoices,
                ],
            ],
        ]);
        ?>
    </div>
</section>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('panel.users_title')) ?></h2>
    </div>

    <?php if ($usRows === []) { ?>
        <div class="card__body">
            <div class="empty">
                <svg class="empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <circle cx="12" cy="8.5" r="3.5"/>
                    <path d="M5 20c0-3.6 3.1-5.5 7-5.5s7 1.9 7 5.5"/>
                </svg>
                <p class="empty__title">
                    <?= e($usFiltered ? t('panel.empty_search') : t('panel.empty_users')) ?>
                </p>
                <p class="empty__text"><?= e(t('panel.empty_hint')) ?></p>
                <?php if ($usFiltered) { ?>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'users'])) ?>">
                        <?= e(t('panel.filter_reset')) ?>
                    </a>
                <?php } ?>
            </div>
        </div>
    <?php } else { ?>
        <div class="card__body card__body--flush">
            <div class="table-wrap table-wrap--sticky">
                <table class="table">
                    <thead>
                    <tr>
                        <?= $usHeader('telegram_id', t('panel.th_telegram_id')) ?>
                        <?= $usHeader('name', t('panel.th_name')) ?>
                        <?= $usHeader('username', t('panel.th_username')) ?>
                        <?= $usHeader('locale', t('panel.th_locale')) ?>
                        <?= $usHeader('registered', t('panel.filter_registered')) ?>
                        <?= $usHeader('last_seen_at', t('panel.th_last_seen')) ?>
                        <?= $usHeader('created_at', t('panel.th_created')) ?>
                        <?= $usHeader('blocked', t('panel.th_blocked')) ?>
                        <th scope="col" class="text-right"><?= e(t('panel.th_actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($usRows as $usRow) { ?>
                        <?php
                        if (!is_array($usRow)) {
                            continue;
                        }

                        $usTelegramId = (int) ($usRow['telegram_id'] ?? 0);

                        if ($usTelegramId === 0) {
                            continue;
                        }

                        $usName = trim(
                            (string) ($usRow['first_name'] ?? '') . ' ' . (string) ($usRow['last_name'] ?? '')
                        );
                        $usUsername = trim((string) ($usRow['username'] ?? ''));
                        $usRowLocale = trim((string) ($usRow['locale'] ?? ''));
                        $usIsBlocked = (int) ($usRow['is_blocked'] ?? 0) === 1;
                        $usIsAdmin = (int) ($usRow['is_admin'] ?? 0) === 1;
                        $usIsRegistered = (int) ($usRow['is_registered'] ?? 0) === 1;
                        $usIsConfigAdmin = in_array($usTelegramId, $usConfigAdmins, true);
                        $usChatUrl = $usUsername !== ''
                            ? 'https://t.me/' . rawurlencode($usUsername)
                            : 'tg://user?id=' . $usTelegramId;
                        ?>
                        <tr>
                            <td class="nowrap tabular"><?= e((string) $usTelegramId) ?></td>
                            <td>
                                <span class="user-cell">
                                    <span class="avatar avatar--sm" aria-hidden="true">
                                        <?= e(Text::initials($usName === '' ? $usUsername : $usName)) ?>
                                    </span>
                                    <span>
                                        <span class="cell-main">
                                            <?= e($usName === '' ? t('common.unknown') : Text::truncate($usName, 34)) ?>
                                        </span>
                                        <?php if ($usIsAdmin || $usIsConfigAdmin) { ?>
                                            <span class="cell-sub"><?= e(t('panel.th_admin')) ?></span>
                                        <?php } ?>
                                    </span>
                                </span>
                            </td>
                            <td>
                                <?php if ($usUsername !== '') { ?>
                                    <a class="page-link" href="<?= e($usChatUrl) ?>"
                                       target="_blank" rel="noopener noreferrer">@<?= e($usUsername) ?></a>
                                <?php } else { ?>
                                    <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                                <?php } ?>
                            </td>
                            <td class="nowrap"><?= e($usRowLocale === '' ? t('common.unknown') : strtoupper($usRowLocale)) ?></td>
                            <td>
                                <?php if ($usIsRegistered) { ?>
                                    <a class="badge badge--ok"
                                       href="<?= e(url(['p' => 'registrations', 'q' => (string) $usTelegramId])) ?>">
                                        <?= e(t('panel.user_registered_yes')) ?>
                                    </a>
                                <?php } else { ?>
                                    <span class="badge badge--muted"><?= e(t('panel.user_registered_no')) ?></span>
                                <?php } ?>
                            </td>
                            <td class="nowrap tabular">
                                <?php $usSeen = substr((string) ($usRow['last_seen_at'] ?? ''), 0, 16); ?>
                                <?= e($usSeen === '' ? t('common.never') : $usSeen) ?>
                            </td>
                            <td class="nowrap tabular"><?= e(substr((string) ($usRow['created_at'] ?? ''), 0, 16)) ?></td>
                            <td>
                                <?php if ($usIsBlocked) { ?>
                                    <span class="badge badge--danger"><?= e(t('common.yes')) ?></span>
                                <?php } else { ?>
                                    <span class="badge badge--muted"><?= e(t('common.no')) ?></span>
                                <?php } ?>
                            </td>
                            <td class="shrink">
                                <div class="table__actions">
                                    <a class="btn btn--ghost btn--sm" href="<?= e($usChatUrl) ?>"
                                       <?= $usUsername !== '' ? 'target="_blank" rel="noopener noreferrer"' : '' ?>>
                                        <?= e(t('panel.user_open_chat')) ?>
                                    </a>

                                    <form method="post"
                                          action="<?= e($usAction($usIsBlocked ? 'unblock' : 'block')) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="tid" value="<?= e((string) $usTelegramId) ?>">
                                        <button class="btn btn--sm <?= $usIsBlocked ? 'btn--ok' : 'btn--danger' ?>"
                                                type="submit">
                                            <?= e($usIsBlocked ? t('panel.user_unblock') : t('panel.user_block')) ?>
                                        </button>
                                    </form>

                                    <form method="post"
                                          action="<?= e($usAction($usIsAdmin ? 'revoke_admin' : 'make_admin')) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="tid" value="<?= e((string) $usTelegramId) ?>">
                                        <?php /* A config administrator keeps the flag no matter what the row says. */ ?>
                                        <button class="btn btn--ghost btn--sm" type="submit"
                                                <?= $usIsConfigAdmin ? 'title="' . e(t('panel.set_admin_ids_hint')) . '"' : '' ?>>
                                            <?= e($usIsAdmin ? t('panel.user_revoke_admin') : t('panel.user_make_admin')) ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php } ?>

    <div class="card__foot">
        <?php
        $view->partial('pagination', [
            'page'    => $usPage,
            'pages'   => $usPages,
            'total'   => $usTotal,
            'perPage' => $usPerPage,
            'query'   => $usQuery,
        ]);
        ?>
    </div>
</section>
<?php
unset(
    $usRows, $usFilters, $usQuery, $usSortable, $usLocales, $usPerPageOptions, $usSort, $usDir,
    $usTotal, $usPages, $usPage, $usPerPage, $usBlockedCount, $usFiltered, $usConfigAdmins,
    $usAdminId, $usLocaleOptions, $usLocaleOption, $usValue, $usPerPageChoices, $usOption,
    $usFlagOptions, $usAction, $usSortLink, $usHeader, $usRow, $usTelegramId, $usName,
    $usUsername, $usRowLocale, $usIsBlocked, $usIsAdmin, $usIsRegistered, $usIsConfigAdmin,
    $usChatUrl, $usSeen
);
?>
