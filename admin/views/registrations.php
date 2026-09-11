<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — applications list.
 *
 * The working screen of the panel: a filter bar, a sortable table, per-row
 * moderation, bulk moderation and an XLSX export that always matches whatever
 * the operator is currently looking at.
 *
 * Two details are worth knowing before editing this template:
 *
 *  1. The bulk form wraps the table, so the `ids[]` checkboxes are submitted by
 *     the browser itself — the panel's JavaScript only mirrors the selection
 *     into the floating bar. Bulk actions therefore keep working with scripting
 *     disabled.
 *  2. HTML forbids nested forms, so the per-row POST buttons cannot live inside
 *     that bulk form. Each row instead owns a tiny form rendered underneath the
 *     table and referenced through the standard `form=` attribute; the button's
 *     `formaction` picks approve, reject or delete.
 *
 * Variables handed over by RegistrationController::list():
 *   $title, $subtitle, $rows, $total, $page, $pages, $perPage, $perPageOptions,
 *   $filters, $sort, $dir, $sortable, $districts, $directions, $statuses,
 *   $query, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Registration\Catalog;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */

$rgRows           = isset($rows) && is_array($rows) ? $rows : [];
$rgFilters        = isset($filters) && is_array($filters) ? $filters : [];
$rgQuery          = isset($query) && is_array($query) ? $query : ['p' => 'registrations'];
$rgSortable       = isset($sortable) && is_array($sortable) ? $sortable : [];
$rgStatuses       = isset($statuses) && is_array($statuses) ? $statuses : [];
$rgDistrictList   = isset($districts) && is_array($districts) ? $districts : [];
$rgDirectionList  = isset($directions) && is_array($directions) ? $directions : [];
$rgPerPageOptions = isset($perPageOptions) && is_array($perPageOptions) ? $perPageOptions : [25, 50, 100];

$rgLocale  = isset($locale) && is_string($locale) && $locale !== '' ? $locale : panel_locale();
$rgSort    = isset($sort) && is_string($sort) && $sort !== '' ? $sort : 'created_at';
$rgDir     = isset($dir) && is_string($dir) && strtolower($dir) === 'asc' ? 'asc' : 'desc';
$rgTotal   = isset($total) && is_numeric($total) ? max(0, (int) $total) : count($rgRows);
$rgPages   = isset($pages) && is_numeric($pages) ? max(1, (int) $pages) : 1;
$rgPage    = isset($page) && is_numeric($page) ? max(1, (int) $page) : 1;
$rgPerPage = isset($perPage) && is_numeric($perPage) ? max(1, (int) $perPage) : (int) $rgPerPageOptions[0];

// True as soon as at least one filter narrows the list: it changes the wording
// of the empty state from "no applications yet" to "nothing matched".
$rgFiltered = $rgFilters !== [];

/* -------------------------------------------------------------------------
 | Lookup tables built once, then reused by every row
 */

/** status value => ['value','label','badge','emoji'] */
$rgStatusMap = [];

/** The same list, shaped for the filter bar's <select>. */
$rgStatusOptions = [];

foreach ($rgStatuses as $rgStatus) {
    if (!is_array($rgStatus)) {
        continue;
    }

    $rgValue = trim((string) ($rgStatus['value'] ?? ''));

    if ($rgValue === '') {
        continue;
    }

    $rgStatusMap[$rgValue] = $rgStatus;
    $rgStatusOptions[] = [
        'value' => $rgValue,
        'label' => trim((string) ($rgStatus['emoji'] ?? '') . ' ' . (string) ($rgStatus['label'] ?? $rgValue)),
    ];
}

/** Districts, cities first — Catalog already returns them in that order. */
$rgDistrictOptions = [];

foreach ($rgDistrictList as $rgDistrict) {
    if (!is_array($rgDistrict)) {
        continue;
    }

    $rgKey = trim((string) ($rgDistrict['key'] ?? ''));

    if ($rgKey !== '') {
        $rgDistrictOptions[] = ['value' => $rgKey, 'label' => (string) ($rgDistrict['label'] ?? $rgKey)];
    }
}

/** Directions, each prefixed with its emoji so the list stays scannable. */
$rgDirectionOptions = [];

foreach ($rgDirectionList as $rgDirection) {
    if (!is_array($rgDirection)) {
        continue;
    }

    $rgKey = trim((string) ($rgDirection['key'] ?? ''));

    if ($rgKey !== '') {
        $rgDirectionOptions[] = [
            'value' => $rgKey,
            'label' => trim((string) ($rgDirection['emoji'] ?? '') . ' ' . (string) ($rgDirection['label'] ?? $rgKey)),
        ];
    }
}

/** Page-size selector options. */
$rgPerPageChoices = [];

foreach ($rgPerPageOptions as $rgOption) {
    if (is_numeric($rgOption)) {
        $rgPerPageChoices[] = ['value' => (string) (int) $rgOption, 'label' => (string) (int) $rgOption];
    }
}

/* -------------------------------------------------------------------------
 | URLs
 */

// "Export current filter": the export controller reads exactly these keys.
$rgExportQuery = ['p' => 'export'];

foreach (['q', 'status', 'district', 'direction', 'date_from', 'date_to'] as $rgKey) {
    $rgValue = $rgFilters[$rgKey] ?? null;

    if (is_scalar($rgValue) && trim((string) $rgValue) !== '') {
        $rgExportQuery[$rgKey] = (string) $rgValue;
    }
}

/** POST target of an action, current filters preserved. */
$rgAction = static function (string $action) use ($rgQuery): string {
    return url(array_merge($rgQuery, ['a' => $action]));
};

/**
 * The link behind a sortable column header.
 *
 * Clicking the active column flips the direction; clicking a new one starts
 * with the direction that reads best for its data (newest first for the
 * numeric/date columns, A→Z for the textual ones).
 */
$rgSortLink = static function (string $column) use ($rgQuery, $rgSort, $rgDir): string {
    if ($rgSort === $column) {
        $next = $rgDir === 'asc' ? 'desc' : 'asc';
    } else {
        $next = in_array($column, ['id', 'created_at'], true) ? 'desc' : 'asc';
    }

    // A new ordering invalidates the current offset — always land on page 1.
    return url(array_merge($rgQuery, ['sort' => $column, 'dir' => $next, 'page' => null]));
};

/**
 * One table header cell: a plain label, or a sort link when the repository
 * accepts that column in its ORDER BY whitelist.
 */
$rgHeader = static function (string $column, string $label) use ($rgSortable, $rgSort, $rgDir, $rgSortLink): string {
    if (!in_array($column, $rgSortable, true)) {
        return '<th scope="col">' . e($label) . '</th>';
    }

    $isActive = $rgSort === $column;
    $class = $isActive ? ' class="is-sorted-' . ($rgDir === 'asc' ? 'asc' : 'desc') . '"' : '';
    $aria = $isActive ? ($rgDir === 'asc' ? 'ascending' : 'descending') : 'none';

    return '<th scope="col" aria-sort="' . $aria . '">'
        . '<a' . $class . ' data-sync-query data-sync-drop="page"'
        . ' href="' . e($rgSortLink($column)) . '"'
        . ' title="' . e(t('panel.sort_by')) . '">' . e($label) . '</a>'
        . '</th>';
};

/** Small monochrome glyphs for the compact row actions. */
$rgIcon = static function (string $name): string {
    $body = match ($name) {
        'approve' => '<path d="M4.5 12.5 9 17l10.5-10.5"/>',
        'reject'  => '<path d="m6 6 12 12M18 6 6 18"/>',
        'delete'  => '<path d="M4 7h16"/><path d="M9.5 7V5.2A1.2 1.2 0 0 1 10.7 4h2.6a1.2 1.2 0'
            . ' 0 1 1.2 1.2V7"/><path d="M6.5 7 7.4 19a1.5 1.5 0 0 0 1.5 1.4h6.2A1.5 1.5 0 0 0'
            . ' 16.6 19L17.5 7"/><path d="M10.5 10.5v6M13.5 10.5v6"/>',
        default   => '<circle cx="12" cy="12" r="8"/>',
    };

    return '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
};

/** Ids of the rendered rows — the hidden per-row forms are built from them. */
$rgRowIds = [];

?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('common.filter')) ?></h2>
        <div class="card__actions">
            <a class="btn btn--ghost btn--sm" href="<?= e(url($rgExportQuery)) ?>">
                <?= e(t('panel.export_filtered')) ?>
            </a>
        </div>
    </div>
    <div class="card__body">
        <?php
        $view->partial('filters', [
            'action' => 'registrations',
            'values' => $rgFilters,
            'hidden' => ['sort' => $rgSort, 'dir' => $rgDir],
            'fields' => [
                [
                    'type'        => 'search',
                    'name'        => 'q',
                    'label'       => t('common.search'),
                    'placeholder' => t('panel.search_placeholder'),
                ],
                [
                    'type'    => 'select',
                    'name'    => 'status',
                    'label'   => t('panel.filter_status'),
                    'options' => $rgStatusOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'district',
                    'label'   => t('panel.filter_district'),
                    'options' => $rgDistrictOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'direction',
                    'label'   => t('panel.filter_direction'),
                    'options' => $rgDirectionOptions,
                ],
                [
                    'type'  => 'date',
                    'name'  => 'date_from',
                    'label' => t('panel.filter_date_from'),
                ],
                [
                    'type'  => 'date',
                    'name'  => 'date_to',
                    'label' => t('panel.filter_date_to'),
                ],
                [
                    'type'    => 'select',
                    'name'    => 'per_page',
                    'label'   => t('panel.per_page'),
                    'value'   => (string) $rgPerPage,
                    'empty'   => false,
                    'options' => $rgPerPageChoices,
                ],
            ],
        ]);
        ?>
    </div>
</section>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('panel.registrations_title')) ?></h2>
        <span class="chip"><?= e(t('common.total')) ?>: <?= e(number_format((float) $rgTotal, 0, ',', ' ')) ?></span>
    </div>

    <?php if ($rgRows === []) { ?>
        <div class="card__body">
            <div class="empty">
                <svg class="empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M5 4.5h9l5 5V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19z"/>
                    <path d="M14 4.5V10h5"/><path d="M8.5 14h7"/><path d="M8.5 17h4.5"/>
                </svg>
                <p class="empty__title">
                    <?= e($rgFiltered ? t('panel.empty_search') : t('panel.empty_registrations')) ?>
                </p>
                <p class="empty__text"><?= e(t('panel.empty_hint')) ?></p>
                <?php if ($rgFiltered) { ?>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'registrations'])) ?>">
                        <?= e(t('panel.filter_reset')) ?>
                    </a>
                <?php } ?>
            </div>
        </div>
    <?php } else { ?>
        <form method="post" action="<?= e($rgAction('bulk')) ?>" data-bulk-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="bulk" value="" data-bulk-op>
            <span hidden data-bulk-ids></span>

            <div class="card__body card__body--flush">
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                        <tr>
                            <th scope="col" class="shrink">
                                <label class="checkbox" title="<?= e(t('panel.select_all')) ?>">
                                    <input type="checkbox" data-check-all
                                           aria-label="<?= e(t('panel.select_all')) ?>">
                                </label>
                            </th>
                            <?= $rgHeader('id', t('panel.th_id')) ?>
                            <?= $rgHeader('created_at', t('panel.th_created')) ?>
                            <?= $rgHeader('full_name', t('panel.th_name')) ?>
                            <?= $rgHeader('phone', t('panel.th_phone')) ?>
                            <?= $rgHeader('district', t('panel.th_district')) ?>
                            <?= $rgHeader('direction', t('panel.th_direction')) ?>
                            <?= $rgHeader('status', t('panel.th_status')) ?>
                            <th scope="col" class="text-right"><?= e(t('panel.th_actions')) ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rgRows as $rgRow) { ?>
                            <?php
                            if (!is_array($rgRow)) {
                                continue;
                            }

                            $rgId = (int) ($rgRow['id'] ?? 0);

                            if ($rgId <= 0) {
                                continue;
                            }

                            $rgRowIds[] = $rgId;

                            $rgName = trim((string) ($rgRow['full_name'] ?? ''));
                            $rgUsername = trim((string) ($rgRow['username'] ?? ''));
                            $rgPhone = trim((string) ($rgRow['phone'] ?? ''));
                            $rgDistrictKey = trim((string) ($rgRow['district'] ?? ''));
                            $rgStatusKey = trim((string) ($rgRow['status'] ?? ''));
                            $rgBadge = $rgStatusMap[$rgStatusKey] ?? null;
                            $rgCreated = substr((string) ($rgRow['created_at'] ?? ''), 0, 16);
                            $rgDetailUrl = url(['p' => 'registration', 'id' => $rgId]);

                            // Direction keys are already decoded into an array
                            // by RegistrationRepository; free-text "other"
                            // entries are appended as they were typed.
                            $rgNames = [];

                            foreach (is_array($rgRow['directions'] ?? null) ? $rgRow['directions'] : [] as $rgItem) {
                                if (is_string($rgItem) && $rgItem !== '') {
                                    $rgNames[] = Catalog::directionLabel($rgItem, $rgLocale, false);
                                }
                            }

                            $rgOther = trim((string) ($rgRow['direction_other'] ?? ''));

                            if ($rgOther !== '') {
                                $rgNames[] = $rgOther;
                            }
                            ?>
                            <tr>
                                <td class="shrink">
                                    <label class="checkbox">
                                        <input type="checkbox" name="ids[]" value="<?= e((string) $rgId) ?>"
                                               data-check-item
                                               aria-label="<?= e(t('panel.th_id') . ' ' . $rgId) ?>">
                                    </label>
                                </td>
                                <td class="num">
                                    <a class="page-link" href="<?= e($rgDetailUrl) ?>">#<?= e((string) $rgId) ?></a>
                                </td>
                                <td class="nowrap tabular"><?= e($rgCreated) ?></td>
                                <td>
                                    <a class="cell-main" href="<?= e($rgDetailUrl) ?>">
                                        <?= e($rgName === '' ? t('common.unknown') : Text::truncate($rgName, 44)) ?>
                                    </a>
                                    <?php if ($rgUsername !== '') { ?>
                                        <span class="cell-sub">@<?= e($rgUsername) ?></span>
                                    <?php } else { ?>
                                        <span class="cell-sub"><?= e((string) ($rgRow['telegram_id'] ?? '')) ?></span>
                                    <?php } ?>
                                </td>
                                <td class="nowrap">
                                    <?php if ($rgPhone !== '') { ?>
                                        <a class="page-link" href="tel:<?= e($rgPhone) ?>">
                                            <?= e(Text::phoneDisplay($rgPhone)) ?>
                                        </a>
                                    <?php } else { ?>
                                        <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($rgDistrictKey !== '') { ?>
                                        <?= e(Catalog::districtLabel($rgDistrictKey, $rgLocale)) ?>
                                    <?php } else { ?>
                                        <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($rgNames === []) { ?>
                                        <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                                    <?php } else { ?>
                                        <span title="<?= e(implode(', ', $rgNames)) ?>">
                                            <?= e(Text::truncate(implode(', ', $rgNames), 42)) ?>
                                        </span>
                                    <?php } ?>
                                </td>
                                <td>
                                    <?php if ($rgBadge !== null) { ?>
                                        <span class="badge badge--<?= e((string) ($rgBadge['badge'] ?? 'warn')) ?>">
                                            <?= e((string) ($rgBadge['emoji'] ?? '')) ?>
                                            <?= e((string) ($rgBadge['label'] ?? $rgStatusKey)) ?>
                                        </span>
                                    <?php } else { ?>
                                        <span class="badge badge--muted"><?= e(t('status.unknown')) ?></span>
                                    <?php } ?>
                                </td>
                                <td class="shrink">
                                    <div class="table__actions">
                                        <a class="btn btn--ghost btn--sm" href="<?= e($rgDetailUrl) ?>">
                                            <?= e(t('panel.view')) ?>
                                        </a>
                                        <button class="btn btn--ok btn--icon btn--sm" type="submit"
                                                form="reg-row-<?= e((string) $rgId) ?>"
                                                formaction="<?= e($rgAction('approve')) ?>"
                                                title="<?= e(t('panel.bulk_approve')) ?>"
                                                aria-label="<?= e(t('panel.bulk_approve')) ?>">
                                            <?= $rgIcon('approve') ?>
                                        </button>
                                        <button class="btn btn--icon btn--sm" type="submit"
                                                form="reg-row-<?= e((string) $rgId) ?>"
                                                formaction="<?= e($rgAction('reject')) ?>"
                                                title="<?= e(t('panel.bulk_reject')) ?>"
                                                aria-label="<?= e(t('panel.bulk_reject')) ?>">
                                            <?= $rgIcon('reject') ?>
                                        </button>
                                        <button class="btn btn--danger btn--icon btn--sm" type="submit"
                                                form="reg-row-<?= e((string) $rgId) ?>"
                                                formaction="<?= e($rgAction('delete')) ?>"
                                                data-confirm="<?= e(t('panel.confirm_delete')) ?>"
                                                title="<?= e(t('panel.bulk_delete')) ?>"
                                                aria-label="<?= e(t('panel.bulk_delete')) ?>">
                                            <?= $rgIcon('delete') ?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bulk-bar" data-bulk-bar aria-hidden="true">
                <span class="bulk-bar__count">
                    <strong data-bulk-count>0</strong>
                    <span><?= e(t('panel.bulk_actions')) ?></span>
                </span>
                <div class="btn-row">
                    <button class="btn btn--ok btn--sm" type="submit" data-bulk-action="approve"
                            data-confirm="<?= e(t('panel.confirm_bulk')) ?>">
                        <?= e(t('panel.bulk_approve')) ?>
                    </button>
                    <button class="btn btn--sm" type="submit" data-bulk-action="reject"
                            data-confirm="<?= e(t('panel.confirm_bulk')) ?>">
                        <?= e(t('panel.bulk_reject')) ?>
                    </button>
                    <button class="btn btn--danger btn--sm" type="submit" data-bulk-action="delete"
                            data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                        <?= e(t('panel.bulk_delete')) ?>
                    </button>
                </div>
            </div>
        </form>
    <?php } ?>

    <div class="card__foot">
        <?php
        $view->partial('pagination', [
            'page'    => $rgPage,
            'pages'   => $rgPages,
            'total'   => $rgTotal,
            'perPage' => $rgPerPage,
            'query'   => $rgQuery,
        ]);
        ?>
    </div>
</section>

<?php if ($rgRowIds !== []) { ?>
    <?php /* The per-row POST forms: referenced by the buttons above through form="…". */ ?>
    <div hidden>
        <?php foreach ($rgRowIds as $rgRowId) { ?>
            <form id="reg-row-<?= e((string) $rgRowId) ?>" method="post"
                  action="<?= e($rgAction('approve')) ?>">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= e((string) $rgRowId) ?>">
            </form>
        <?php } ?>
    </div>
<?php } ?>
<?php
unset(
    $rgRows, $rgFilters, $rgQuery, $rgSortable, $rgStatuses, $rgDistrictList, $rgDirectionList,
    $rgPerPageOptions, $rgLocale, $rgSort, $rgDir, $rgTotal, $rgPages, $rgPage, $rgPerPage,
    $rgFiltered, $rgStatusMap, $rgStatusOptions, $rgStatus, $rgValue, $rgDistrictOptions,
    $rgDistrict, $rgDirectionOptions, $rgDirection, $rgPerPageChoices, $rgOption, $rgExportQuery,
    $rgKey, $rgAction, $rgSortLink, $rgHeader, $rgIcon, $rgRowIds, $rgRowId, $rgRow, $rgId,
    $rgName, $rgUsername, $rgPhone, $rgDistrictKey, $rgStatusKey, $rgBadge, $rgCreated,
    $rgDetailUrl, $rgNames, $rgItem, $rgOther
);
?>
