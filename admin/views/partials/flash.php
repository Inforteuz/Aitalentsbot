<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — flash messages.
 *
 * Renders whatever a controller queued with the global `flash()` helper before
 * redirecting. Reading the bag empties it, so this partial must be included
 * exactly once per page — the layout does that right above the content.
 *
 * Each message becomes a `.toast` element carrying `data-toast`; assets/app.js
 * picks them up to fade them out after `data-toast-timeout` milliseconds and to
 * wire the close button. Without JavaScript the toasts simply stay on screen,
 * which is still perfectly usable.
 *
 * Variables (all optional):
 *   $messages — an explicit list of ['type' => string, 'message' => string]
 *               pairs; when omitted the session flash bag is consumed.
 */

$flashMessages = [];

if (isset($messages) && is_array($messages)) {
    $flashMessages = $messages;
} elseif (function_exists('flash')) {
    $flashMessages = flash();
}

// Normalise: drop anything that is not a printable message.
$flashItems = [];

foreach ($flashMessages as $flashEntry) {
    if (!is_array($flashEntry)) {
        continue;
    }

    $flashText = trim((string) ($flashEntry['message'] ?? ''));

    if ($flashText === '') {
        continue;
    }

    $flashType = (string) ($flashEntry['type'] ?? 'info');
    $flashType = function_exists('panel_flash_type') ? panel_flash_type($flashType) : $flashType;

    if (!in_array($flashType, ['success', 'error', 'warning', 'info'], true)) {
        $flashType = 'info';
    }

    $flashItems[] = ['type' => $flashType, 'message' => $flashText];
}

if ($flashItems === []) {
    return;
}

/** Small status glyph per message type — decorative, hidden from screen readers. */
$flashIcon = static function (string $type): string {
    $body = match ($type) {
        'success' => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.2 2.4 2.4 4.6-5"/>',
        'error'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5"/><path d="M12 16.3v.2"/>',
        'warning' => '<path d="M12 4.5 21 19.5H3z"/><path d="M12 10v4"/><path d="M12 17v.2"/>',
        default   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5"/><path d="M12 7.7v.2"/>',
    };

    return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"'
        . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
        . ' aria-hidden="true" focusable="false">' . $body . '</svg>';
};

?>
<div class="toasts" data-toast-stack role="status" aria-live="polite" aria-atomic="false">
    <?php foreach ($flashItems as $flashItem) { ?>
        <div class="toast toast--<?= e($flashItem['type']) ?>"
             data-toast data-type="<?= e($flashItem['type']) ?>" data-timeout="7000">
            <?= $flashIcon($flashItem['type']) ?>
            <span class="toast__text"><?= e($flashItem['message']) ?></span>
            <button class="toast__close" type="button" data-dismiss="toast"
                    aria-label="<?= e(t('common.close')) ?>" title="<?= e(t('common.close')) ?>">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                    <path d="m6 6 12 12M18 6 6 18"/>
                </svg>
            </button>
        </div>
    <?php } ?>
</div>
<?php unset($flashMessages, $flashItems, $flashItem, $flashIcon, $flashEntry, $flashText, $flashType); ?>
