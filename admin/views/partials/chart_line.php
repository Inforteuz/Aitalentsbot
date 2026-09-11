<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — inline SVG area/line chart.
 *
 * A dependency-free trend chart drawn entirely in PHP: no JavaScript library,
 * no canvas, no external request — which is exactly what the panel's strict
 * Content-Security-Policy demands. The result is a single <svg> element that
 * scales with its container (viewBox + preserveAspectRatio) and inherits the
 * theme colour of the surrounding card through `currentColor`.
 *
 * Variables:
 *   $data   array  list of ['label' => string, 'value' => int] — required
 *   $height ?int   drawing height in viewBox units (140…520, default 240)
 *   $title  ?string accessible name of the chart
 *   $unit   ?string appended to the tooltip values (optional)
 *
 * Empty series and all-zero series are handled explicitly: the first renders the
 * "not enough data" placeholder, the second draws a flat line on the baseline
 * without ever dividing by zero.
 */

$lnRaw = isset($data) && is_array($data) ? $data : [];
$lnTitle = isset($title) && is_string($title) ? trim($title) : '';
$lnUnit = isset($unit) && is_string($unit) ? trim($unit) : '';

/* ---------------------------------------------------------------------------
 | 1. Normalise the series
 */
$lnPoints = [];

foreach ($lnRaw as $lnEntry) {
    if (!is_array($lnEntry)) {
        continue;
    }

    $lnValue = $lnEntry['value'] ?? 0;
    $lnValue = is_numeric($lnValue) ? (int) $lnValue : 0;

    $lnPoints[] = [
        'label' => trim((string) ($lnEntry['label'] ?? '')),
        // Negative counts are meaningless here and would break the scale.
        'value' => max(0, $lnValue),
    ];
}

if ($lnPoints === []) {
    ?>
    <div class="empty"><?= e(t('panel.chart_empty')) ?></div>
    <?php
    return;
}

/* ---------------------------------------------------------------------------
 | 2. Geometry
 */
$lnWidth = 720;
$lnHeight = isset($height) && is_numeric($height) ? (int) $height : 240;
$lnHeight = max(140, min(520, $lnHeight));

$lnPadLeft = 48;
$lnPadRight = 18;
$lnPadTop = 18;
$lnPadBottom = 32;

$lnPlotWidth = $lnWidth - $lnPadLeft - $lnPadRight;
$lnPlotHeight = $lnHeight - $lnPadTop - $lnPadBottom;
$lnBaseline = $lnPadTop + $lnPlotHeight;

$lnCount = count($lnPoints);
$lnTicks = 4;

$lnMax = 0;

foreach ($lnPoints as $lnPoint) {
    $lnMax = max($lnMax, $lnPoint['value']);
}

/*
 * A "nice" axis top: the smallest 1/2/5 × 10ⁿ step that covers the maximum in
 * four gridlines. With an all-zero series the step falls back to 1, so the
 * scale stays valid and the division below can never be by zero.
 */
if ($lnMax <= 0) {
    $lnStep = 1;
} else {
    $lnRough = $lnMax / $lnTicks;
    $lnMagnitude = 10 ** max(0, (int) floor(log10($lnRough)));
    $lnStep = $lnMagnitude * 10;

    foreach ([1, 2, 5, 10] as $lnMultiplier) {
        if ($lnMagnitude * $lnMultiplier >= $lnRough) {
            $lnStep = $lnMagnitude * $lnMultiplier;
            break;
        }
    }
}

$lnStep = max(1, (int) $lnStep);
$lnTop = $lnStep * $lnTicks;

/** Horizontal position of the point at index $index. */
$lnX = static function (int $index) use ($lnCount, $lnPadLeft, $lnPlotWidth): float {
    if ($lnCount <= 1) {
        return round($lnPadLeft + ($lnPlotWidth / 2), 2);
    }

    return round($lnPadLeft + ($index * $lnPlotWidth / ($lnCount - 1)), 2);
};

/** Vertical position of a value. */
$lnY = static function (int $value) use ($lnTop, $lnPadTop, $lnPlotHeight): float {
    return round($lnPadTop + $lnPlotHeight - ($value / $lnTop * $lnPlotHeight), 2);
};

/* ---------------------------------------------------------------------------
 | 3. Paths
 */
$lnLine = [];
$lnArea = [];

foreach ($lnPoints as $lnIndex => $lnPoint) {
    $lnPx = $lnX($lnIndex);
    $lnPy = $lnY($lnPoint['value']);

    $lnLine[] = $lnPx . ',' . $lnPy;
    $lnArea[] = ($lnIndex === 0 ? 'M' : 'L') . ' ' . $lnPx . ' ' . $lnPy;
}

$lnAreaPath = 'M ' . $lnX(0) . ' ' . $lnBaseline . ' '
    . implode(' ', $lnArea)
    . ' L ' . $lnX($lnCount - 1) . ' ' . $lnBaseline . ' Z';

// Unique ids: several charts may live on the same page.
$lnUid = 'ln' . substr(md5($lnTitle . '|' . $lnCount . '|' . uniqid('', true)), 0, 10);

// Show at most eight captions on the x-axis, always including the newest one.
$lnStride = (int) max(1, (int) ceil($lnCount / 8));

$lnTotal = 0;

foreach ($lnPoints as $lnPoint) {
    $lnTotal += $lnPoint['value'];
}

$lnAccessible = ($lnTitle !== '' ? $lnTitle . ' — ' : '')
    . t('common.total') . ': ' . $lnTotal;

?>
<div class="chart">
    <svg viewBox="0 0 <?= e((string) $lnWidth) ?> <?= e((string) $lnHeight) ?>"
         preserveAspectRatio="xMidYMid meet" width="100%"
         role="img" aria-label="<?= e($lnAccessible) ?>">
        <title><?= e($lnAccessible) ?></title>
        <defs>
            <linearGradient id="<?= e($lnUid) ?>-fill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0" stop-color="#22a7ff" stop-opacity="0.42"/>
                <stop offset="1" stop-color="#22a7ff" stop-opacity="0.02"/>
            </linearGradient>
            <linearGradient id="<?= e($lnUid) ?>-stroke" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" stop-color="#1273d4"/>
                <stop offset="1" stop-color="#7cf3ff"/>
            </linearGradient>
        </defs>

        <?php /* Horizontal gridlines with their value captions. */ ?>
        <?php for ($lnTick = 0; $lnTick <= $lnTicks; $lnTick++) { ?>
            <?php
            $lnTickValue = $lnStep * $lnTick;
            $lnTickY = $lnY($lnTickValue);
            ?>
            <line x1="<?= e((string) $lnPadLeft) ?>" y1="<?= e((string) $lnTickY) ?>"
                  x2="<?= e((string) ($lnWidth - $lnPadRight)) ?>" y2="<?= e((string) $lnTickY) ?>"
                  stroke="currentColor" stroke-opacity="<?= $lnTick === 0 ? '0.28' : '0.12' ?>"
                  stroke-width="1"/>
            <text x="<?= e((string) ($lnPadLeft - 10)) ?>" y="<?= e((string) ($lnTickY + 4)) ?>"
                  text-anchor="end" font-size="11" fill="currentColor" fill-opacity="0.62">
                <?= e((string) $lnTickValue) ?>
            </text>
        <?php } ?>

        <?php /* The series itself: filled area, then the line on top. */ ?>
        <path d="<?= e($lnAreaPath) ?>" fill="url(#<?= e($lnUid) ?>-fill)"/>
        <polyline points="<?= e(implode(' ', $lnLine)) ?>" fill="none"
                  stroke="url(#<?= e($lnUid) ?>-stroke)" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round"/>

        <?php /* Data points — kept off very dense series so they do not merge. */ ?>
        <?php if ($lnCount <= 32) { ?>
            <?php foreach ($lnPoints as $lnIndex => $lnPoint) { ?>
                <circle cx="<?= e((string) $lnX($lnIndex)) ?>" cy="<?= e((string) $lnY($lnPoint['value'])) ?>"
                        r="3.2" fill="#7cf3ff" stroke="#0a1b3d" stroke-width="1.2">
                    <title><?= e($lnPoint['label'] . ': ' . $lnPoint['value'] . ($lnUnit === '' ? '' : ' ' . $lnUnit)) ?></title>
                </circle>
            <?php } ?>
        <?php } ?>

        <?php /* X-axis captions. */ ?>
        <?php foreach ($lnPoints as $lnIndex => $lnPoint) { ?>
            <?php
            $lnIsLast = $lnIndex === $lnCount - 1;

            if ($lnPoint['label'] === '' || (!$lnIsLast && $lnIndex % $lnStride !== 0)) {
                continue;
            }

            $lnAnchor = 'middle';
            $lnLabelX = $lnX($lnIndex);

            if ($lnIndex === 0 && $lnCount > 1) {
                $lnAnchor = 'start';
            } elseif ($lnIsLast && $lnCount > 1) {
                $lnAnchor = 'end';
            }
            ?>
            <text x="<?= e((string) $lnLabelX) ?>" y="<?= e((string) ($lnHeight - 10)) ?>"
                  text-anchor="<?= e($lnAnchor) ?>" font-size="11"
                  fill="currentColor" fill-opacity="0.62"><?= e($lnPoint['label']) ?></text>
        <?php } ?>
    </svg>
</div>
<?php
unset(
    $lnRaw, $lnPoints, $lnPoint, $lnEntry, $lnValue, $lnLine, $lnArea, $lnAreaPath,
    $lnX, $lnY, $lnIndex, $lnPx, $lnPy, $lnTick, $lnTickValue, $lnTickY,
    $lnAnchor, $lnLabelX, $lnIsLast, $lnMultiplier
);
?>
