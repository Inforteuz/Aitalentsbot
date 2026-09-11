<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — settings and diagnostics.
 *
 * Five grouped cards:
 *
 *   1. the four runtime settings that live in the `settings` table and are read
 *      through App::setting() — registration gate, required channel, language
 *      question and the extra welcome paragraph;
 *   2. bot health: getMe plus the full webhook picture, with the two webhook
 *      maintenance buttons;
 *   3. the database: driver, size, and how many rows each table holds;
 *   4. the administrators compiled into config.php — read only on purpose;
 *   5. maintenance: clear the log files, purge old audit entries.
 *
 * Every mutating control is a POST form carrying the CSRF field, and the two
 * destructive ones (delete webhook, clear logs, purge audit) also carry
 * data-confirm so assets/app.js asks before the request leaves the browser.
 *
 * Variables handed over by SettingsController::index():
 *   $title, $subtitle, $settings, $webhook, $me, $dbInfo, $adminIds,
 *   $botAdminIds, $locales, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */
/** @var \AiTalents\App $app */

/* ---------------------------------------------------------------------------
 | 1. Input
 */
$stSettings = isset($settings) && is_array($settings) ? $settings : [];
$stWebhook = isset($webhook) && is_array($webhook) ? $webhook : [];
$stMe = isset($me) && is_array($me) ? $me : [];
$stDb = isset($dbInfo) && is_array($dbInfo) ? $dbInfo : [];
$stAdminIds = isset($adminIds) && is_array($adminIds) ? $adminIds : [];
$stBotAdminIds = isset($botAdminIds) && is_array($botAdminIds) ? $botAdminIds : [];
$stLocales = isset($locales) && is_array($locales) ? $locales : [];

/*
 * The switches always mirror what is stored: a rejected save (an unusable
 * channel name) persists nothing, so the toggles did not change either. The two
 * free-text fields do come back through old(), because those are what the
 * operator has to correct.
 */
$stRegistrationOpen = (bool) ($stSettings['registration_open'] ?? true);
$stAskLanguage = (bool) ($stSettings['ask_language'] ?? true);

$stChannel = old('required_channel', (string) ($stSettings['required_channel'] ?? ''));
$stChannel = is_string($stChannel) ? $stChannel : '';

$stWelcome = old('welcome_extra', (string) ($stSettings['welcome_extra'] ?? ''));
$stWelcome = is_string($stWelcome) ? $stWelcome : '';

// The form has been re-rendered — drop the stash so a later visit starts clean.
if (function_exists('forget_old')) {
    forget_old();
}

/** Thousands separated integer. */
$stNumber = static function (mixed $value): string {
    return number_format((float) (is_numeric($value) ? $value : 0), 0, ',', ' ');
};

/** A value, or the "Mavjud emas" placeholder when it is empty. */
$stValue = static function (mixed $value): string {
    $text = is_scalar($value) ? trim((string) $value) : '';

    return $text === '' ? t('panel.not_available') : $text;
};

/** A unix timestamp as `Y-m-d H:i`, or the placeholder. */
$stTime = static function (mixed $stamp): string {
    $stamp = is_numeric($stamp) ? (int) $stamp : 0;

    return $stamp > 0 ? date('Y-m-d H:i', $stamp) : t('panel.not_available');
};

/* ---------------------------------------------------------------------------
 | 2. Bot and webhook facts
 */
$stMeOk = (bool) ($stMe['ok'] ?? false);
$stMeResult = is_array($stMe['result'] ?? null) ? $stMe['result'] : [];
$stMeUsername = trim((string) ($stMe['username'] ?? ''));
$stMeError = trim((string) ($stMe['error'] ?? ''));

$stHookOk = (bool) ($stWebhook['ok'] ?? false);
$stHookInfo = is_array($stWebhook['info'] ?? null) ? $stWebhook['info'] : [];
$stHookUrl = trim((string) ($stWebhook['url'] ?? ''));
$stHookError = trim((string) ($stWebhook['error'] ?? ''));
$stHookSuggested = trim((string) ($stWebhook['suggested'] ?? ''));
$stHookSecret = (bool) ($stWebhook['secret'] ?? false);

// What the "re-set webhook" field starts with: the live URL, else the one
// derived from app.base_url.
$stHookField = $stHookUrl !== '' ? $stHookUrl : $stHookSuggested;

$stLastError = trim((string) ($stHookInfo['last_error_message'] ?? ''));

/* ---------------------------------------------------------------------------
 | 3. Table row counts
 |
 | The controller only reports driver and size, so the per-table numbers are
 | gathered here from a fixed whitelist — never from user input — and any
 | database hiccup simply leaves the block empty instead of breaking the page.
 */
$stTables = [];

try {
    $stDatabase = $app->db();

    foreach ([
        'users',
        'registrations',
        'settings',
        'broadcasts',
        'broadcast_targets',
        'rate_limits',
        'audit_log',
        'login_attempts',
        'migrations',
    ] as $stTableName) {
        if (!$stDatabase->tableExists($stTableName)) {
            continue;
        }

        $stTables[] = [
            'name'  => $stDatabase->table($stTableName),
            'count' => $stDatabase->count($stTableName),
        ];
    }
} catch (\Throwable $stError) {
    $stTables = [];
}

/** Days offered by the audit purge selector. */
$stPurgeDays = [30, 90, 180, 365];

?>
<div class="stack">

    <?php /* ---- 1. Runtime settings --------------------------------------- */ ?>
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= e(t('panel.set_registration_open')) ?></h2>
            <p class="card__hint"><?= e(t('panel.settings_subtitle')) ?></p>
        </div>
        <form method="post" action="<?= e(url(['p' => 'settings', 'a' => 'save'])) ?>">
            <?= Csrf::field() ?>
            <div class="card__body stack">
                <div class="field">
                    <label class="switch" for="setting-registration-open">
                        <input type="checkbox" id="setting-registration-open"
                               name="registration_open" value="1"
                            <?= $stRegistrationOpen ? 'checked' : '' ?>>
                        <span class="switch__text"><?= e(t('common.enabled')) ?></span>
                    </label>
                    <p class="field__hint"><?= e(t('panel.set_registration_open_hint')) ?></p>
                </div>

                <div class="field">
                    <label class="field__label" for="setting-required-channel">
                        <?= e(t('panel.set_required_channel')) ?>
                    </label>
                    <input class="input" type="text" id="setting-required-channel"
                           name="required_channel" value="<?= e($stChannel) ?>"
                           maxlength="64" autocomplete="off" spellcheck="false"
                           placeholder="@andijon_ai_talents">
                    <p class="field__hint"><?= e(t('panel.set_required_channel_hint')) ?></p>
                </div>

                <div class="field">
                    <label class="switch" for="setting-ask-language">
                        <input type="checkbox" id="setting-ask-language"
                               name="ask_language" value="1"
                            <?= $stAskLanguage ? 'checked' : '' ?>>
                        <span class="switch__text"><?= e(t('panel.set_ask_language')) ?></span>
                    </label>
                    <p class="field__hint"><?= e(t('panel.set_ask_language_hint')) ?></p>
                </div>

                <div class="field">
                    <label class="field__label" for="setting-welcome-extra">
                        <?= e(t('panel.set_welcome_extra')) ?>
                    </label>
                    <textarea class="textarea" id="setting-welcome-extra" name="welcome_extra"
                              rows="4" maxlength="1000"><?= e($stWelcome) ?></textarea>
                    <p class="field__hint"><?= e(t('panel.set_welcome_extra_hint')) ?></p>
                </div>

                <div class="form-actions">
                    <button class="btn btn--primary" type="submit"><?= e(t('common.save')) ?></button>
                    <span class="meta"><?= e(t('common.language')) ?>:
                        <?php foreach ($stLocales as $stLocaleOption) { ?>
                            <?php if (is_array($stLocaleOption)) { ?>
                                <span class="chip chip--plain">
                                    <?= e((string) ($stLocaleOption['label'] ?? $stLocaleOption['value'] ?? '')) ?>
                                </span>
                            <?php } ?>
                        <?php } ?>
                    </span>
                </div>
            </div>
        </form>
    </section>

    <?php /* ---- 2. Bot health and webhook ---------------------------------- */ ?>
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= e(t('panel.set_bot')) ?></h2>
            <div class="card__actions">
                <?php if ($stMeOk) { ?>
                    <span class="badge badge--ok">
                        <?= e(t('panel.set_getme_ok', ['username' => $stMeUsername])) ?>
                    </span>
                <?php } else { ?>
                    <span class="badge badge--danger">
                        <?= e(t('panel.set_getme_failed', [
                            'error' => $stMeError === '' ? t('error.telegram') : $stMeError,
                        ])) ?>
                    </span>
                <?php } ?>
                <form method="post" action="<?= e(url(['p' => 'settings', 'a' => 'test_bot'])) ?>">
                    <?= Csrf::field() ?>
                    <button class="btn btn--ghost btn--sm" type="submit">
                        <?= e(t('panel.set_getme')) ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="card__body stack">
            <dl class="meta">
                <div class="meta__row">
                    <dt><?= e(t('panel.th_telegram_id')) ?></dt>
                    <dd><?= e($stValue($stMeResult['id'] ?? null)) ?></dd>
                </div>
                <div class="meta__row">
                    <dt><?= e(t('common.name')) ?></dt>
                    <dd><?= e($stValue($stMeResult['first_name'] ?? null)) ?></dd>
                </div>
                <div class="meta__row">
                    <dt><?= e(t('panel.th_username')) ?></dt>
                    <dd>
                        <?php if ($stMeUsername !== '') { ?>
                            <a class="page-link" href="https://t.me/<?= e(rawurlencode($stMeUsername)) ?>"
                               target="_blank" rel="noopener noreferrer">@<?= e($stMeUsername) ?></a>
                        <?php } else { ?>
                            <span class="muted"><?= e(t('panel.not_available')) ?></span>
                        <?php } ?>
                    </dd>
                </div>
            </dl>

            <h3><?= e(t('panel.set_webhook_info')) ?></h3>

            <?php if (!$stHookOk && $stHookError !== '') { ?>
                <p class="field__error"><?= e($stHookError) ?></p>
            <?php } ?>

            <div class="table-wrap">
                <table class="table">
                    <tbody>
                    <tr>
                        <th scope="row"><?= e(t('panel.set_webhook')) ?></th>
                        <td>
                            <?php if ($stHookUrl !== '') { ?>
                                <span class="truncate"><?= e($stHookUrl) ?></span>
                            <?php } else { ?>
                                <span class="muted"><?= e(t('panel.not_available')) ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?= e(t('panel.th_ip')) ?></th>
                        <td><?= e($stValue($stHookInfo['ip_address'] ?? null)) ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?= e(t('common.count')) ?></th>
                        <td class="tabular"><?= e($stNumber($stHookInfo['pending_update_count'] ?? 0)) ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?= e(t('common.error')) ?></th>
                        <td>
                            <?php if ($stLastError !== '') { ?>
                                <span class="badge badge--danger"><?= e(Text::truncate($stLastError, 160)) ?></span>
                            <?php } else { ?>
                                <span class="muted"><?= e(t('common.no_data')) ?></span>
                            <?php } ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?= e(t('common.time')) ?></th>
                        <td class="nowrap"><?= e($stTime($stHookInfo['last_error_date'] ?? null)) ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?= e(t('common.status')) ?></th>
                        <td>
                            <span class="badge badge--<?= $stHookSecret ? 'ok' : 'muted' ?>">
                                <?= e($stHookSecret ? t('common.enabled') : t('common.disabled')) ?>
                            </span>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <form method="post" action="<?= e(url(['p' => 'settings', 'a' => 'webhook_set'])) ?>">
                <?= Csrf::field() ?>
                <div class="field">
                    <label class="field__label" for="setting-webhook-url">
                        <?= e(t('panel.set_webhook')) ?>
                    </label>
                    <input class="input" type="url" id="setting-webhook-url" name="url"
                           value="<?= e($stHookField) ?>" maxlength="500"
                           autocomplete="off" spellcheck="false"
                           placeholder="https://example.com/bot/index.php">
                    <p class="field__hint"><?= e(t('panel.set_webhook_reset')) ?></p>
                </div>
                <label class="checkbox" for="setting-webhook-drop">
                    <input type="checkbox" id="setting-webhook-drop" name="drop_pending" value="1">
                    <span><?= e(t('common.reset')) ?></span>
                </label>
                <div class="form-actions">
                    <button class="btn btn--primary" type="submit">
                        <?= e(t('panel.set_webhook_reset')) ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="card__foot">
            <form method="post" action="<?= e(url(['p' => 'settings', 'a' => 'webhook_delete'])) ?>"
                  data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                <?= Csrf::field() ?>
                <button class="btn btn--danger btn--sm" type="submit">
                    <?= e(t('panel.set_webhook') . ' — ' . t('common.delete')) ?>
                </button>
            </form>
            <?php if ($stHookSuggested !== '') { ?>
                <span class="meta"><?= e(t('panel.set_webhook')) ?>: <?= e($stHookSuggested) ?></span>
            <?php } ?>
        </div>
    </section>

    <section class="grid-2">
        <?php /* ---- 3. Database ------------------------------------------- */ ?>
        <article class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.set_db')) ?></h2>
                <div class="card__actions">
                    <span class="chip"><?= e($stValue($stDb['driver'] ?? null)) ?></span>
                    <span class="chip"><?= e(t('panel.version', [
                        'version' => (string) ($stDb['version'] ?? ''),
                    ])) ?></span>
                </div>
            </div>
            <div class="card__body stack">
                <dl class="meta">
                    <div class="meta__row">
                        <dt><?= e(t('panel.set_db_driver')) ?></dt>
                        <dd><?= e($stValue($stDb['driver'] ?? null)) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('common.name')) ?></dt>
                        <dd><?= e($stValue($stDb['path'] ?? ($stDb['database'] ?? null))) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.set_db_size')) ?></dt>
                        <dd class="tabular"><?= e($stValue($stDb['size_human'] ?? null)) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.set_php_version')) ?></dt>
                        <dd><?= e($stValue($stDb['php'] ?? null)) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('common.time')) ?></dt>
                        <dd><?= e($stValue($stDb['timezone'] ?? null)) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.logs_title')) ?></dt>
                        <dd>
                            <?= e($stValue($stDb['log_level'] ?? null)) ?>
                            · <?= e($stNumber($stDb['log_files'] ?? 0)) ?>
                        </dd>
                    </div>
                </dl>

                <?php if ($stTables !== []) { ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col"><?= e(t('common.name')) ?></th>
                                <th scope="col" class="num"><?= e(t('common.count')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stTables as $stTable) { ?>
                                <tr>
                                    <td><code><?= e((string) $stTable['name']) ?></code></td>
                                    <td class="num tabular"><?= e($stNumber($stTable['count'])) ?></td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } else { ?>
                    <p class="muted"><?= e(t('common.no_data')) ?></p>
                <?php } ?>
            </div>
        </article>

        <?php /* ---- 4. Administrators --------------------------------------- */ ?>
        <article class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.set_admin_ids')) ?></h2>
                <div class="card__actions">
                    <span class="chip"><?= e($stNumber(count($stAdminIds))) ?></span>
                </div>
                <p class="card__hint"><?= e(t('panel.set_admin_ids_hint')) ?></p>
            </div>
            <div class="card__body stack">
                <?php if ($stAdminIds === []) { ?>
                    <div class="empty">
                        <p class="empty__title"><?= e(t('common.no_data')) ?></p>
                        <p class="empty__text"><?= e(t('panel.set_admin_ids_hint')) ?></p>
                    </div>
                <?php } else { ?>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col"><?= e(t('panel.th_telegram_id')) ?></th>
                                <th scope="col" class="shrink"><?= e(t('panel.th_actions')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($stAdminIds as $stAdminId) { ?>
                                <?php
                                if (!is_int($stAdminId) && !is_string($stAdminId)) {
                                    continue;
                                }

                                $stAdminIdText = (string) $stAdminId;
                                ?>
                                <tr>
                                    <td class="tabular"><?= e($stAdminIdText) ?></td>
                                    <td class="shrink">
                                        <div class="table__actions">
                                            <a class="btn btn--ghost btn--sm"
                                               href="tg://user?id=<?= e(rawurlencode($stAdminIdText)) ?>">
                                                <?= e(t('panel.user_open_chat')) ?>
                                            </a>
                                            <button class="btn btn--ghost btn--sm" type="button"
                                                    data-copy="<?= e($stAdminIdText) ?>">
                                                <?= e(t('common.copy')) ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>

                <p class="field__hint">
                    <?= e(t('panel.th_admin')) ?>:
                    <?= e($stNumber(count($stBotAdminIds))) ?>
                </p>
            </div>
        </article>
    </section>

    <?php /* ---- 5. Maintenance --------------------------------------------- */ ?>
    <section class="card">
        <div class="card__head">
            <h2 class="card__title"><?= e(t('common.actions')) ?></h2>
        </div>
        <div class="card__body">
            <div class="grid-2">
                <form method="post" action="<?= e(url(['p' => 'settings', 'a' => 'clear_logs'])) ?>"
                      data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                    <?= Csrf::field() ?>
                    <div class="field">
                        <span class="field__label"><?= e(t('panel.set_clear_logs')) ?></span>
                        <p class="field__hint">
                            <?= e($stValue($stDb['log_dir'] ?? null)) ?>
                            · <?= e($stNumber($stDb['log_files'] ?? 0)) ?>
                        </p>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn--danger btn--sm" type="submit">
                            <?= e(t('panel.set_clear_logs')) ?>
                        </button>
                        <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'logs'])) ?>">
                            <?= e(t('panel.nav_logs')) ?>
                        </a>
                    </div>
                </form>

                <form method="post" action="<?= e(url(['p' => 'audit', 'a' => 'purge'])) ?>"
                      data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                    <?= Csrf::field() ?>
                    <div class="field">
                        <label class="field__label" for="setting-purge-days">
                            <?= e(t('panel.audit_purge')) ?>
                        </label>
                        <select class="select" id="setting-purge-days" name="days">
                            <?php foreach ($stPurgeDays as $stDays) { ?>
                                <option value="<?= e((string) $stDays) ?>" <?= $stDays === 90 ? 'selected' : '' ?>>
                                    <?= e($stNumber($stDays) . ' ' . t('common.day')) ?>
                                </option>
                            <?php } ?>
                        </select>
                        <p class="field__hint"><?= e(t('panel.audit_subtitle')) ?></p>
                    </div>
                    <div class="form-actions">
                        <button class="btn btn--danger btn--sm" type="submit">
                            <?= e(t('panel.audit_purge')) ?>
                        </button>
                        <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'audit'])) ?>">
                            <?= e(t('panel.nav_audit')) ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
<?php
unset(
    $stSettings, $stWebhook, $stMe, $stDb, $stAdminIds, $stBotAdminIds, $stLocales,
    $stRegistrationOpen, $stAskLanguage, $stChannel, $stWelcome,
    $stNumber, $stValue, $stTime,
    $stMeOk, $stMeResult, $stMeUsername, $stMeError,
    $stHookOk, $stHookInfo, $stHookUrl, $stHookError, $stHookSuggested, $stHookSecret,
    $stHookField, $stLastError,
    $stTables, $stTable, $stTableName, $stDatabase, $stError,
    $stPurgeDays, $stDays, $stAdminId, $stAdminIdText, $stLocaleOption
);
?>
