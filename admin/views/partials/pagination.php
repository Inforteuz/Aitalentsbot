<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — pagination bar.
 *
 * Renders first / previous / numbered / next / last links, collapsing long
 * ranges into an ellipsis so a list with two hundred pages still fits on one
 * line. Every link keeps the current filters: the caller hands over the query
 * array the controller built (`$query`), from which only `page` is replaced.
 *
 * Variables:
 *   $page    int    current page, 1-based (required)
 *   $pages   int    total number of pages (required)
 *   $query   array  base query string, e.g. ['p' => 'registrations', 'status' => 'pending']
 *   $base    array  alias of $query, for callers that already use that name
 *   $total   ?int   total number of rows — enables the ":from–:to / jami :total" summary
 *   $perPage ?int   rows per page; derived from $total/$pages when omitted
 */

$pgPages = isset($pages) ? (int) $pages : 1;
$pgPages = max(1, $pgPages);

$pgPage = isset($page) ? (int) $page : 1;
$pgPage = min(max(1, $pgPage), $pgPages);

$pgTotal = isset($total) && is_numeric($total) ? max(0, (int) $total) : null;

// The base query: everything the current screen needs, minus the page cursor.
$pgBase = [];

if (isset($query) && is_array($query)) {
    $pgBase = $query;
} elseif (isset($base) && is_array($base)) {
    $pgBase = $base;
}

unset($pgBase['page']);

if (!isset($pgBase['p'])) {
    $pgBase['p'] = $view->currentPage();
}

// Rows per page: taken from the caller, otherwise inferred from the totals.
$pgPerPage = isset($perPage) && is_numeric($perPage) ? max(1, (int) $perPage) : null;

if ($pgPerPage === null && $pgTotal !== null && $pgPages > 0) {
    $pgPerPage = max(1, (int) ceil($pgTotal / $pgPages));
}

// A single page without a summary to show carries no information at all.
if ($pgPages <= 1 && $pgTotal === null) {
    return;
}

/** URL of one page, filters preserved. */
$pgLink = static function (int $number) use ($pgBase): string {
    return url(array_merge($pgBase, ['page' => $number]));
};

/*
 * The visible page numbers: the first, the last and a window of two around the
 * current one. `0` marks the position of an ellipsis.
 */
$pgWindow = 2;
$pgNumbers = [];

for ($pgIndex = 1; $pgIndex <= $pgPages; $pgIndex++) {
    if ($pgIndex === 1
        || $pgIndex === $pgPages
        || ($pgIndex >= $pgPage - $pgWindow && $pgIndex <= $pgPage + $pgWindow)
    ) {
        $pgNumbers[] = $pgIndex;
    }
}

$pgItems = [];
$pgPrevious = 0;

foreach ($pgNumbers as $pgNumber) {
    if ($pgPrevious !== 0 && $pgNumber - $pgPrevious > 1) {
        $pgItems[] = 0;
    }

    $pgItems[] = $pgNumber;
    $pgPrevious = $pgNumber;
}

// Summary sentence shown next to the links.
if ($pgTotal !== null && $pgPerPage !== null) {
    $pgFrom = $pgTotal === 0 ? 0 : (($pgPage - 1) * $pgPerPage) + 1;
    $pgTo = $pgTotal === 0 ? 0 : min($pgTotal, $pgPage * $pgPerPage);

    $pgSummary = t('panel.pagination', ['from' => $pgFrom, 'to' => $pgTo, 'total' => $pgTotal]);
} else {
    $pgSummary = t('panel.page_of', ['page' => $pgPage, 'pages' => $pgPages]);
}

?>
<nav class="pagination" aria-label="<?= e(t('common.page')) ?>">
    <span class="meta"><?= e($pgSummary) ?></span>

    <?php if ($pgPages > 1) { ?>
        <?php if ($pgPage > 1) { ?>
            <a class="page-link" href="<?= e($pgLink(1)) ?>"
               aria-label="<?= e(t('common.page') . ' 1') ?>" title="<?= e(t('common.page') . ' 1') ?>">«</a>
            <a class="page-link" href="<?= e($pgLink($pgPage - 1)) ?>"
               rel="prev" aria-label="<?= e(t('common.prev')) ?>" title="<?= e(t('common.prev')) ?>">‹</a>
        <?php } else { ?>
            <span class="page-link" aria-disabled="true">«</span>
            <span class="page-link" aria-disabled="true">‹</span>
        <?php } ?>

        <?php foreach ($pgItems as $pgItem) { ?>
            <?php if ($pgItem === 0) { ?>
                <span class="page-link" aria-hidden="true">…</span>
            <?php } elseif ($pgItem === $pgPage) { ?>
                <span class="page-link is-active" aria-current="page"><?= e((string) $pgItem) ?></span>
            <?php } else { ?>
                <a class="page-link" href="<?= e($pgLink($pgItem)) ?>"
                   aria-label="<?= e(t('common.page') . ' ' . $pgItem) ?>"><?= e((string) $pgItem) ?></a>
            <?php } ?>
        <?php } ?>

        <?php if ($pgPage < $pgPages) { ?>
            <a class="page-link" href="<?= e($pgLink($pgPage + 1)) ?>"
               rel="next" aria-label="<?= e(t('common.next')) ?>" title="<?= e(t('common.next')) ?>">›</a>
            <a class="page-link" href="<?= e($pgLink($pgPages)) ?>"
               aria-label="<?= e(t('common.page') . ' ' . $pgPages) ?>"
               title="<?= e(t('common.page') . ' ' . $pgPages) ?>">»</a>
        <?php } else { ?>
            <span class="page-link" aria-disabled="true">›</span>
            <span class="page-link" aria-disabled="true">»</span>
        <?php } ?>
    <?php } ?>
</nav>
<?php unset($pgBase, $pgItems, $pgNumbers, $pgLink, $pgItem, $pgNumber, $pgIndex, $pgPrevious); ?>
