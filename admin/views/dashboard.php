<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — dashboard.
 *
 * The landing screen of the panel: six headline numbers, the 14-day trend, the
 * direction / district / status breakdowns and the ten newest applications.
 * All charts are inline SVG produced by the two chart partials, so the page
 * stays script-free and works with JavaScript switched off.
 *
 * Variables handed over by DashboardController:
 *   $title, $subtitle, $overview, $daily, $hourly, $byStatus, $byDirection,
 *   $byDistrict, $topDay, $latest, $trendDays, $statuses, $locale, $q
 */

use AiTalents\Registration\Catalog;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */

$dbOverview = isset($overview) && is_array($overview) ? $overview : [];
$dbLatest = isset($latest) && is_array($latest) ? $latest : [];
$dbStatuses = isset($statuses) && is_array($statuses) ? $statuses : [];
$dbLocale = isset($locale) && is_string($locale) && $locale !== '' ? $locale : panel_locale();
$dbTrendDays = isset($trendDays) && is_numeric($trendDays) ? (int) $trendDays : 14;

/** Thousands-separated integer, the way the panel prints every counter. */
$dbNumber = static function (mixed $value): string {
    return number_format((float) (is_numeric($value) ? $value : 0), 0, ',', ' ');
};

/** One value out of the overview array, always an int. */
$dbStat = static function (string $key) use ($dbOverview): int {
    $value = $dbOverview[$key] ?? 0;

    return is_numeric($value) ? (int) $value : 0;
};

/** The locale-aware label of a StatsService breakdown row. */
$dbLabel = static function (array $row) use ($dbLocale): string {
    $label = $dbLocale === 'ru'
        ? (string) ($row['label_ru'] ?? '')
        : (string) ($row['label_uz'] ?? '');

    if (trim($label) === '') {
        $label = (string) ($row['key'] ?? '');
    }

    return $label;
};

/** Turn a StatsService breakdown into the ['label','value'] pairs a chart eats. */
$dbSeries = static function (mixed $rows) use ($dbLabel): array {
    $series = [];

    foreach (is_array($rows) ? $rows : [] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $series[] = [
            'label' => $dbLabel($row),
            'value' => is_numeric($row['count'] ?? null) ? (int) $row['count'] : 0,
        ];
    }

    return $series;
};

/* The 14-day trend: `2026-09-10` becomes the short `10.09` axis caption. */
$dbDaily = [];

foreach (isset($daily) && is_array($daily) ? $daily : [] as $dbDay) {
    if (!is_array($dbDay)) {
        continue;
    }

    $dbDate = (string) ($dbDay['date'] ?? '');
    $dbStamp = $dbDate === '' ? false : strtotime($dbDate);

    $dbDaily[] = [
        'label' => $dbStamp === false ? $dbDate : date('d.m', $dbStamp),
        'value' => is_numeric($dbDay['count'] ?? null) ? (int) $dbDay['count'] : 0,
    ];
}

/* The last 24 hours, already carrying its own `H:00` label. */
$dbHourly = [];

foreach (isset($hourly) && is_array($hourly) ? $hourly : [] as $dbHour) {
    if (!is_array($dbHour)) {
        continue;
    }

    $dbHourly[] = [
        'label' => (string) ($dbHour['label'] ?? ''),
        'value' => is_numeric($dbHour['count'] ?? null) ? (int) $dbHour['count'] : 0,
    ];
}

$dbDirections = $dbSeries($byDirection ?? []);
$dbDistricts = $dbSeries($byDistrict ?? []);
$dbStatusSeries = $dbSeries($byStatus ?? []);

/* The six headline cards. */
$dbCards = [
    ['label' => t('panel.card_users'),         'value' => $dbStat('users')],
    ['label' => t('panel.card_registrations'), 'value' => $dbStat('registrations')],
    ['label' => t('panel.card_today'),         'value' => $dbStat('today')],
    ['label' => t('panel.card_week'),          'value' => $dbStat('week')],
    ['label' => t('panel.card_pending'),       'value' => $dbStat('pending')],
    ['label' => t('panel.card_approved'),      'value' => $dbStat('approved')],
];

$dbConversion = $dbOverview['conversion'] ?? 0;
$dbConversion = is_numeric($dbConversion) ? (float) $dbConversion : 0.0;

$dbTopDay = isset($topDay) && is_array($topDay) ? $topDay : null;

?>
<section class="card">
    <div class="card__head">
        <h2><?= e(t('panel.quick_search')) ?></h2>
        <span class="chip"><?= e(t('panel.card_conversion')) ?>: <?= e(number_format($dbConversion, 1, '.', ' ')) ?>%</span>
    </div>
    <div class="card__body">
        <?php
        $view->partial('filters', [
            'action'     => 'dashboard',
            'hidden'     => ['a' => 'search'],
            'autoSubmit' => false,
            'reset'      => false,
            'submit'     => t('common.search'),
            'fields'     => [[
                'type'        => 'search',
                'name'        => 'q',
                'label'       => t('panel.quick_search'),
                'placeholder' => t('panel.quick_search_placeholder'),
                'value'       => isset($q) && is_string($q) ? $q : '',
            ]],
        ]);
        ?>
    </div>
</section>

<section class="stat-grid">
    <?php foreach ($dbCards as $dbCard) { ?>
        <article class="stat">
            <div class="stat__value"><?= e($dbNumber($dbCard['value'])) ?></div>
            <div class="stat__label"><?= e($dbCard['label']) ?></div>
        </article>
    <?php } ?>
</section>

<section class="card">
    <div class="card__head">
        <h2><?= e(t('panel.chart_daily')) ?></h2>
        <span class="meta">
            <span class="chip"><?= e(t('common.day')) ?>: <?= e((string) $dbTrendDays) ?></span>
            <?php if ($dbTopDay !== null) { ?>
                <span class="chip">
                    <?= e(t('panel.top_day')) ?>:
                    <?= e((string) ($dbTopDay['date'] ?? '')) ?>
                    (<?= e($dbNumber($dbTopDay['count'] ?? 0)) ?>)
                </span>
            <?php } ?>
        </span>
    </div>
    <div class="card__body">
        <?php
        $view->partial('chart_line', [
            'data'   => $dbDaily,
            'height' => 260,
            'title'  => t('panel.chart_daily'),
        ]);
        ?>
    </div>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card__head">
            <h2><?= e(t('panel.chart_directions')) ?></h2>
        </div>
        <div class="card__body">
            <?php
            $view->partial('chart_bar', [
                'data'  => $dbDirections,
                'title' => t('panel.chart_directions'),
                'limit' => 11,
            ]);
            ?>
        </div>
    </article>

    <article class="card">
        <div class="card__head">
            <h2><?= e(t('panel.chart_districts')) ?></h2>
        </div>
        <div class="card__body">
            <?php
            $view->partial('chart_bar', [
                'data'  => $dbDistricts,
                'title' => t('panel.chart_districts'),
                'limit' => 12,
            ]);
            ?>
        </div>
    </article>
</section>

<section class="grid-2">
    <article class="card">
        <div class="card__head">
            <h2><?= e(t('panel.chart_hourly')) ?></h2>
        </div>
        <div class="card__body">
            <?php
            $view->partial('chart_line', [
                'data'   => $dbHourly,
                'height' => 200,
                'title'  => t('panel.chart_hourly'),
            ]);
            ?>
        </div>
    </article>

    <article class="card">
        <div class="card__head">
            <h2><?= e(t('panel.chart_status')) ?></h2>
        </div>
        <div class="card__body">
            <?php
            $view->partial('chart_bar', [
                'data'   => $dbStatusSeries,
                'title'  => t('panel.chart_status'),
                'height' => 160,
            ]);
            ?>
        </div>
    </article>
</section>

<section class="card">
    <div class="card__head">
        <h2><?= e(t('panel.latest_registrations')) ?></h2>
        <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'registrations'])) ?>">
            <?= e(t('panel.nav_registrations')) ?>
        </a>
    </div>
    <div class="card__body">
        <?php if ($dbLatest === []) { ?>
            <div class="empty">
                <p><?= e(t('panel.empty_registrations')) ?></p>
                <p class="meta"><?= e(t('panel.empty_hint')) ?></p>
            </div>
        <?php } else { ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col"><?= e(t('panel.th_id')) ?></th>
                        <th scope="col"><?= e(t('panel.th_name')) ?></th>
                        <th scope="col"><?= e(t('panel.th_phone')) ?></th>
                        <th scope="col"><?= e(t('panel.th_district')) ?></th>
                        <th scope="col"><?= e(t('panel.th_direction')) ?></th>
                        <th scope="col"><?= e(t('panel.th_status')) ?></th>
                        <th scope="col"><?= e(t('panel.th_created')) ?></th>
                        <th scope="col"><?= e(t('panel.th_actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($dbLatest as $dbRow) { ?>
                        <?php
                        if (!is_array($dbRow)) {
                            continue;
                        }

                        $dbId = (int) ($dbRow['id'] ?? 0);
                        $dbPhone = trim((string) ($dbRow['phone'] ?? ''));
                        $dbDistrict = trim((string) ($dbRow['district'] ?? ''));
                        $dbStatusKey = (string) ($dbRow['status'] ?? '');
                        $dbBadge = $dbStatuses[$dbStatusKey] ?? null;

                        $dbDirectionKeys = is_array($dbRow['directions'] ?? null) ? $dbRow['directions'] : [];
                        $dbDirectionNames = [];

                        foreach ($dbDirectionKeys as $dbKey) {
                            if (is_string($dbKey) && $dbKey !== '') {
                                $dbDirectionNames[] = Catalog::directionLabel($dbKey, $dbLocale, false);
                            }
                        }

                        $dbCreated = substr((string) ($dbRow['created_at'] ?? ''), 0, 16);
                        ?>
                        <tr>
                            <td><a class="page-link" href="<?= e(url(['p' => 'registration', 'id' => $dbId])) ?>">#<?= e((string) $dbId) ?></a></td>
                            <td><?= e(Text::truncate((string) ($dbRow['full_name'] ?? ''), 40)) ?></td>
                            <td>
                                <?php if ($dbPhone !== '') { ?>
                                    <a class="page-link" href="tel:<?= e($dbPhone) ?>"><?= e(Text::phoneDisplay($dbPhone)) ?></a>
                                <?php } else { ?>
                                    <span class="meta"><?= e(t('profile.empty_value')) ?></span>
                                <?php } ?>
                            </td>
                            <td><?= e($dbDistrict === '' ? t('profile.empty_value') : Catalog::districtLabel($dbDistrict, $dbLocale)) ?></td>
                            <td><?= e($dbDirectionNames === [] ? t('profile.empty_value') : Text::truncate(implode(', ', $dbDirectionNames), 46)) ?></td>
                            <td>
                                <?php if ($dbBadge !== null) { ?>
                                    <span class="badge badge--<?= e((string) ($dbBadge['badge'] ?? 'warn')) ?>">
                                        <?= e((string) ($dbBadge['emoji'] ?? '')) ?>
                                        <?= e((string) ($dbBadge['label'] ?? $dbStatusKey)) ?>
                                    </span>
                                <?php } else { ?>
                                    <span class="badge"><?= e(t('status.unknown')) ?></span>
                                <?php } ?>
                            </td>
                            <td><?= e($dbCreated) ?></td>
                            <td>
                                <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'registration', 'id' => $dbId])) ?>">
                                    <?= e(t('panel.view')) ?>
                                </a>
                            </td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
</section>
<?php
unset(
    $dbOverview, $dbLatest, $dbStatuses, $dbNumber, $dbStat, $dbLabel, $dbSeries,
    $dbDaily, $dbHourly, $dbDirections, $dbDistricts, $dbStatusSeries, $dbCards, $dbCard,
    $dbDay, $dbHour, $dbDate, $dbStamp, $dbRow, $dbId, $dbPhone, $dbDistrict,
    $dbStatusKey, $dbBadge, $dbDirectionKeys, $dbDirectionNames, $dbKey, $dbCreated
);
?>
