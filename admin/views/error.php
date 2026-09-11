<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — error page.
 *
 * Used by admin/index.php for an unknown `?p=` (404) and for any exception that
 * escapes a controller (500). The details of the failure stay in the log file:
 * the operator only ever sees the status code and one readable sentence.
 *
 * Variables: $code, $status, $title, $message (see panel_error_page()).
 */

/** @var \AiTalents\Admin\View $view */

$erCode = 500;

if (isset($code) && is_numeric($code)) {
    $erCode = (int) $code;
} elseif (isset($status) && is_numeric($status)) {
    $erCode = (int) $status;
}

if ($erCode < 100 || $erCode > 599) {
    $erCode = 500;
}

$erTitle = isset($title) && is_string($title) && trim($title) !== ''
    ? trim($title)
    : t('common.error');

$erMessage = isset($message) && is_string($message) && trim($message) !== ''
    ? trim($message)
    : t('error.generic');

// A client mistake is a warning, a server failure is an error.
$erBadge = $erCode >= 500 ? 'danger' : ($erCode >= 400 ? 'warn' : 'ok');

?>
<section class="card">
    <div class="card__head">
        <h2><?= e($erTitle) ?></h2>
        <span class="badge badge--<?= e($erBadge) ?>"><?= e((string) $erCode) ?></span>
    </div>
    <div class="card__body">
        <div class="empty">
            <div class="stat__value"><?= e((string) $erCode) ?></div>
            <p><?= e($erMessage) ?></p>
            <p class="meta"><?= e(t('error.try_again')) ?></p>
        </div>

        <div class="filters">
            <a class="btn btn--primary btn--sm" href="<?= e(url(['p' => 'dashboard'])) ?>">
                <?= e(t('panel.nav_dashboard')) ?>
            </a>
            <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'registrations'])) ?>">
                <?= e(t('panel.nav_registrations')) ?>
            </a>
            <a class="btn btn--ghost btn--sm" href="<?= e(url(['p' => 'login'])) ?>">
                <?= e(t('panel.login_title')) ?>
            </a>
        </div>
    </div>
</section>
<?php unset($erCode, $erTitle, $erMessage, $erBadge); ?>
