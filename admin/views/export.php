<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Export landing page.
 *
 * Reached from the sidebar. It reports what the filters currently carried in
 * the query string select, and only then offers the download — the button on
 * the registrations list skips this screen and streams the workbook directly.
 *
 * Variables from ExportController::overview():
 *   $filters     array   the whitelisted registration filters in force
 *   $total       int     how many applications those filters select
 *   $downloadUrl string  the ?p=export&a=download link carrying those filters
 */

use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;

$exFilters = isset($filters) && is_array($filters) ? $filters : [];
$exTotal   = isset($total) ? (int) $total : 0;
$exUrl     = isset($downloadUrl) && is_string($downloadUrl) ? $downloadUrl : '';
$exLocale  = panel_locale();

/** Human-readable chips describing the filters in force. */
$exChips = [];

$exSearch = (string) ($exFilters['q'] ?? '');

if ($exSearch !== '') {
    $exChips[] = t('common.search') . ': ' . $exSearch;
}

$exStatus = RegistrationStatus::tryOrNull((string) ($exFilters['status'] ?? ''));

if ($exStatus !== null) {
    $exChips[] = Lang::t($exStatus->labelKey(), $exLocale);
}

$exDistrict = (string) ($exFilters['district'] ?? '');

if ($exDistrict !== '' && Catalog::hasDistrict($exDistrict)) {
    $exChips[] = Catalog::districtLabel($exDistrict, $exLocale);
}

$exDirection = (string) ($exFilters['direction'] ?? '');

if ($exDirection !== '' && Catalog::hasDirection($exDirection)) {
    $exChips[] = Catalog::directionLabel($exDirection, $exLocale, false);
}

foreach (['date_from' => 'panel.filter_date_from', 'date_to' => 'panel.filter_date_to'] as $exKey => $exLabel) {
    $exValue = (string) ($exFilters[$exKey] ?? '');

    if ($exValue !== '') {
        $exChips[] = t($exLabel) . ': ' . $exValue;
    }
}

?>
<section class="card">
    <div class="card__head">
        <div>
            <h2><?= e(t('panel.export_title')) ?></h2>
            <p class="meta"><?= e(t('panel.export_subtitle')) ?></p>
        </div>
    </div>
    <div class="card__body">
        <?php if ($exChips !== []) { ?>
            <p class="meta__row"><?= e(t('common.filter')) ?>:</p>
            <p>
                <?php foreach ($exChips as $exChip) { ?>
                    <span class="chip"><?= e($exChip) ?></span>
                <?php } ?>
            </p>
        <?php } ?>

        <?php if ($exTotal > 0) { ?>
            <p class="stat__value"><?= e(t('panel.export_rows', ['count' => $exTotal])) ?></p>
            <p class="meta"><?= e(t('panel.export_hint')) ?></p>
            <p>
                <a class="btn btn--primary" href="<?= e($exUrl) ?>">
                    <?= e(t('panel.export_download')) ?>
                </a>
                <a class="btn btn--ghost" href="<?= e(url(['p' => 'registrations'])) ?>">
                    <?= e(t('panel.nav_registrations')) ?>
                </a>
            </p>
        <?php } else { ?>
            <p class="empty"><?= e(t('panel.export_empty')) ?></p>
            <p>
                <a class="btn btn--ghost" href="<?= e(url(['p' => 'registrations'])) ?>">
                    <?= e(t('panel.nav_registrations')) ?>
                </a>
            </p>
        <?php } ?>
    </div>
</section>
