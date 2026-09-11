<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — one application.
 *
 * Two columns: the submitted answers on the left, everything the operator can
 * *do* about them on the right (who the applicant is, the status form with its
 * internal note, a message box that goes out through the bot, the delete
 * button and the audit trail of this very record).
 *
 * All three moderation endpoints of RegistrationController are reachable from
 * the status form: the primary button saves status + note through `a=note`,
 * while the two shortcut buttons post the same fields to `a=approve` /
 * `a=reject` through `formaction`, so one click both moderates and notifies.
 *
 * Variables handed over by RegistrationController::view():
 *   $title, $subtitle, $reg, $user, $audit, $status, $statuses,
 *   $directionLabels, $districtLabel, $links, $query, $locale
 */

use AiTalents\Admin\Csrf;
use AiTalents\Lang;
use AiTalents\Text;

/** @var \AiTalents\Admin\View $view */

$rvReg        = isset($reg) && is_array($reg) ? $reg : [];
$rvUser       = isset($user) && is_array($user) ? $user : null;
$rvAudit      = isset($audit) && is_array($audit) ? $audit : [];
$rvStatuses   = isset($statuses) && is_array($statuses) ? $statuses : [];
$rvStatus     = isset($status) && is_array($status) ? $status : [];
$rvDirections = isset($directionLabels) && is_array($directionLabels) ? $directionLabels : [];
$rvLinks      = isset($links) && is_array($links) ? $links : [];
$rvQuery      = isset($query) && is_array($query) ? $query : ['p' => 'registration'];
$rvLocale     = isset($locale) && is_string($locale) && $locale !== '' ? $locale : panel_locale();
$rvDistrict   = isset($districtLabel) && is_string($districtLabel) ? trim($districtLabel) : '';

$rvId         = (int) ($rvReg['id'] ?? 0);
$rvTelegramId = (int) ($rvReg['telegram_id'] ?? 0);
$rvName       = trim((string) ($rvReg['full_name'] ?? ''));
$rvPhone      = trim((string) ($rvReg['phone'] ?? ''));
$rvBirthYear  = $rvReg['birth_year'] ?? null;
$rvPortfolio  = trim((string) ($rvReg['portfolio'] ?? ''));
$rvNote       = (string) ($rvReg['admin_note'] ?? '');
$rvStatusKey  = trim((string) ($rvReg['status'] ?? ''));
$rvUsername   = trim((string) ($rvReg['username'] ?? ($rvUser['username'] ?? '')));

// The applicant's interface language: the registration row carries the joined
// users.locale, the user row is the fallback for a deleted/renamed account.
$rvUserLocale = Lang::normalize((string) ($rvReg['user_locale'] ?? ($rvUser['locale'] ?? '')));
$rvBlocked    = (int) ($rvReg['user_is_blocked'] ?? ($rvUser['is_blocked'] ?? 0)) === 1;

/* -------------------------------------------------------------------------
 | Helpers
 */

/** A value, or the "not provided" placeholder when it is empty. */
$rvValue = static function (mixed $value): string {
    if ($value === null) {
        return t('profile.empty_value');
    }

    $text = trim((string) (is_scalar($value) ? $value : ''));

    return $text === '' ? t('profile.empty_value') : $text;
};

/** A timestamp trimmed to minutes; never prints a raw "0000-00-00". */
$rvMoment = static function (mixed $value): string {
    $text = trim((string) (is_scalar($value) ? $value : ''));

    if ($text === '' || str_starts_with($text, '0000')) {
        return t('common.never');
    }

    return substr($text, 0, 16);
};

/** One scalar of an audit meta payload, rendered for humans. */
$rvScalar = static function (mixed $value): string {
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
 * The decoded `meta` column of an audit row as a compact "key: value" list.
 *
 * @return array<int,array{key:string,value:string}>
 */
$rvMeta = static function (mixed $meta) use ($rvScalar): array {
    if (!is_array($meta) || $meta === []) {
        return [];
    }

    $pairs = [];

    foreach ($meta as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        $pairs[] = ['key' => $key, 'value' => Text::truncate($rvScalar($value), 120)];
    }

    return $pairs;
};

/* -------------------------------------------------------------------------
 | URLs
 */

/** POST target of one action on this application. */
$rvAction = static function (string $action) use ($rvQuery, $rvId): string {
    return url(array_merge($rvQuery, ['p' => 'registration', 'id' => $rvId, 'a' => $action]));
};

// Back to the list, keeping whatever filters brought the operator here.
$rvBackQuery = $rvQuery;
unset($rvBackQuery['id'], $rvBackQuery['a']);
$rvBackQuery['p'] = 'registrations';

$rvChatUrl = $rvUsername !== '' ? 'https://t.me/' . rawurlencode($rvUsername) : '';
$rvDeepLink = $rvTelegramId !== 0 ? 'tg://user?id=' . $rvTelegramId : '';

?>
<div class="btn-row mb-4">
    <a class="btn btn--ghost btn--sm" href="<?= e(url($rvBackQuery)) ?>">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
             aria-hidden="true" focusable="false">
            <path d="M14.5 6 9 12l5.5 6"/>
        </svg>
        <?= e(t('panel.back_to_registrations')) ?>
    </a>
    <?php if ($rvStatus !== []) { ?>
        <span class="badge badge--<?= e((string) ($rvStatus['badge'] ?? 'warn')) ?>">
            <?php $view->partial('icon', ['icon' => (string) ($rvStatus['icon'] ?? 'dot')]); ?>
            <?= e((string) ($rvStatus['label'] ?? $rvStatusKey)) ?>
        </span>
    <?php } ?>
</div>

<div class="grid-aside">
    <div class="stack">
        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.detail_answers')) ?></h2>
                <span class="chip">#<?= e((string) $rvId) ?></span>
            </div>
            <div class="card__body">
                <dl class="meta">
                    <div class="meta__row">
                        <dt><?= e(t('panel.th_name')) ?></dt>
                        <dd class="strong"><?= e($rvValue($rvName)) ?></dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_phone')) ?></dt>
                        <dd>
                            <?php if ($rvPhone !== '') { ?>
                                <a class="page-link" href="tel:<?= e($rvPhone) ?>"
                                   title="<?= e(t('panel.detail_call')) ?>">
                                    <?= e(Text::phoneDisplay($rvPhone)) ?>
                                </a>
                                <button class="btn btn--ghost btn--sm" type="button"
                                        data-copy="<?= e($rvPhone) ?>"
                                        title="<?= e(t('common.copy')) ?>"
                                        aria-label="<?= e(t('common.copy')) ?>">
                                    <?= e(t('common.copy')) ?>
                                </button>
                            <?php } else { ?>
                                <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                            <?php } ?>
                        </dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_birth_year')) ?></dt>
                        <dd class="tabular"><?= e($rvValue($rvBirthYear)) ?></dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_district')) ?></dt>
                        <dd><?= e($rvValue($rvDistrict)) ?></dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_direction')) ?></dt>
                        <dd>
                            <?php if ($rvDirections === []) { ?>
                                <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                            <?php } else { ?>
                                <?php foreach ($rvDirections as $rvDirection) { ?>
                                    <span class="chip"><?= e((string) $rvDirection) ?></span>
                                <?php } ?>
                            <?php } ?>
                        </dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_portfolio')) ?></dt>
                        <dd>
                            <?php if ($rvPortfolio === '') { ?>
                                <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                            <?php } else { ?>
                                <?= nl2br(e($rvPortfolio)) ?>
                            <?php } ?>
                        </dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.detail_links')) ?></dt>
                        <dd>
                            <?php if ($rvLinks === []) { ?>
                                <span class="muted"><?= e(t('panel.detail_no_links')) ?></span>
                            <?php } else { ?>
                                <div class="link-list">
                                    <?php foreach ($rvLinks as $rvLink) { ?>
                                        <?php
                                        // The controller already filtered these
                                        // down to absolute http(s) URLs.
                                        $rvHref = (string) $rvLink;
                                        ?>
                                        <a href="<?= e($rvHref) ?>" target="_blank"
                                           rel="noopener noreferrer nofollow ugc">
                                            <?= e(Text::truncate($rvHref, 80)) ?>
                                        </a>
                                    <?php } ?>
                                </div>
                            <?php } ?>
                        </dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_source')) ?></dt>
                        <dd><?= e($rvValue($rvReg['source'] ?? '')) ?></dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_created')) ?></dt>
                        <dd class="tabular"><?= e($rvMoment($rvReg['created_at'] ?? '')) ?></dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_updated')) ?></dt>
                        <dd class="tabular"><?= e($rvMoment($rvReg['updated_at'] ?? '')) ?></dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_reviewed')) ?></dt>
                        <dd class="tabular">
                            <?= e($rvMoment($rvReg['reviewed_at'] ?? '')) ?>
                            <?php if (($rvReg['reviewed_by'] ?? null) !== null) { ?>
                                <span class="cell-sub">
                                    <?= e(t('admin.review_by', ['actor' => (string) $rvReg['reviewed_by']])) ?>
                                </span>
                            <?php } ?>
                        </dd>
                    </div>

                    <div class="meta__row">
                        <dt><?= e(t('panel.th_note')) ?></dt>
                        <dd>
                            <?php if (trim($rvNote) === '') { ?>
                                <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                            <?php } else { ?>
                                <?= nl2br(e($rvNote)) ?>
                            <?php } ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </section>
    </div>

    <div class="stack">
        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.detail_applicant')) ?></h2>
            </div>
            <div class="card__body">
                <div class="user-cell mb-4">
                    <span class="avatar avatar--lg" aria-hidden="true"><?= e(Text::initials($rvName)) ?></span>
                    <span>
                        <span class="cell-main"><?= e($rvValue($rvName)) ?></span>
                        <span class="cell-sub">
                            <?= $rvUsername !== '' ? '@' . e($rvUsername) : e((string) $rvTelegramId) ?>
                        </span>
                    </span>
                </div>

                <div class="btn-row mb-4">
                    <?php if ($rvChatUrl !== '') { ?>
                        <a class="btn btn--ghost btn--sm" href="<?= e($rvChatUrl) ?>"
                           target="_blank" rel="noopener noreferrer">
                            <?= e(t('panel.detail_open_chat')) ?>
                        </a>
                    <?php } ?>
                    <?php if ($rvDeepLink !== '') { ?>
                        <a class="btn btn--ghost btn--sm" href="<?= e($rvDeepLink) ?>">
                            <?= e(t('panel.user_open_chat')) ?>
                        </a>
                    <?php } ?>
                </div>

                <dl class="meta">
                    <div class="meta__row">
                        <dt><?= e(t('panel.th_telegram_id')) ?></dt>
                        <dd class="tabular">
                            <?= e((string) $rvTelegramId) ?>
                            <button class="btn btn--ghost btn--sm" type="button"
                                    data-copy="<?= e((string) $rvTelegramId) ?>"
                                    title="<?= e(t('common.copy')) ?>"
                                    aria-label="<?= e(t('common.copy')) ?>">
                                <?= e(t('common.copy')) ?>
                            </button>
                        </dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.th_username')) ?></dt>
                        <dd>
                            <?php if ($rvChatUrl !== '') { ?>
                                <a href="<?= e($rvChatUrl) ?>" target="_blank"
                                   rel="noopener noreferrer">@<?= e($rvUsername) ?></a>
                            <?php } else { ?>
                                <span class="muted"><?= e(t('profile.empty_value')) ?></span>
                            <?php } ?>
                        </dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.th_locale')) ?></dt>
                        <dd><?= e(Lang::name($rvUserLocale)) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.th_blocked')) ?></dt>
                        <dd>
                            <?php if ($rvBlocked) { ?>
                                <span class="badge badge--danger"><?= e(t('common.yes')) ?></span>
                            <?php } else { ?>
                                <span class="badge badge--muted"><?= e(t('common.no')) ?></span>
                            <?php } ?>
                        </dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('common.created_at')) ?></dt>
                        <dd class="tabular"><?= e($rvMoment($rvUser['created_at'] ?? '')) ?></dd>
                    </div>
                    <div class="meta__row">
                        <dt><?= e(t('panel.th_last_seen')) ?></dt>
                        <dd class="tabular"><?= e($rvMoment($rvUser['last_seen_at'] ?? '')) ?></dd>
                    </div>
                </dl>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.detail_status')) ?></h2>
            </div>
            <div class="card__body">
                <form method="post" action="<?= e($rvAction('note')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $rvId) ?>">

                    <div class="field">
                        <label class="field__label" for="reg-status"><?= e(t('panel.th_status')) ?></label>
                        <select class="select" id="reg-status" name="status">
                            <?php foreach ($rvStatuses as $rvOption) { ?>
                                <?php
                                if (!is_array($rvOption)) {
                                    continue;
                                }

                                $rvOptionValue = (string) ($rvOption['value'] ?? '');

                                if ($rvOptionValue === '') {
                                    continue;
                                }
                                ?>
                                <option value="<?= e($rvOptionValue) ?>"
                                    <?= $rvOptionValue === $rvStatusKey ? 'selected' : '' ?>>
                                    <?= e(trim((string) ($rvOption['label'] ?? $rvOptionValue))) ?>
                                </option>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="field">
                        <label class="field__label" for="reg-note"><?= e(t('panel.detail_note')) ?></label>
                        <textarea class="textarea" id="reg-note" name="note" rows="4" maxlength="2000"
                                  placeholder="<?= e(t('panel.detail_note_placeholder')) ?>"><?= e($rvNote) ?></textarea>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn--primary" type="submit">
                            <?= e(t('panel.detail_save_status')) ?>
                        </button>
                        <button class="btn btn--ok btn--sm" type="submit"
                                formaction="<?= e($rvAction('approve')) ?>">
                            <?= e(t('panel.bulk_approve')) ?>
                        </button>
                        <button class="btn btn--sm" type="submit"
                                formaction="<?= e($rvAction('reject')) ?>">
                            <?= e(t('panel.bulk_reject')) ?>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.detail_message_title')) ?></h2>
            </div>
            <div class="card__body">
                <form method="post" action="<?= e($rvAction('message')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $rvId) ?>">

                    <div class="field">
                        <label class="field__label" for="reg-message">
                            <?= e(t('panel.detail_message_title')) ?>
                        </label>
                        <textarea class="textarea" id="reg-message" name="message" rows="4"
                                  maxlength="3800" required
                                  placeholder="<?= e(t('panel.detail_message_placeholder')) ?>"></textarea>
                    </div>

                    <div class="form-actions">
                        <button class="btn btn--primary" type="submit" <?= $rvTelegramId === 0 ? 'disabled' : '' ?>>
                            <?= e(t('panel.detail_message_send')) ?>
                        </button>
                    </div>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.detail_audit')) ?></h2>
                <span class="chip"><?= e((string) count($rvAudit)) ?></span>
            </div>
            <?php if ($rvAudit === []) { ?>
                <div class="card__body">
                    <div class="empty">
                        <p class="empty__text"><?= e(t('panel.audit_empty')) ?></p>
                    </div>
                </div>
            <?php } else { ?>
                <div class="card__body card__body--flush">
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                            <tr>
                                <th scope="col"><?= e(t('panel.th_time')) ?></th>
                                <th scope="col"><?= e(t('panel.th_actor')) ?></th>
                                <th scope="col"><?= e(t('panel.th_action')) ?></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rvAudit as $rvEntry) { ?>
                                <?php
                                if (!is_array($rvEntry)) {
                                    continue;
                                }

                                $rvPairs = $rvMeta($rvEntry['meta'] ?? null);
                                ?>
                                <tr>
                                    <td class="nowrap tabular"><?= e($rvMoment($rvEntry['created_at'] ?? '')) ?></td>
                                    <td>
                                        <span class="cell-main"><?= e((string) ($rvEntry['actor'] ?? '')) ?></span>
                                        <?php if (trim((string) ($rvEntry['ip'] ?? '')) !== '') { ?>
                                            <span class="cell-sub"><?= e((string) $rvEntry['ip']) ?></span>
                                        <?php } ?>
                                    </td>
                                    <td>
                                        <span class="cell-main"><?= e((string) ($rvEntry['action'] ?? '')) ?></span>
                                        <?php if ($rvPairs !== []) { ?>
                                            <span class="cell-sub">
                                                <?php foreach ($rvPairs as $rvPair) { ?>
                                                    <span class="chip chip--plain">
                                                        <?= e($rvPair['key']) ?>: <?= e($rvPair['value']) ?>
                                                    </span>
                                                <?php } ?>
                                            </span>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php } ?>
        </section>

        <section class="card card--accent">
            <div class="card__head">
                <h2 class="card__title"><?= e(t('panel.detail_delete')) ?></h2>
            </div>
            <div class="card__body">
                <form method="post" action="<?= e($rvAction('delete')) ?>"
                      data-confirm="<?= e(t('panel.confirm_delete')) ?>">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= e((string) $rvId) ?>">
                    <p class="card__hint"><?= e(t('panel.confirm_delete')) ?></p>
                    <div class="form-actions">
                        <button class="btn btn--danger" type="submit">
                            <?= e(t('panel.detail_delete')) ?>
                        </button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</div>
<?php
unset(
    $rvReg, $rvUser, $rvAudit, $rvStatuses, $rvStatus, $rvDirections, $rvLinks, $rvQuery,
    $rvLocale, $rvDistrict, $rvId, $rvTelegramId, $rvName, $rvPhone, $rvBirthYear, $rvPortfolio,
    $rvNote, $rvStatusKey, $rvUsername, $rvUserLocale, $rvBlocked, $rvValue, $rvMoment,
    $rvScalar, $rvMeta, $rvAction, $rvBackQuery, $rvChatUrl, $rvDeepLink, $rvDirection,
    $rvLink, $rvHref, $rvOption, $rvOptionValue, $rvEntry, $rvPairs, $rvPair
);
?>
