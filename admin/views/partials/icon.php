<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — one inline SVG icon.
 *
 * The panel carries no icon font, no sprite file and no emoji. An emoji is
 * drawn by the operating system, so the same status badge would look different
 * on every machine in the hokimlik — and a flag emoji is not drawn at all on
 * Windows, where it falls back to the bare letters of the country code. Every
 * glyph is therefore hand written inline SVG, exactly like the sidebar icons in
 * partials/nav.php, and stroked with `currentColor` so it inherits the colour
 * of whatever carries it (a badge, a button, a table cell) in both themes.
 *
 * From a template:
 *
 *     $view->partial('icon', ['icon' => 'clock']);
 *     $view->partial('icon', ['icon' => 'check', 'icon_size' => 18]);
 *     $view->partial('icon', ['icon' => 'x', 'icon_class' => 'badge__icon']);
 *
 * The icon is decorative — a readable label always sits beside it — so it is
 * hidden from assistive technology and kept out of the tab order. An unknown
 * name degrades to a neutral dot instead of printing nothing.
 *
 * Variables: $icon (name), $icon_size (pixels, optional), $icon_class (optional).
 */

/** Icon name => the body of a 24×24 stroke drawing. */
$iconPaths = [
    // Moderation states, used by the status badges.
    'check' => '<path d="M4.5 12.5 9 17l10.5-10.5"/>',
    'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.2V12l3.2 1.9"/>',
    'x'     => '<path d="m6 6 12 12M18 6 6 18"/>',

    // Neutral fallback.
    'dot'   => '<circle cx="12" cy="12" r="3.4"/>',
];

$iconName = isset($icon) && is_string($icon) && isset($iconPaths[$icon]) ? $icon : 'dot';

// Clamped so a stray value cannot blow a table row apart.
$iconSize = isset($icon_size) && is_numeric($icon_size) ? (int) $icon_size : 16;
$iconSize = max(8, min(64, $iconSize));

$iconClass = isset($icon_class) && is_string($icon_class) ? trim($icon_class) : '';

?>
<svg <?= $iconClass === '' ? '' : 'class="' . e($iconClass) . '" ' ?>viewBox="0 0 24 24"
     width="<?= e((string) $iconSize) ?>" height="<?= e((string) $iconSize) ?>"
     fill="none" stroke="currentColor" stroke-width="1.8"
     stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true" focusable="false"><?= $iconPaths[$iconName] ?></svg>
<?php unset($iconPaths, $iconName, $iconSize, $iconClass); ?>
