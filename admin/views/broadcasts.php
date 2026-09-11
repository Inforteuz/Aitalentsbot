<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — broadcast history.
 *
 * Every campaign ever started, newest first: what was sent, to whom, how far it
 * got and how it ended. The row's mini progress bar is an inline SVG (two
 * rectangles) rather than a styled <div>, because the panel's CSP forbids the
 * inline `style` attribute a percentage width would need.
 *
 * "Ko‘rish" opens the composer with `?id=`, which is where a paused campaign can
 * be resumed; "O‘chirish" is a POST form guarded by data-confirm and removes the
 * campaign together with its queued targets.
 *
 * Variables handed over by BroadcastController::list():
 *   $title, $subtitle, $rows, $total, $page, $pages, $perPage, $statusLabels, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */

/* ---------------------------------------------------------------------------
 | 1. Input
 */
$bsRows = isset($rows) && is_array($rows) ? $rows : [];
$bsTotal = isset($total) && is_numeric($total) ? max(0, (int) $total) : count($bsRows);
$bsPage = isset($page) && is_numeric($page) ? max(1, (int) $page) : 1;
$bsPages = isset($pages) && is_numeric($pages) ? max(1, (int) $pages) : 1;
$bsPerPage = isset($perPage) && is_numeric($perPage) ? max(1, (int) $perPage) : 20;
$bsLabels = isset($statusLabels) && is_array($statusLabels) ? $statusLabels : [];
$bsLocale = isset($locale) && is_string($locale) && $locale !== '' ? $locale : panel_locale();

/** Thousands separated integer. */
$bsNumber = static function (mixed $value): string {
    return number_format((float) (is_numeric($value) ? $value : 0), 0, ',', ' ');
};

/** Campaign status => badge modifier of the stylesheet. */
$bsBadge = static function (string $status): string {
    return match ($status) {
        'running' => 'info',
        'paused'  => 'warn',
        'done'    => 'ok',
        'failed'  => 'danger',
        default   => 'muted',
    };
};

/** Campaign status => translated caption, falling back to the controller's map. */
$bsStatusLabel = static function (string $status) use ($bsLabels): string {
    $label = trim((string) ($bsLabels[$status] ?? ''));

    if ($label !== '') {
        return $label;
    }

    return match ($status) {
        'draft'   => t('panel.broadcast_draft'),
        'running' => t('panel.broadcast_running'),
        'paused'  => t('panel.broadcast_paused'),
        'done'    => t('panel.broadcast_done'),
        'failed'  => t('panel.broadcast_failed'),
        default   => t('status.unknown'),
    };
};

/**
 * The audience of a campaign, spelled out: the preset first, then the concrete
 * status / district / direction the filter narrowed it down to.
 *
 * @param array<string,mixed> $filters
 * @return array<int,string>
 */
$bsAudience = static function (array $filters) use ($bsLocale): array {
    $chips = [];

    $audience = (string) ($filters['audience'] ?? '');

    if ($audience !== '' && in_array($audience, ['all', 'registered', 'status', 'district', 'direction'], true)) {
        $chips[] = t('panel.broadcast_audience_' . $audience);
    }

    // BroadcastService normalises `status` to a list (an audience may target
    // several statuses at once), but an older row may still hold a bare string.
    $statusRaw  = $filters['status'] ?? '';
    $statusList = is_array($statusRaw) ? $statusRaw : ($statusRaw === '' ? [] : [$statusRaw]);

    foreach ($statusList as $statusValue) {
        $case = is_scalar($statusValue) ? RegistrationStatus::tryOrNull((string) $statusValue) : null;

        if ($case !== null) {
            $chips[] = Lang::t($case->labelKey(), $bsLocale);
        }
    }

    // The filters column is JSON written by an earlier release (or by hand), so
    // a value here can be anything, including an array. Render only scalars.
    $districtRaw = $filters['district'] ?? '';
    $district    = is_scalar($districtRaw) ? (string) $districtRaw : '';

    if ($district !== '' && Catalog::hasDistrict($district)) {
        $chips[] = Catalog::districtLabel($district, $bsLocale);
    }

    $directionRaw = $filters['direction'] ?? '';
    $direction    = is_scalar($directionRaw) ? (string) $directionRaw : '';

    if ($direction !== '' && Catalog::hasDirection($direction)) {
        $chips[] = Catalog::directionLabel($direction, $bsLocale, false);
    }

    if ($chips === []) {
        $chips[] = t('panel.filter_all');
    }

    return $chips;
};

/**
 * A 120x8 progress bar as inline SVG — CSP safe, no stylesheet gymnastics.
 * The track uses the secondary chart colour, the fill the accent gradient stop.
 */
$bsBar = static function (float $percent): string {
    $percent = max(0.0, min(100.0, $percent));
    $width = round($percent, 2);

    $svg = '<svg viewBox="0 0 100 8" width="120" height="8" preserveAspectRatio="none"'
        . ' role="img" aria-label="' . e(number_format($percent, 1, '.', ' ') . '%') . '"'
        . ' focusable="false">'
        . '<rect class="chart__bar--alt" x="0" y="0" width="100" height="8" rx="4"/>';

    if ($width > 0) {
        $svg .= '<rect class="chart__bar" x="0" y="0" width="' . e((string) $width) . '" height="8" rx="4"/>';
    }

    return $svg . '</svg>';
};

?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('panel.broadcasts_title')) ?></h2>
        <div class="card__actions">
            <span class="chip"><?= e(t('common.total')) ?>: <?= e($bsNumber($bsTotal)) ?></span>
            <a class="btn btn--primary btn--sm" href="<?= e(url(['p' => 'broadcast'])) ?>">
                <?= e(t('panel.broadcast_title')) ?>
            </a>
        </div>
    </div>

    <?php if ($bsRows === []) { ?>
        <div class="card__body">
            <div class="empty">
                <svg class="empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M3 11.5 21 4l-7.5 18-2.5-7.5z"/><path d="M11 13 21 4"/>
                </svg>
                <p class="empty__title"><?= e(t('panel.broadcasts_empty')) ?></p>
                <p class="empty__text"><?= e(t('panel.broadcast_subtitle')) ?></p>
            </div>
        </div>
    <?php } else { ?>
        <div class="card__body card__body--flush">
            <div class="table-wrap table-wrap--sticky">
                <table class="table">
                    <thead>
                    <tr>
                        <th scope="col" class="shrink"><?= e(t('panel.th_id')) ?></th>
                        <th scope="col" class="nowrap"><?= e(t('panel.th_created')) ?></th>
                        <th scope="col"><?= e(t('panel.th_text')) ?></th>
                        <th scope="col"><?= e(t('panel.broadcast_audience')) ?></th>
                        <th scope="col" class="num"><?= e(t('panel.th_total')) ?></th>
                        <th scope="col" class="num"><?= e(t('panel.th_sent')) ?></th>
                        <th scope="col" class="num"><?= e(t('panel.th_failed')) ?></th>
                        <th scope="col"><?= e(t('panel.th_status')) ?></th>
                        <th scope="col" class="shrink"><?= e(t('common.percent')) ?></th>
                        <th scope="col" class="shrink"><?= e(t('panel.th_actions')) ?></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bsRows as $bsRow) { ?>
                        <?php
                        if (!is_array($bsRow)) {
                            continue;
                        }

                        $bsId = (int) ($bsRow['id'] ?? 0);
                        $bsStatus = (string) ($bsRow['status'] ?? '');
                        $bsFilters = is_array($bsRow['filters'] ?? null) ? $bsRow['filters'] : [];

                        $bsRowTotal = (int) ($bsRow['total'] ?? 0);
                        $bsRowSent = (int) ($bsRow['sent'] ?? 0);
                        $bsRowFailed = (int) ($bsRow['failed'] ?? 0);
                        $bsProcessed = $bsRowSent + $bsRowFailed;

                        $bsPercent = $bsRowTotal > 0
                            ? min(100.0, ($bsProcessed / $bsRowTotal) * 100)
                            : (in_array($bsStatus, ['done', 'failed'], true) ? 100.0 : 0.0);

                        $bsSnippet = Text::truncate(
                            Text::normalizeSpaces((string) ($bsRow['text'] ?? '')),
                            90
                        );

                        $bsCreated = substr((string) ($bsRow['created_at'] ?? ''), 0, 16);
                        $bsFinished = substr((string) ($bsRow['finished_at'] ?? ''), 0, 16);
                        ?>
                        <tr>
                            <td class="shrink">
                                <a class="page-link" href="<?= e(url(['p' => 'broadcast', 'id' => $bsId])) ?>">
                                    #<?= e((string) $bsId) ?>
                                </a>
                            </td>
                            <td class="nowrap">
                                <span class="cell-main"><?= e($bsCreated) ?></span>
                                <?php if ($bsFinished !== '') { ?>
                                    <span class="cell-sub"><?= e($bsFinished) ?></span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if ($bsSnippet === '') { ?>
                                    <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                                <?php } else { ?>
                                    <?= e($bsSnippet) ?>
                                <?php } ?>
                            </td>
                            <td>
                                <?php foreach ($bsAudience($bsFilters) as $bsChip) { ?>
                                    <span class="chip chip--plain"><?= e($bsChip) ?></span>
                                <?php } ?>
                            </td>
                            <td class="num tabular"><?= e($bsNumber($bsRowTotal)) ?></td>
                            <td class="num tabular"><?= e($bsNumber($bsRowSent)) ?></td>
                            <td class="num tabular"><?= e($bsNumber($bsRowFailed)) ?></td>
                            <td>
                                <span class="badge badge--<?= e($bsBadge($bsStatus)) ?>">
                                    <?= e($bsStatusLabel($bsStatus)) ?>
                                </span>
                            </td>
                            <td class="shrink">
                                <?= $bsBar($bsPercent) ?>
                                <span class="cell-sub tabular"><?= e(number_format($bsPercent, 1, '.', ' ')) ?>%</span>
                            </td>
                            <td class="shrink">
                                <div class="table__actions">
                                    <a class="btn btn--ghost btn--sm"
                                       href="<?= e(url(['p' => 'broadcast', 'id' => $bsId])) ?>">
                                        <?= e(t('panel.view')) ?>
                                    </a>
                                    <form method="post"
                                          action="<?= e(url(['p' => 'broadcasts', 'a' => 'delete'])) ?>"
                                          data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= e((string) $bsId) ?>">
                                        <button class="btn btn--danger btn--sm" type="submit">
                                            <?= e(t('common.delete')) ?>
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

        <div class="card__foot">
            <?php
            $view->partial('pagination', [
                'page'    => $bsPage,
                'pages'   => $bsPages,
                'total'   => $bsTotal,
                'perPage' => $bsPerPage,
                'query'   => ['p' => 'broadcasts'],
            ]);
            ?>
        </div>
    <?php } ?>
</section>
<?php
unset(
    $bsRows, $bsTotal, $bsPage, $bsPages, $bsPerPage, $bsLabels, $bsLocale,
    $bsNumber, $bsBadge, $bsStatusLabel, $bsAudience, $bsBar,
    $bsRow, $bsId, $bsStatus, $bsFilters, $bsRowTotal, $bsRowSent, $bsRowFailed,
    $bsProcessed, $bsPercent, $bsSnippet, $bsCreated, $bsFinished, $bsChip
);
?>
