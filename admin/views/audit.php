<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — audit trail.
 *
 * Every administrative action — a login, a status change, a broadcast, a purge —
 * lands in `audit_log` with its actor, its target, the client IP and a small
 * JSON payload. This screen filters and paginates that table and unfolds the
 * payload into readable `key: value` chips instead of printing raw JSON.
 *
 * A target of the shape `registration:42` is turned into a link to that
 * application, which is what makes the trail actually navigable.
 *
 * Variables handed over by LogController::audit():
 *   $title, $subtitle, $rows, $total, $page, $pages, $perPage, $perPageOptions,
 *   $filters, $actions, $purgeDays, $query, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */

$adRows           = isset($rows) && is_array($rows) ? $rows : [];
$adFilters        = isset($filters) && is_array($filters) ? $filters : [];
$adQuery          = isset($query) && is_array($query) ? $query : ['p' => 'audit'];
$adActions        = isset($actions) && is_array($actions) ? $actions : [];
$adPerPageOptions = isset($perPageOptions) && is_array($perPageOptions) ? $perPageOptions : [25, 50, 100];

$adTotal     = isset($total) && is_numeric($total) ? max(0, (int) $total) : count($adRows);
$adPages     = isset($pages) && is_numeric($pages) ? max(1, (int) $pages) : 1;
$adPage      = isset($page) && is_numeric($page) ? max(1, (int) $page) : 1;
$adPerPage   = isset($perPage) && is_numeric($perPage) ? max(1, (int) $perPage) : (int) $adPerPageOptions[1];
$adPurgeDays = isset($purgeDays) && is_numeric($purgeDays) ? max(1, (int) $purgeDays) : 90;
$adFiltered  = $adFilters !== [];

/** The distinct action names, offered as a dropdown. */
$adActionOptions = [];

foreach ($adActions as $adAction) {
    if (is_string($adAction) && $adAction !== '') {
        $adActionOptions[] = ['value' => $adAction, 'label' => $adAction];
    }
}

/** Page-size selector options. */
$adPerPageChoices = [];

foreach ($adPerPageOptions as $adOption) {
    if (is_numeric($adOption)) {
        $adPerPageChoices[] = ['value' => (string) (int) $adOption, 'label' => (string) (int) $adOption];
    }
}

/** One value of a meta payload, rendered for humans. */
$adScalar = static function (mixed $value): string {
    if (is_bool($value)) {
        return $value ? t('common.yes') : t('common.no');
    }

    if ($value === null) {
        return '—';
    }

    if (is_array($value)) {
        $flat = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $flat[] = (string) $item;
            }
        }

        return $flat === [] ? '—' : implode(', ', $flat);
    }

    if (!is_scalar($value)) {
        return '—';
    }

    $text = trim((string) $value);

    return $text === '' ? '—' : $text;
};

/**
 * The decoded `meta` column as a list of printable pairs.
 *
 * AuditRepository already turned the JSON into an array (and wrapped an
 * unparsable payload into `['raw' => …]`), so nothing is decoded here.
 *
 * @return array<int,array{key:string,value:string}>
 */
$adMeta = static function (mixed $meta) use ($adScalar): array {
    if (!is_array($meta) || $meta === []) {
        return [];
    }

    $pairs = [];

    foreach ($meta as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        $pairs[] = ['key' => $key, 'value' => Text::truncate($adScalar($value), 90)];
    }

    return $pairs;
};

?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('common.filter')) ?></h2>
        <div class="card__actions">
            <span class="chip"><?= e(t('common.total')) ?>: <?= e(number_format((float) $adTotal, 0, ',', ' ')) ?></span>
        </div>
    </div>
    <div class="card__body">
        <?php
        $view->partial('filters', [
            'action' => 'audit',
            'values' => $adFilters,
            'fields' => [
                [
                    'type'        => 'search',
                    'name'        => 'q',
                    'label'       => t('common.search'),
                    'placeholder' => t('panel.search_placeholder'),
                ],
                [
                    'type'        => 'text',
                    'name'        => 'actor',
                    'label'       => t('panel.audit_filter_actor'),
                    'placeholder' => 'panel:admin',
                ],
                [
                    'type'    => 'select',
                    'name'    => 'action',
                    'label'   => t('panel.audit_filter_action'),
                    'options' => $adActionOptions,
                ],
                [
                    'type'        => 'text',
                    'name'        => 'target',
                    'label'       => t('panel.audit_filter_target'),
                    'placeholder' => 'registration:12',
                ],
                [
                    'type'        => 'text',
                    'name'        => 'ip',
                    'label'       => t('panel.audit_filter_ip'),
                    'placeholder' => '127.0.0.1',
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
                    'value'   => (string) $adPerPage,
                    'empty'   => false,
                    'options' => $adPerPageChoices,
                ],
            ],
        ]);
        ?>
    </div>
</section>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('panel.audit_title')) ?></h2>
        <div class="card__actions">
            <form class="filters" method="post" action="<?= e(url(array_merge($adQuery, ['a' => 'purge']))) ?>"
                  data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="field__label" for="audit-days"><?= e(t('common.day')) ?></label>
                    <input class="input" type="number" id="audit-days" name="days" min="1" max="3650"
                           value="<?= e((string) $adPurgeDays) ?>" inputmode="numeric">
                </div>
                <button class="btn btn--danger btn--sm" type="submit">
                    <?= e(t('panel.audit_purge')) ?>
                </button>
            </form>
        </div>
    </div>

    <?php if ($adRows === []) { ?>
        <div class="card__body">
            <div class="empty">
                <svg class="empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <circle cx="12" cy="12" r="8"/><path d="M12 7.5V12l3 2"/>
                </svg>
                <p class="empty__title">
                    <?= e($adFiltered ? t('panel.empty_search') : t('panel.audit_empty')) ?>
                </p>
                <?php if ($adFiltered) { ?>
                    <p class="empty__text"><?= e(t('panel.empty_hint')) ?></p>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'audit'])) ?>">
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
                        <th scope="col"><?= e(t('panel.th_time')) ?></th>
                        <th scope="col"><?= e(t('panel.th_actor')) ?></th>
                        <th scope="col"><?= e(t('panel.th_action')) ?></th>
                        <th scope="col"><?= e(t('panel.th_target')) ?></th>
                        <th scope="col"><?= e(t('common.info')) ?></th>
                        <th scope="col"><?= e(t('panel.th_ip')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($adRows as $adRow) { ?>
                        <?php
                        if (!is_array($adRow)) {
                            continue;
                        }

                        $adTime = substr((string) ($adRow['created_at'] ?? ''), 0, 19);
                        $adActor = trim((string) ($adRow['actor'] ?? ''));
                        $adName = trim((string) ($adRow['action'] ?? ''));
                        $adTarget = trim((string) ($adRow['target'] ?? ''));
                        $adIp = trim((string) ($adRow['ip'] ?? ''));
                        $adPairs = $adMeta($adRow['meta'] ?? null);

                        // `registration:42` becomes a link to that application.
                        $adTargetUrl = '';

                        if (preg_match('/^registration:(\d{1,18})$/', $adTarget, $adMatch) === 1) {
                            $adTargetUrl = url(['p' => 'registration', 'id' => (int) $adMatch[1]]);
                        }
                        ?>
                        <tr>
                            <td class="nowrap tabular"><?= e($adTime) ?></td>
                            <td class="nowrap"><?= e($adActor === '' ? t('common.unknown') : $adActor) ?></td>
                            <td><span class="badge badge--info"><?= e($adName) ?></span></td>
                            <td class="nowrap">
                                <?php if ($adTarget === '') { ?>
                                    <span class="muted">—</span>
                                <?php } elseif ($adTargetUrl !== '') { ?>
                                    <a class="page-link" href="<?= e($adTargetUrl) ?>"><?= e($adTarget) ?></a>
                                <?php } else { ?>
                                    <?= e($adTarget) ?>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if ($adPairs === []) { ?>
                                    <span class="muted">—</span>
                                <?php } else { ?>
                                    <?php foreach ($adPairs as $adPair) { ?>
                                        <span class="chip chip--plain">
                                            <?= e($adPair['key']) ?>: <?= e($adPair['value']) ?>
                                        </span>
                                    <?php } ?>
                                <?php } ?>
                            </td>
                            <td class="nowrap muted"><?= e($adIp === '' ? '—' : $adIp) ?></td>
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
            'page'    => $adPage,
            'pages'   => $adPages,
            'total'   => $adTotal,
            'perPage' => $adPerPage,
            'query'   => $adQuery,
        ]);
        ?>
    </div>
</section>
<?php
unset(
    $adRows, $adFilters, $adQuery, $adActions, $adPerPageOptions, $adTotal, $adPages, $adPage,
    $adPerPage, $adPurgeDays, $adFiltered, $adActionOptions, $adAction, $adPerPageChoices,
    $adOption, $adScalar, $adMeta, $adRow, $adTime, $adActor, $adName, $adTarget, $adIp,
    $adPairs, $adTargetUrl, $adMatch, $adPair
);
?>
