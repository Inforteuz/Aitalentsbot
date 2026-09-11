<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — broadcast composer.
 *
 * Three steps on one screen: write the message, pick the audience, send it.
 * Delivery itself never happens in a single request — assets/app.js posts to
 * `?p=broadcast&a=start` once and then polls `?p=broadcast&a=run` batch after
 * batch, painting the progress bar from the JSON envelope BroadcastController
 * returns. Everything this template does is hand that script the endpoints and
 * the hooks it looks for; the markup contract is documented in section 08 of
 * assets/app.js:
 *
 *   [data-broadcast]            the root element carrying every data-*-url
 *   [data-broadcast-form]       the form whose fields describe the audience
 *   [data-broadcast-text]       the textarea (data-max = character limit)
 *   [data-char-count]           the live character counter
 *   [data-recipients]           the live audience count (alias: data-audience-count)
 *   [data-broadcast-start|pause|resume]   the three delivery buttons
 *   .progress__bar              the bar whose width is animated (alias: data-broadcast-bar)
 *   [data-stat-total|sent|failed|remaining|percent]  the counters
 *   [data-broadcast-report]     the final delivery report, hidden until the end
 *
 * The panel's CSP forbids inline scripts and styles, so this file contains
 * neither: the preview is rendered server side from the current draft and the
 * progress bar is painted by app.js through the CSSOM.
 *
 * Variables handed over by BroadcastController::compose():
 *   $title, $subtitle, $draft, $audienceCount, $filters, $presets, $statuses,
 *   $districts, $directions, $maxChars, $batchSize, $active, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */
/** @var \AiTalents\App $app */

/* ---------------------------------------------------------------------------
 | 1. Normalise everything the controller handed over
 */
$bcDraft = isset($draft) && is_array($draft) ? $draft : [];

$bcText = (string) ($bcDraft['text'] ?? '');
$bcAudience = (string) ($bcDraft['audience'] ?? 'all');
$bcStatusValue = (string) ($bcDraft['status'] ?? '');
$bcDistrictValue = (string) ($bcDraft['district'] ?? '');
$bcDirectionValue = (string) ($bcDraft['direction'] ?? '');

$bcMax = isset($maxChars) && is_numeric($maxChars) ? max(1, (int) $maxChars) : 4000;
$bcBatch = isset($batchSize) && is_numeric($batchSize) ? max(1, (int) $batchSize) : 20;
$bcRecipients = isset($audienceCount) && is_numeric($audienceCount) ? max(0, (int) $audienceCount) : 0;

$bcPresets = isset($presets) && is_array($presets) ? $presets : [];
$bcStatuses = isset($statuses) && is_array($statuses) ? $statuses : [];
$bcDistricts = isset($districts) && is_array($districts) ? $districts : [];
$bcDirections = isset($directions) && is_array($directions) ? $directions : [];

/** Thousands separated integer — the panel prints every counter like this. */
$bcNumber = static function (mixed $value): string {
    return number_format((float) (is_numeric($value) ? $value : 0), 0, ',', ' ');
};

/* ---------------------------------------------------------------------------
 | 2. The campaign the progress panel talks about
 |
 | `$active` is set when a campaign is running (or when the URL carries an id),
 | so a reloaded page picks the delivery back up instead of losing it.
 */
$bcActive = isset($active) && is_array($active) ? $active : null;
$bcCampaign = $bcActive !== null && is_array($bcActive['campaign'] ?? null) ? $bcActive['campaign'] : [];
$bcProgress = $bcActive !== null && is_array($bcActive['progress'] ?? null) ? $bcActive['progress'] : [];

$bcId = $bcActive !== null ? (int) ($bcActive['id'] ?? 0) : 0;
$bcState = (string) ($bcProgress['status'] ?? '');

$bcTotal = (int) ($bcProgress['total'] ?? 0);
$bcSent = (int) ($bcProgress['sent'] ?? 0);
$bcFailed = (int) ($bcProgress['failed'] ?? 0);
$bcRemaining = (int) ($bcProgress['remaining'] ?? 0);
$bcPercent = isset($bcProgress['percent']) && is_numeric($bcProgress['percent'])
    ? max(0.0, min(100.0, (float) $bcProgress['percent']))
    : 0.0;

// Which of the three delivery buttons make sense right now. app.js flips these
// again as soon as the campaign changes state.
$bcIsRunning = $bcId > 0 && $bcState === 'running';
$bcIsPaused = $bcId > 0 && $bcState === 'paused';
$bcIsDone = $bcId > 0 && in_array($bcState, ['done', 'failed'], true);

/** Campaign status => badge modifier of the stylesheet. */
$bcBadge = static function (string $status): string {
    return match ($status) {
        'running' => 'info',
        'paused'  => 'warn',
        'done'    => 'ok',
        'failed'  => 'danger',
        default   => 'muted',
    };
};

/** Campaign status => translated caption. */
$bcStateLabel = static function (string $status): string {
    return match ($status) {
        'draft'   => t('panel.broadcast_draft'),
        'running' => t('panel.broadcast_running'),
        'paused'  => t('panel.broadcast_paused'),
        'done'    => t('panel.broadcast_done'),
        'failed'  => t('panel.broadcast_failed'),
        default   => t('status.unknown'),
    };
};

/* Cities first, then districts — the same grouping the bot's keyboard uses. */
$bcCityOptions = [];
$bcDistrictOptions = [];

foreach ($bcDistricts as $bcEntry) {
    if (!is_array($bcEntry)) {
        continue;
    }

    $bcKey = (string) ($bcEntry['key'] ?? '');

    if ($bcKey === '') {
        continue;
    }

    $bcOption = ['key' => $bcKey, 'label' => (string) ($bcEntry['label'] ?? $bcKey)];

    if ((string) ($bcEntry['type'] ?? 'district') === 'city') {
        $bcCityOptions[] = $bcOption;
    } else {
        $bcDistrictOptions[] = $bcOption;
    }
}

/* The JSON endpoints app.js drives the sender with. */
$bcUrls = [
    'count'  => url(['p' => 'broadcast', 'a' => 'count']),
    'start'  => url(['p' => 'broadcast', 'a' => 'start']),
    'run'    => url(['p' => 'broadcast', 'a' => 'run']),
    'cancel' => url(['p' => 'broadcast', 'a' => 'cancel']),
    'resume' => url(['p' => 'broadcast', 'a' => 'resume']),
];

?>
<div class="stack"
     data-broadcast
     data-count-url="<?= e($bcUrls['count']) ?>"
     data-start-url="<?= e($bcUrls['start']) ?>"
     data-run-url="<?= e($bcUrls['run']) ?>"
     data-cancel-url="<?= e($bcUrls['cancel']) ?>"
     data-resume-url="<?= e($bcUrls['resume']) ?>"
     data-batch="<?= e((string) $bcBatch) ?>"
     data-id="<?= e((string) $bcId) ?>"
     data-status="<?= e($bcState) ?>"
     data-total="<?= e((string) $bcTotal) ?>"
     data-sent="<?= e((string) $bcSent) ?>"
     data-failed="<?= e((string) $bcFailed) ?>"
     data-remaining="<?= e((string) $bcRemaining) ?>"
     data-percent="<?= e((string) $bcPercent) ?>">

    <?php
    /*
     * The composer form.
     *
     * Its own action is the "send a test to me" endpoint: that keeps the whole
     * screen usable without JavaScript (a real POST, a real flash message) while
     * app.js reads the very same fields to build the audience filter and to
     * start the campaign over fetch().
     */
    ?>
    <form class="stack" method="post" action="<?= e(url(['p' => 'broadcast', 'a' => 'test'])) ?>"
          data-broadcast-form>
        <?= Csrf::field() ?>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.broadcast_step_compose')) ?></h2>
                <div class="card__actions">
                    <span class="char-count" data-char-count
                          data-template="<?= e(t('panel.broadcast_chars')) ?>">
                        <?= e(t('panel.broadcast_chars', [
                            'count' => $bcNumber(mb_strlen($bcText, 'UTF-8')),
                            'max'   => $bcNumber($bcMax),
                        ])) ?>
                    </span>
                    <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'broadcasts'])) ?>">
                        <?= e(t('panel.nav_broadcasts')) ?>
                    </a>
                </div>
            </div>
            <div class="card__body">
                <div class="field">
                    <label class="field__label" for="broadcast-text"><?= e(t('panel.broadcast_text')) ?></label>
                    <textarea class="textarea" id="broadcast-text" name="text" rows="9"
                              maxlength="<?= e((string) $bcMax) ?>"
                              placeholder="<?= e(t('panel.broadcast_text_placeholder')) ?>"
                              data-broadcast-text data-max="<?= e((string) $bcMax) ?>"
                              spellcheck="false"><?= e($bcText) ?></textarea>
                    <p class="field__hint"><?= e(t('panel.broadcast_html_hint')) ?></p>
                    <p class="field__hint">
                        <code>&lt;b&gt;</code>
                        <code>&lt;i&gt;</code>
                        <code>&lt;u&gt;</code>
                        <code>&lt;s&gt;</code>
                        <code>&lt;a href="…"&gt;</code>
                        <code>&lt;code&gt;</code>
                        <code>&lt;pre&gt;</code>
                    </p>
                </div>
            </div>
            <div class="card__foot">
                <button class="btn btn--ghost btn--sm" type="submit">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" focusable="false">
                        <path d="M21 3 10.5 13.5"/><path d="M21 3l-6.5 18-4-8-8-4z"/>
                    </svg>
                    <?= e(t('panel.broadcast_test')) ?>
                </button>
                <span class="meta"><?= e(t('panel.set_admin_ids_hint')) ?></span>
            </div>
        </section>

        <section class="grid-2">
            <?php /* ---- Audience ------------------------------------------ */ ?>
            <article class="card">
                <div class="card__head">
                    <h2 class="card__title"><?= e(t('panel.broadcast_step_audience')) ?></h2>
                    <div class="card__actions">
                        <span class="chip" data-recipients data-audience-count>
                            <?= e(t('panel.broadcast_recipients', ['count' => $bcNumber($bcRecipients)])) ?>
                        </span>
                    </div>
                </div>
                <div class="card__body stack">
                    <div class="stack">
                        <?php foreach ($bcPresets as $bcPreset) { ?>
                            <?php
                            if (!is_array($bcPreset)) {
                                continue;
                            }

                            $bcPresetValue = (string) ($bcPreset['value'] ?? '');

                            if ($bcPresetValue === '') {
                                continue;
                            }
                            ?>
                            <label class="checkbox" for="audience-<?= e($bcPresetValue) ?>">
                                <input type="radio" id="audience-<?= e($bcPresetValue) ?>"
                                       name="audience" value="<?= e($bcPresetValue) ?>"
                                    <?= $bcPresetValue === $bcAudience ? 'checked' : '' ?>>
                                <span><?= e((string) ($bcPreset['label'] ?? $bcPresetValue)) ?></span>
                            </label>
                        <?php } ?>
                    </div>

                    <div class="form-grid">
                        <div class="field">
                            <label class="field__label" for="broadcast-status">
                                <?= e(t('panel.filter_status')) ?>
                            </label>
                            <select class="select" id="broadcast-status" name="status">
                                <option value=""><?= e(t('panel.filter_all')) ?></option>
                                <?php foreach ($bcStatuses as $bcStatusOption) { ?>
                                    <?php
                                    if (!is_array($bcStatusOption)) {
                                        continue;
                                    }

                                    $bcOptionValue = (string) ($bcStatusOption['value'] ?? '');
                                    ?>
                                    <option value="<?= e($bcOptionValue) ?>"
                                        <?= $bcOptionValue === $bcStatusValue ? 'selected' : '' ?>>
                                        <?= e((string) ($bcStatusOption['label'] ?? $bcOptionValue)) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="field__label" for="broadcast-district">
                                <?= e(t('panel.filter_district')) ?>
                            </label>
                            <select class="select" id="broadcast-district" name="district">
                                <option value=""><?= e(t('panel.filter_all')) ?></option>
                                <?php if ($bcCityOptions !== []) { ?>
                                    <optgroup label="<?= e(t('panel.th_district')) ?>">
                                        <?php foreach ($bcCityOptions as $bcCity) { ?>
                                            <option value="<?= e($bcCity['key']) ?>"
                                                <?= $bcCity['key'] === $bcDistrictValue ? 'selected' : '' ?>>
                                                <?= e($bcCity['label']) ?>
                                            </option>
                                        <?php } ?>
                                    </optgroup>
                                <?php } ?>
                                <?php foreach ($bcDistrictOptions as $bcDistrictOption) { ?>
                                    <option value="<?= e($bcDistrictOption['key']) ?>"
                                        <?= $bcDistrictOption['key'] === $bcDistrictValue ? 'selected' : '' ?>>
                                        <?= e($bcDistrictOption['label']) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>

                        <div class="field">
                            <label class="field__label" for="broadcast-direction">
                                <?= e(t('panel.filter_direction')) ?>
                            </label>
                            <select class="select" id="broadcast-direction" name="direction">
                                <option value=""><?= e(t('panel.filter_all')) ?></option>
                                <?php foreach ($bcDirections as $bcDirection) { ?>
                                    <?php
                                    if (!is_array($bcDirection)) {
                                        continue;
                                    }

                                    $bcDirectionKey = (string) ($bcDirection['key'] ?? '');

                                    if ($bcDirectionKey === '') {
                                        continue;
                                    }

                                    // The plain catalogue name: an <option> holds
                                    // text only, and the panel prints no emoji.
                                    $bcDirectionLabel = trim((string) ($bcDirection['label'] ?? $bcDirectionKey));
                                    ?>
                                    <option value="<?= e($bcDirectionKey) ?>"
                                        <?= $bcDirectionKey === $bcDirectionValue ? 'selected' : '' ?>>
                                        <?= e($bcDirectionLabel) ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
            </article>

            <?php /* ---- Preview -------------------------------------------- */ ?>
            <article class="card">
                <div class="card__head">
                    <h2 class="card__title"><?= e(t('panel.broadcast_preview')) ?></h2>
                </div>
                <div class="card__body">
                    <div class="preview" data-broadcast-preview>
                        <?php if (trim($bcText) === '') { ?>
                            <span class="muted"><?= e(t('panel.broadcast_text_placeholder')) ?></span>
                        <?php } else { ?>
                            <?= e($bcText) ?>
                        <?php } ?>
                    </div>
                    <p class="field__hint"><?= e(t('panel.broadcast_html_hint')) ?></p>
                </div>
            </article>
        </section>
    </form>

    <?php /* ---- Delivery ------------------------------------------------- */ ?>
    <section class="card card--accent">
        <div class="card__head">
            <h2 class="card__title"><?= e(t('panel.broadcast_step_send')) ?></h2>
            <div class="card__actions">
                <?php if ($bcId > 0) { ?>
                    <span class="badge badge--<?= e($bcBadge($bcState)) ?>"><?= e($bcStateLabel($bcState)) ?></span>
                    <a class="chip" href="<?= e(url(['p' => 'broadcast', 'id' => $bcId])) ?>">#<?= e((string) $bcId) ?></a>
                <?php } ?>

                <button class="btn btn--primary" type="button" data-broadcast-start
                        <?= $bcIsRunning ? 'disabled' : '' ?>>
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                         aria-hidden="true" focusable="false">
                        <path d="M6 4.5 19 12 6 19.5z"/>
                    </svg>
                    <?= e(t('panel.broadcast_start')) ?>
                </button>

                <?php
                /*
                 * Pause and resume are real POST forms so they keep working when
                 * JavaScript is off; app.js intercepts the click, calls the JSON
                 * endpoint instead and toggles the two buttons itself.
                 */
                ?>
                <form class="btn-row" method="post"
                      action="<?= e(url(['p' => 'broadcast', 'a' => 'cancel'])) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $bcId) ?>">
                    <button class="btn btn--ghost" type="submit" data-broadcast-pause
                            <?= $bcIsRunning ? '' : 'hidden' ?>>
                        <?= e(t('panel.broadcast_pause')) ?>
                    </button>
                    <button class="btn btn--ok" type="submit" data-broadcast-resume
                            formaction="<?= e(url(['p' => 'broadcast', 'a' => 'resume'])) ?>"
                            <?= $bcIsPaused ? '' : 'hidden' ?>>
                        <?= e(t('panel.broadcast_resume')) ?>
                    </button>
                </form>
            </div>
            <?php if ($bcId > 0) { ?>
                <p class="card__hint"><?= e(t('panel.broadcast_progress', [
                    'sent'  => $bcNumber($bcSent),
                    'total' => $bcNumber($bcTotal),
                ])) ?></p>
            <?php } ?>
        </div>
        <div class="card__body">
            <div class="progress progress--lg <?= $bcIsRunning ? 'is-running' : '' ?>"
                 data-broadcast-progress role="progressbar"
                 aria-valuemin="0" aria-valuemax="100"
                 aria-valuenow="<?= e((string) (int) round($bcPercent)) ?>"
                 aria-label="<?= e(t('panel.broadcast_step_send')) ?>">
                <span class="progress__bar" data-progress-bar data-broadcast-bar></span>
            </div>

            <div class="progress-meta">
                <span><?= e(t('panel.th_total')) ?>:
                    <b data-stat-total data-broadcast-total><?= e($bcNumber($bcTotal)) ?></b></span>
                <span><?= e(t('panel.th_sent')) ?>:
                    <b data-stat-sent data-broadcast-sent><?= e($bcNumber($bcSent)) ?></b></span>
                <span><?= e(t('panel.th_failed')) ?>:
                    <b data-stat-failed data-broadcast-failed><?= e($bcNumber($bcFailed)) ?></b></span>
                <span><?= e(t('common.count')) ?>:
                    <b data-stat-remaining><?= e($bcNumber($bcRemaining)) ?></b></span>
                <span><?= e(t('common.percent')) ?>:
                    <b data-stat-percent><?= e(number_format($bcPercent, 1, '.', ' ')) ?>%</b></span>
            </div>

            <p class="preview mt-4" data-broadcast-report <?= $bcIsDone ? '' : 'hidden' ?>>
                <?= e(t('panel.broadcast_report', [
                    'sent'   => $bcNumber($bcSent),
                    'failed' => $bcNumber($bcFailed),
                    'total'  => $bcNumber($bcTotal),
                ])) ?>
            </p>
        </div>
        <?php if ($bcId > 0) { ?>
            <div class="card__foot">
                <span class="meta">
                    <?= e(t('panel.th_text')) ?>:
                    <?= e(Text::truncate(Text::normalizeSpaces((string) ($bcCampaign['text'] ?? '')), 120)) ?>
                </span>
                <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'broadcasts'])) ?>">
                    <?= e(t('panel.nav_broadcasts')) ?>
                </a>
            </div>
        <?php } ?>
    </section>
</div>
<?php
unset(
    $bcDraft, $bcText, $bcAudience, $bcStatusValue, $bcDistrictValue, $bcDirectionValue,
    $bcMax, $bcBatch, $bcRecipients, $bcPresets, $bcStatuses, $bcDistricts, $bcDirections,
    $bcNumber, $bcActive, $bcCampaign, $bcProgress, $bcId, $bcState,
    $bcTotal, $bcSent, $bcFailed, $bcRemaining, $bcPercent, $bcIsRunning, $bcIsPaused, $bcIsDone,
    $bcBadge, $bcStateLabel, $bcCityOptions, $bcDistrictOptions, $bcEntry, $bcKey, $bcOption,
    $bcUrls, $bcPreset, $bcPresetValue, $bcStatusOption, $bcOptionValue, $bcCity,
    $bcDistrictOption, $bcDirection, $bcDirectionKey, $bcDirectionLabel
);
?>
