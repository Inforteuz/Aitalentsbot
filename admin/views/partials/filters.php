<?php

declare(strict_types=1);

// Templates are rendered by admin/index.php through Admin\View; a direct HTTP
// request must not execute them. .htaccess already denies this folder, but not
// every shared host honours it, so the guard is enforced in PHP as well.
defined('AITALENTS_ROOT') || exit('Forbidden');

/**
 * Andijon AI Talents — reusable filter bar.
 *
 * A plain GET form: the browser turns the fields into the very query string the
 * controllers already read, which keeps every filtered view bookmarkable and
 * shareable. `data-auto-submit` lets assets/app.js re-submit the form as soon as
 * a select changes; without JavaScript the "Filtrlash" button does the same.
 *
 * Variables (all optional except $fields):
 *   $fields     array  field definitions, see below
 *   $action     string page key the form submits to (defaults to the current page)
 *   $hidden     array  extra name => value pairs carried along as hidden inputs
 *   $values     array  fallback values, e.g. the controller's $filters array
 *   $submit     string label of the submit button
 *   $reset      bool|string  false hides the reset link, a string overrides its URL
 *   $autoSubmit bool   attach the data-auto-submit hook (default true)
 *
 * A field definition looks like:
 *   ['type' => 'search'|'text'|'date'|'number'|'select',
 *    'name' => 'status', 'label' => t('panel.filter_status'),
 *    'value' => 'pending', 'placeholder' => '…',
 *    'empty' => t('panel.filter_all'),                      // select only
 *    'options' => [['value' => 'pending', 'label' => '…'],  // select only
 *                  ['group' => 'Shaharlar', 'options' => [...]]]]
 */

/** @var \AiTalents\Admin\View $view */

$flPage = isset($action) && is_string($action) && $action !== '' ? $action : $view->currentPage();
$flValues = isset($values) && is_array($values) ? $values : [];
$flHidden = isset($hidden) && is_array($hidden) ? $hidden : [];
$flSubmit = isset($submit) && is_string($submit) && $submit !== '' ? $submit : t('panel.filter_apply');
$flAuto = !isset($autoSubmit) || (bool) $autoSubmit;

$flFields = isset($fields) && is_array($fields) ? $fields : [];

// Zero-configuration default: a single search box named `q`.
if ($flFields === []) {
    $flFields = [[
        'type'        => 'search',
        'name'        => 'q',
        'label'       => t('common.search'),
        'placeholder' => t('panel.search_placeholder'),
    ]];
}

// Where "Tozalash" goes: the same screen without a single filter.
if (isset($reset) && $reset === false) {
    $flReset = null;
} elseif (isset($reset) && is_string($reset) && $reset !== '') {
    $flReset = $reset;
} else {
    $flReset = url(['p' => $flPage]);
}

/** Scalar of a mixed value, ready to be printed inside an attribute. */
$flScalar = static function (mixed $value): string {
    if ($value === null || is_array($value) || is_object($value)) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return (string) $value;
};

/** The value of one field: explicit first, then $values, then the query string. */
$flValueOf = static function (array $field, array $bag) use ($flScalar): string {
    if (array_key_exists('value', $field)) {
        return $flScalar($field['value']);
    }

    $name = (string) ($field['name'] ?? '');

    if ($name !== '' && array_key_exists($name, $bag)) {
        return $flScalar($bag[$name]);
    }

    return $name === '' ? '' : $flScalar(\AiTalents\Admin\Request::get($name));
};

/** A stable, HTML-safe id for the label/field pair. */
$flId = static function (string $name): string {
    $clean = preg_replace('/[^A-Za-z0-9_\-]/', '-', $name);

    return 'filter-' . ($clean === '' || $clean === null ? 'field' : $clean);
};

?>
<form class="filters" method="get" action="<?= e(\AiTalents\Admin\View::ENTRY) ?>"
      <?= $flAuto ? 'data-auto-submit' : '' ?> role="search">
    <input type="hidden" name="p" value="<?= e($flPage) ?>">
    <?php foreach ($flHidden as $flKey => $flHiddenValue) { ?>
        <?php if (is_string($flKey) && $flKey !== '' && $flKey !== 'p' && $flKey !== 'page') { ?>
            <input type="hidden" name="<?= e($flKey) ?>" value="<?= e($flScalar($flHiddenValue)) ?>">
        <?php } ?>
    <?php } ?>

    <?php foreach ($flFields as $flField) { ?>
        <?php
        if (!is_array($flField)) {
            continue;
        }

        $flName = trim((string) ($flField['name'] ?? ''));

        if ($flName === '') {
            continue;
        }

        $flType = strtolower((string) ($flField['type'] ?? 'text'));
        $flLabel = (string) ($flField['label'] ?? '');
        $flValue = $flValueOf($flField, $flValues);
        $flFieldId = $flId($flName);
        $flPlaceholder = (string) ($flField['placeholder'] ?? '');
        ?>

        <?php if ($flType === 'hidden') { ?>
            <input type="hidden" name="<?= e($flName) ?>" value="<?= e($flValue) ?>">
        <?php } elseif ($flType === 'select') { ?>
            <div class="field">
                <?php if ($flLabel !== '') { ?>
                    <label class="field__label" for="<?= e($flFieldId) ?>"><?= e($flLabel) ?></label>
                <?php } ?>
                <select class="select" id="<?= e($flFieldId) ?>" name="<?= e($flName) ?>">
                    <?php if (!isset($flField['empty']) || $flField['empty'] !== false) { ?>
                        <option value=""><?= e((string) ($flField['empty'] ?? t('panel.filter_all'))) ?></option>
                    <?php } ?>
                    <?php foreach ((array) ($flField['options'] ?? []) as $flOptionKey => $flOption) { ?>
                        <?php if (is_array($flOption) && isset($flOption['options'])) { ?>
                            <optgroup label="<?= e((string) ($flOption['group'] ?? '')) ?>">
                                <?php foreach ((array) $flOption['options'] as $flSubKey => $flSub) { ?>
                                    <?php
                                    $flSubValue = is_array($flSub)
                                        ? $flScalar($flSub['value'] ?? $flSubKey)
                                        : $flScalar($flSubKey);
                                    $flSubLabel = is_array($flSub)
                                        ? (string) ($flSub['label'] ?? $flSubValue)
                                        : $flScalar($flSub);
                                    ?>
                                    <option value="<?= e($flSubValue) ?>"
                                        <?= $flSubValue === $flValue ? 'selected' : '' ?>><?= e($flSubLabel) ?></option>
                                <?php } ?>
                            </optgroup>
                        <?php } else { ?>
                            <?php
                            $flOptionValue = is_array($flOption)
                                ? $flScalar($flOption['value'] ?? $flOptionKey)
                                : $flScalar($flOptionKey);
                            $flOptionLabel = is_array($flOption)
                                ? (string) ($flOption['label'] ?? $flOptionValue)
                                : $flScalar($flOption);
                            ?>
                            <option value="<?= e($flOptionValue) ?>"
                                <?= $flOptionValue === $flValue ? 'selected' : '' ?>><?= e($flOptionLabel) ?></option>
                        <?php } ?>
                    <?php } ?>
                </select>
            </div>
        <?php } else { ?>
            <?php
            $flInputType = match ($flType) {
                'search' => 'search',
                'date'   => 'date',
                'number' => 'number',
                default  => 'text',
            };
            ?>
            <div class="field">
                <?php if ($flLabel !== '') { ?>
                    <label class="field__label" for="<?= e($flFieldId) ?>"><?= e($flLabel) ?></label>
                <?php } ?>
                <input class="input" type="<?= e($flInputType) ?>" id="<?= e($flFieldId) ?>"
                       name="<?= e($flName) ?>" value="<?= e($flValue) ?>"
                       <?php if ($flPlaceholder !== '') { ?>placeholder="<?= e($flPlaceholder) ?>"<?php } ?>
                       <?php if ($flInputType === 'search') { ?>autocomplete="off" spellcheck="false"<?php } ?>>
            </div>
        <?php } ?>
    <?php } ?>

    <button class="btn btn--primary btn--sm" type="submit"><?= e($flSubmit) ?></button>

    <?php if ($flReset !== null) { ?>
        <a class="btn btn--ghost btn--sm" href="<?= e($flReset) ?>"><?= e(t('panel.filter_reset')) ?></a>
    <?php } ?>
</form>
<?php
unset(
    $flFields, $flField, $flHidden, $flHiddenValue, $flKey, $flValues, $flScalar, $flValueOf, $flId,
    $flName, $flType, $flLabel, $flValue, $flFieldId, $flPlaceholder, $flInputType,
    $flOption, $flOptionKey, $flOptionValue, $flOptionLabel, $flSub, $flSubKey, $flSubValue, $flSubLabel
);
?>
