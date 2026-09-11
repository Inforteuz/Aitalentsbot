<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — inline SVG bar chart.
 *
 * Horizontal bars, because the categories this panel charts (directions, cities
 * and districts of Andijon region) carry long names that would be unreadable
 * under vertical columns. Like the line chart it is pure PHP: one <svg>
 * element, no script, no external asset, safe under the panel's CSP.
 *
 * Variables:
 *   $data   array  list of ['label' => string, 'value' => int] — required,
 *                  already ordered by the caller (StatsService sorts descending)
 *   $height ?int   total drawing height in viewBox units; derived from the row
 *                  count when omitted
 *   $title  ?string accessible name of the chart
 *   $limit  ?int    keep only the first N rows (0 or absent = all)
 *
 * Empty data renders the "not enough data" placeholder; an all-zero series
 * renders empty tracks instead of dividing by zero.
 */

use AiTalents\Text;

$brRaw = isset($data) && is_array($data) ? $data : [];
$brTitle = isset($title) && is_string($title) ? trim($title) : '';
$brLimit = isset($limit) && is_numeric($limit) ? max(0, (int) $limit) : 0;

/* ---------------------------------------------------------------------------
 | 1. Normalise the series
 */
$brRows = [];

foreach ($brRaw as $brEntry) {
    if (!is_array($brEntry)) {
        continue;
    }

    $brLabel = trim((string) ($brEntry['label'] ?? ''));

    if ($brLabel === '') {
        $brLabel = t('common.unknown');
    }

    $brValue = $brEntry['value'] ?? 0;

    $brRows[] = [
        'label' => $brLabel,
        'value' => max(0, is_numeric($brValue) ? (int) $brValue : 0),
    ];
}

if ($brLimit > 0 && count($brRows) > $brLimit) {
    $brRows = array_slice($brRows, 0, $brLimit);
}

if ($brRows === []) {
    ?>
    <div class="empty"><?= e(t('panel.chart_empty')) ?></div>
    <?php
    return;
}

/* ---------------------------------------------------------------------------
 | 2. Geometry
 */
$brWidth = 720;
$brLabelWidth = 210;   // left column holding the category names
$brPadRight = 56;      // room for the value printed after each bar
$brPadTop = 14;
$brPadBottom = 28;     // axis captions

$brCount = count($brRows);

// Row height: derived from the row count, or from an explicit total height.
$brRowHeight = 32;

if (isset($height) && is_numeric($height)) {
    $brInner = (int) $height - $brPadTop - $brPadBottom;
    $brRowHeight = $brInner > 0 ? (int) floor($brInner / $brCount) : $brRowHeight;
}

$brRowHeight = max(16, min(48, $brRowHeight));
$brBarHeight = max(8, $brRowHeight - 14);
$brHeight = $brPadTop + ($brCount * $brRowHeight) + $brPadBottom;

$brPlotX = $brLabelWidth;
$brPlotWidth = $brWidth - $brLabelWidth - $brPadRight;

$brTicks = 4;
$brMax = 0;

foreach ($brRows as $brRow) {
    $brMax = max($brMax, $brRow['value']);
}

/*
 * Same "nice axis" rule as the line chart: the smallest 1/2/5 × 10ⁿ step that
 * covers the maximum in four gridlines. An all-zero series keeps step 1, so the
 * scale below is always a positive number.
 */
if ($brMax <= 0) {
    $brStep = 1;
} else {
    $brRough = $brMax / $brTicks;
    $brMagnitude = 10 ** max(0, (int) floor(log10($brRough)));
    $brStep = $brMagnitude * 10;

    foreach ([1, 2, 5, 10] as $brMultiplier) {
        if ($brMagnitude * $brMultiplier >= $brRough) {
            $brStep = $brMagnitude * $brMultiplier;
            break;
        }
    }
}

$brStep = max(1, (int) $brStep);
$brTop = $brStep * $brTicks;

$brUid = 'br' . substr(md5($brTitle . '|' . $brCount . '|' . uniqid('', true)), 0, 10);

$brTotal = 0;

foreach ($brRows as $brRow) {
    $brTotal += $brRow['value'];
}

$brAccessible = ($brTitle !== '' ? $brTitle . ' — ' : '') . t('common.total') . ': ' . $brTotal;

?>
<div class="chart">
    <svg viewBox="0 0 <?= e((string) $brWidth) ?> <?= e((string) $brHeight) ?>"
         preserveAspectRatio="xMidYMid meet" width="100%"
         role="img" aria-label="<?= e($brAccessible) ?>">
        <title><?= e($brAccessible) ?></title>
        <defs>
            <linearGradient id="<?= e($brUid) ?>-bar" gradientUnits="userSpaceOnUse"
                            x1="<?= e((string) $brPlotX) ?>" y1="0"
                            x2="<?= e((string) ($brPlotX + $brPlotWidth)) ?>" y2="0">
                <stop offset="0" stop-color="#1273d4"/>
                <stop offset="1" stop-color="#7cf3ff"/>
            </linearGradient>
        </defs>

        <?php /* Vertical gridlines and their captions. */ ?>
        <?php for ($brTick = 0; $brTick <= $brTicks; $brTick++) { ?>
            <?php
            $brTickValue = $brStep * $brTick;
            $brTickX = round($brPlotX + ($brTickValue / $brTop * $brPlotWidth), 2);
            ?>
            <line x1="<?= e((string) $brTickX) ?>" y1="<?= e((string) $brPadTop) ?>"
                  x2="<?= e((string) $brTickX) ?>"
                  y2="<?= e((string) ($brHeight - $brPadBottom)) ?>"
                  stroke="currentColor" stroke-opacity="<?= $brTick === 0 ? '0.28' : '0.12' ?>"
                  stroke-width="1"/>
            <text x="<?= e((string) $brTickX) ?>" y="<?= e((string) ($brHeight - 9)) ?>"
                  text-anchor="middle" font-size="11" fill="currentColor" fill-opacity="0.62">
                <?= e((string) $brTickValue) ?>
            </text>
        <?php } ?>

        <?php /* One row per category: caption, track, bar and value. */ ?>
        <?php foreach ($brRows as $brIndex => $brRow) { ?>
            <?php
            $brRowTop = $brPadTop + ($brIndex * $brRowHeight);
            $brBarY = round($brRowTop + (($brRowHeight - $brBarHeight) / 2), 2);
            $brBarWidth = round($brRow['value'] / $brTop * $brPlotWidth, 2);
            $brTextY = round($brBarY + ($brBarHeight / 2) + 4, 2);
            $brRadius = round(min(8, $brBarHeight / 2), 2);
            $brCaption = Text::truncate($brRow['label'], 28);
            ?>
            <text x="<?= e((string) ($brLabelWidth - 12)) ?>" y="<?= e((string) $brTextY) ?>"
                  text-anchor="end" font-size="12" fill="currentColor" fill-opacity="0.82">
                <?= e($brCaption) ?>
                <title><?= e($brRow['label']) ?></title>
            </text>

            <rect x="<?= e((string) $brPlotX) ?>" y="<?= e((string) $brBarY) ?>"
                  width="<?= e((string) $brPlotWidth) ?>" height="<?= e((string) $brBarHeight) ?>"
                  rx="<?= e((string) $brRadius) ?>"
                  fill="currentColor" fill-opacity="0.07"/>

            <?php if ($brBarWidth > 0.5) { ?>
                <rect x="<?= e((string) $brPlotX) ?>" y="<?= e((string) $brBarY) ?>"
                      width="<?= e((string) $brBarWidth) ?>" height="<?= e((string) $brBarHeight) ?>"
                      rx="<?= e((string) $brRadius) ?>"
                      fill="url(#<?= e($brUid) ?>-bar)">
                    <title><?= e($brRow['label'] . ': ' . $brRow['value']) ?></title>
                </rect>
            <?php } ?>

            <text x="<?= e((string) round($brPlotX + $brBarWidth + 10, 2)) ?>"
                  y="<?= e((string) $brTextY) ?>" text-anchor="start" font-size="12"
                  fill="currentColor" fill-opacity="0.9"><?= e((string) $brRow['value']) ?></text>
        <?php } ?>
    </svg>
</div>
<?php
unset(
    $brRaw, $brRows, $brRow, $brEntry, $brLabel, $brValue, $brIndex,
    $brTick, $brTickValue, $brTickX, $brRowTop, $brBarY, $brBarWidth, $brTextY,
    $brRadius, $brCaption, $brMultiplier
);
?>
