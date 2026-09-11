<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — log viewer.
 *
 * Tails one day of the bot's rotating log file. The controller already parsed
 * every line into time / level / message and applied the severity filter, so
 * this template only has to colour the lines and offer the three selectors
 * (day, minimum level, number of lines) plus a raw download.
 *
 * The colours come from the `.lvl-*` classes of assets/app.css — the panel's
 * CSP forbids inline styles, so a class is the only way to paint a line.
 *
 * Variables handed over by LogController::logs():
 *   $title, $subtitle, $lines, $files, $date, $level, $levels, $limit,
 *   $limitOptions, $logLevel, $enabled, $locale
 */

/** @var \AiTalents\Admin\View $view */

$lvLines        = isset($lines) && is_array($lines) ? $lines : [];
$lvFiles        = isset($files) && is_array($files) ? $files : [];
$lvLevels       = isset($levels) && is_array($levels) ? $levels : ['debug', 'info', 'warning', 'error'];
$lvLimitOptions = isset($limitOptions) && is_array($limitOptions) ? $limitOptions : [100, 200, 500, 1000, 2000];

$lvDate     = isset($date) && is_string($date) ? trim($date) : '';
$lvLevel    = isset($level) && is_string($level) ? strtolower(trim($level)) : '';
$lvLimit    = isset($limit) && is_numeric($limit) ? max(1, (int) $limit) : 200;
$lvLogLevel = isset($logLevel) && is_string($logLevel) ? strtoupper($logLevel) : '';
$lvEnabled  = !isset($enabled) || (bool) $enabled;

/** The days a log file exists for, newest first. */
$lvDateOptions = [];

foreach ($lvFiles as $lvFile) {
    if (is_string($lvFile) && $lvFile !== '') {
        $lvDateOptions[] = ['value' => $lvFile, 'label' => $lvFile];
    }
}

/**
 * Severity choices. The names are the logger's own technical identifiers
 * (DEBUG/INFO/WARNING/ERROR), so they are printed as-is in every language.
 */
$lvLevelOptions = [];

foreach ($lvLevels as $lvLevelName) {
    if (is_string($lvLevelName) && $lvLevelName !== '') {
        $lvLevelOptions[] = ['value' => $lvLevelName, 'label' => strtoupper($lvLevelName)];
    }
}

/** How many lines to tail. */
$lvLimitChoices = [];

foreach ($lvLimitOptions as $lvOption) {
    if (is_numeric($lvOption)) {
        $lvLimitChoices[] = ['value' => (string) (int) $lvOption, 'label' => (string) (int) $lvOption];
    }
}

// The download link only makes sense while a file for that day is on disk.
$lvDownloadUrl = $lvDate === '' ? '' : url(['p' => 'logs', 'a' => 'download', 'date' => $lvDate]);

?>
<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e(t('panel.logs_title')) ?></h2>
        <div class="card__actions">
            <span class="chip">
                <?= e(t('panel.logs_level')) ?>:
                <?= e($lvLogLevel === '' ? t('common.unknown') : $lvLogLevel) ?>
            </span>
            <span class="chip"><?= e($lvEnabled ? t('common.enabled') : t('common.disabled')) ?></span>
            <?php if ($lvDownloadUrl !== '') { ?>
                <a class="btn btn--ghost btn--sm" href="<?= e($lvDownloadUrl) ?>" download>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" focusable="false">
                        <path d="M12 4.5v10"/><path d="m8 11 4 4 4-4"/><path d="M5 19.5h14"/>
                    </svg>
                    <?= e(t('panel.logs_download')) ?>
                </a>
            <?php } ?>
        </div>
    </div>
    <div class="card__body">
        <?php
        $view->partial('filters', [
            'action' => 'logs',
            'reset'  => false,
            'fields' => [
                [
                    'type'    => 'select',
                    'name'    => 'date',
                    'label'   => t('panel.logs_date'),
                    'value'   => $lvDate,
                    'empty'   => false,
                    'options' => $lvDateOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'level',
                    'label'   => t('panel.logs_level'),
                    'value'   => $lvLevel,
                    'options' => $lvLevelOptions,
                ],
                [
                    'type'    => 'select',
                    'name'    => 'lines',
                    'label'   => t('panel.logs_lines'),
                    'value'   => (string) $lvLimit,
                    'empty'   => false,
                    'options' => $lvLimitChoices,
                ],
            ],
        ]);
        ?>
    </div>
</section>

<section class="card">
    <div class="card__head">
        <h2 class="card__title"><?= e($lvDate === '' ? t('panel.logs_title') : $lvDate) ?></h2>
        <span class="chip"><?= e(t('common.count')) ?>: <?= e((string) count($lvLines)) ?></span>
    </div>
    <div class="card__body">
        <?php if ($lvLines === []) { ?>
            <div class="empty">
                <svg class="empty__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true" focusable="false">
                    <path d="M5 4.5h9l5 5V19a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19z"/>
                    <path d="M14 4.5V10h5"/><path d="M8.5 13.5h7"/><path d="M8.5 16.5h4.5"/>
                </svg>
                <p class="empty__title"><?= e(t('panel.logs_empty')) ?></p>
                <?php if (!$lvEnabled) { ?>
                    <p class="empty__text"><?= e(t('common.disabled')) ?></p>
                <?php } ?>
            </div>
        <?php } else { ?>
            <pre class="code" tabindex="0" aria-label="<?= e(t('panel.logs_title')) ?>"><?php
                foreach ($lvLines as $lvLine) {
                    if (!is_array($lvLine)) {
                        continue;
                    }

                    $lvLineLevel = strtolower(trim((string) ($lvLine['level'] ?? '')));
                    $lvClass = in_array($lvLineLevel, $lvLevels, true) ? ' class="lvl-' . $lvLineLevel . '"' : '';
                    $lvText = (string) ($lvLine['raw'] ?? ($lvLine['message'] ?? ''));

                    // Every line is one <span> so its severity can be coloured;
                    // the newline lives outside the element to keep copy/paste
                    // of the block clean.
                    echo '<span' . $lvClass . '>' . e($lvText) . '</span>' . "\n";
                }
            ?></pre>
        <?php } ?>
    </div>
</section>
<?php
unset(
    $lvLines, $lvFiles, $lvLevels, $lvLimitOptions, $lvDate, $lvLevel, $lvLimit, $lvLogLevel,
    $lvEnabled, $lvDateOptions, $lvFile, $lvLevelOptions, $lvLevelName, $lvLimitChoices,
    $lvOption, $lvDownloadUrl, $lvLine, $lvLineLevel, $lvClass, $lvText
);
?>
