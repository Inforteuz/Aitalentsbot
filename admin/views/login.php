<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — admin panel login.
 *
 * Rendered by AuthController inside a bare, navigation-free shell: the layout
 * recognises this view and drops the sidebar and the top bar, so the visitor
 * sees nothing but the sign-in card until the session exists.
 *
 * The screen is deliberately vague about failures — "login yoki parol
 * noto‘g‘ri" never reveals which of the two was wrong — and it shows the
 * remaining lockout time once {@see \AiTalents\Admin\Auth} starts throttling
 * the source IP.
 *
 * Variables from AuthController:
 *   $title, $subtitle, $error, $configured, $enabled, $locked,
 *   $lockoutSeconds, $lockoutMinutes, $username
 */

use AiTalents\Admin\Csrf;

/** @var \AiTalents\App $app */

$lgError = isset($error) && is_string($error) ? trim($error) : '';
$lgUsername = isset($username) && is_string($username) ? $username : '';
$lgLocked = isset($locked) && (bool) $locked;
$lgConfigured = !isset($configured) || (bool) $configured;
$lgMinutes = isset($lockoutMinutes) && is_numeric($lockoutMinutes) ? max(0, (int) $lockoutMinutes) : 0;
$lgSubtitle = isset($subtitle) && is_string($subtitle) && $subtitle !== ''
    ? $subtitle
    : t('panel.login_subtitle');

$lgProject = (string) $app->config('app.name', 'Andijon AI Talents');

if (trim($lgProject) === '') {
    $lgProject = t('panel.brand');
}

// A lockout of less than a minute still has to read as "wait a minute".
if ($lgLocked && $lgMinutes < 1) {
    $lgMinutes = 1;
}

?>
<section class="login__card">
    <header class="meta">
        <span class="avatar" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 aria-hidden="true" focusable="false">
                <path d="M5 19 10 5h2l5 14"/><path d="M7.2 14.5h7.6"/><path d="M20 5v14"/>
            </svg>
        </span>
        <h1><?= e($lgProject) ?></h1>
        <p class="meta__row"><?= e(t('panel.login_title')) ?></p>
    </header>

    <div>
        <p class="meta"><?= e($lgSubtitle) ?></p>

        <?php if (!$lgConfigured) { ?>
            <div class="login__error login__error--warning" role="alert">
                <span><?= e(t('panel.login_disabled')) ?></span>
            </div>
        <?php } ?>

        <?php if ($lgLocked) { ?>
            <div class="login__error login__error--warning" role="alert">
                <span><?= e(t('panel.login_locked', ['minutes' => $lgMinutes])) ?></span>
            </div>
        <?php } ?>

        <?php if ($lgError !== '') { ?>
            <div class="login__error" role="alert">
                <span><?= e($lgError) ?></span>
            </div>
        <?php } ?>

        <form method="post" action="<?= e(url(['p' => 'login'])) ?>" autocomplete="on">
            <?= Csrf::field() ?>

            <div class="field">
                <label class="field__label" for="login-username"><?= e(t('panel.login_username')) ?></label>
                <input class="input" type="text" id="login-username" name="username"
                       value="<?= e($lgUsername) ?>" autocomplete="username"
                       spellcheck="false" autocapitalize="none" required
                       maxlength="64" <?= $lgUsername === '' ? 'autofocus' : '' ?>>
            </div>

            <div class="field">
                <label class="field__label" for="login-password"><?= e(t('panel.login_password')) ?></label>
                <input class="input" type="password" id="login-password" name="password"
                       autocomplete="current-password" required maxlength="255"
                       <?= $lgUsername !== '' ? 'autofocus' : '' ?>>
            </div>

            <button class="btn btn--primary" type="submit" <?= $lgLocked ? 'disabled' : '' ?>>
                <?= e(t('panel.login_submit')) ?>
            </button>
        </form>
    </div>

    <footer class="meta">
        <div class="meta__row"><?= e(t('panel.login_footer')) ?></div>
    </footer>
</section>
<?php unset($lgError, $lgUsername, $lgLocked, $lgConfigured, $lgMinutes, $lgSubtitle, $lgProject); ?>
