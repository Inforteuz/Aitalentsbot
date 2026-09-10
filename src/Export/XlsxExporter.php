<?php

declare(strict_types=1);

namespace AiTalents\Export;

use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Repository\RegistrationRepository;
use AiTalents\Text;

/**
 * Turns the registrations table into an Excel workbook.
 *
 * The export is XLSX, never CSV: Excel opens it without an import wizard, the
 * dates stay sortable and a phone number keeps its leading "+" instead of
 * being mangled into scientific notation.
 *
 * Rows are pulled from {@see RegistrationRepository::each()} — a generator that
 * walks the table in chunks — and handed to {@see XlsxWriter} one at a time, so
 * exporting fifty thousand applications uses about as much memory as exporting
 * ten.
 *
 * Column titles come from the `export.*` language keys in the requested locale;
 * district, direction and status values are rendered as human readable labels
 * (via {@see Catalog} and {@see RegistrationStatus}), not as the raw storage
 * keys.
 *
 *     $exporter = new XlsxExporter($app->registrations());
 *     $exporter->toFile(AITALENTS_ROOT . '/data/exports/arizalar.xlsx', ['status' => 'approved']);
 *     // ... or straight to the browser:
 *     $exporter->stream(['district' => 'asaka'], 'uz');
 *
 * PHP 8.1 compatible, no external dependencies.
 */
final class XlsxExporter
{
    /**
     * The exported columns, in order.
     *
     * `key` is the registration field, `title` the `export.*` language key,
     * `type` the XlsxWriter cell type and `width` the column width.
     *
     * @var array<int,array{key:string,title:string,width:int,type:string}>
     */
    private const COLUMNS = [
        ['key' => 'id',              'title' => 'export.id',              'width' => 8,  'type' => 'number'],
        ['key' => 'created_at',      'title' => 'export.created_at',      'width' => 19, 'type' => 'date'],
        ['key' => 'full_name',       'title' => 'export.full_name',       'width' => 28, 'type' => 'text'],
        ['key' => 'phone',           'title' => 'export.phone',           'width' => 17, 'type' => 'text'],
        ['key' => 'birth_year',      'title' => 'export.birth_year',      'width' => 12, 'type' => 'number'],
        ['key' => 'district',        'title' => 'export.district',        'width' => 22, 'type' => 'text'],
        ['key' => 'directions',      'title' => 'export.directions',      'width' => 38, 'type' => 'text'],
        ['key' => 'direction_other', 'title' => 'export.direction_other', 'width' => 22, 'type' => 'text'],
        ['key' => 'portfolio',       'title' => 'export.portfolio',       'width' => 48, 'type' => 'text'],
        ['key' => 'portfolio_links', 'title' => 'export.portfolio_links', 'width' => 34, 'type' => 'url'],
        ['key' => 'status',          'title' => 'export.status',          'width' => 14, 'type' => 'text'],
        ['key' => 'admin_note',      'title' => 'export.admin_note',      'width' => 30, 'type' => 'text'],
        ['key' => 'telegram_id',     'title' => 'export.telegram_id',     'width' => 16, 'type' => 'text'],
        ['key' => 'username',        'title' => 'export.username',        'width' => 18, 'type' => 'text'],
        ['key' => 'locale',          'title' => 'export.locale',          'width' => 8,  'type' => 'text'],
        ['key' => 'source',          'title' => 'export.source',          'width' => 12, 'type' => 'text'],
        ['key' => 'reviewed_by',     'title' => 'export.reviewed_by',     'width' => 16, 'type' => 'text'],
        ['key' => 'reviewed_at',     'title' => 'export.reviewed_at',     'width' => 19, 'type' => 'date'],
        ['key' => 'updated_at',      'title' => 'export.updated_at',      'width' => 19, 'type' => 'date'],
    ];

    private RegistrationRepository $repo;

    public function __construct(RegistrationRepository $repo)
    {
        $this->repo = $repo;
    }

    /* =====================================================================
     | Layout
     ===================================================================== */

    /**
     * Column definitions for {@see XlsxWriter::setColumns()}.
     *
     * The extra `key` entry is ignored by the writer and is what
     * {@see self::row()} uses to line the values up with the headers.
     *
     * @return array<int,array{key:string,title:string,width:int,type:string}>
     */
    public function columns(string $locale = 'uz'): array
    {
        $columns = [];

        foreach (self::COLUMNS as $column) {
            $columns[] = [
                'key'   => $column['key'],
                'title' => Lang::t($column['title'], $locale),
                'width' => $column['width'],
                'type'  => $column['type'],
            ];
        }

        return $columns;
    }

    /**
     * Just the translated column titles.
     *
     * @return string[]
     */
    public function headers(string $locale = 'uz'): array
    {
        $titles = [];

        foreach (self::COLUMNS as $column) {
            $titles[] = Lang::t($column['title'], $locale);
        }

        return $titles;
    }

    /**
     * One registration row, in the column order of {@see self::columns()}.
     *
     * @param array<string,mixed> $registration a row as returned by the repository
     *
     * @return array<int,mixed>
     */
    public function row(array $registration, string $locale = 'uz'): array
    {
        $district = self::text($registration, 'district');
        $status   = self::text($registration, 'status');

        return [
            self::integer($registration, 'id'),
            self::text($registration, 'created_at'),
            self::text($registration, 'full_name'),
            self::text($registration, 'phone'),
            self::integer($registration, 'birth_year'),
            $district === '' ? '' : Catalog::districtLabel($district, $locale),
            $this->directionsLabel($registration, $locale),
            self::text($registration, 'direction_other'),
            self::text($registration, 'portfolio'),
            implode("\n", self::listValue($registration, 'portfolio_links')),
            $this->statusLabel($status, $locale),
            self::text($registration, 'admin_note'),
            self::text($registration, 'telegram_id'),
            self::text($registration, 'username', 'user_username'),
            self::text($registration, 'locale', 'user_locale'),
            self::text($registration, 'source'),
            self::text($registration, 'reviewed_by'),
            self::text($registration, 'reviewed_at'),
            self::text($registration, 'updated_at'),
        ];
    }

    /* =====================================================================
     | Building
     ===================================================================== */

    /**
     * Build the workbook, streaming the matching registrations into it.
     *
     * @param array<string,mixed> $filters passed straight to RegistrationRepository::each()
     */
    public function build(array $filters = [], string $locale = 'uz'): XlsxWriter
    {
        $writer = new XlsxWriter($this->sheetName($locale));
        $writer->setColumns($this->columns($locale));

        foreach ($this->repo->each($filters) as $registration) {
            if (is_array($registration)) {
                $writer->addRow($this->row($registration, $locale));
            }
        }

        return $writer;
    }

    /**
     * Write the workbook to disk, creating the directory when it is missing.
     *
     * @param array<string,mixed> $filters
     *
     * @return int number of exported registrations (the header row excluded)
     */
    public function toFile(string $path, array $filters = [], string $locale = 'uz'): int
    {
        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('XlsxExporter: cannot create the export directory ' . $directory);
        }

        $writer = $this->build($filters, $locale);
        $writer->save($path);

        return $writer->rowCount();
    }

    /**
     * Send the workbook to the browser as a download.
     *
     * Sends its own headers and echoes the bytes; it never calls exit(), so the
     * caller stays in control (the admin panel still wants to write its audit
     * log entry afterwards).
     *
     * @param array<string,mixed> $filters
     */
    public function stream(array $filters = [], string $locale = 'uz', ?string $filename = null): void
    {
        $filename ??= $this->filename($locale);

        $this->build($filters, $locale)->stream($filename);
    }

    /**
     * Download name: the `export.filename` stem plus today's date,
     * e.g. `andijon-ai-talents-arizalar-2026-09-10.xlsx`.
     */
    public function filename(string $locale = 'uz'): string
    {
        $stem = Lang::t('export.filename', $locale);

        // Lang::t() returns the key itself when a translation is missing.
        if ($stem === 'export.filename') {
            $stem = 'export';
        }

        $stem = Text::slug($stem);

        if ($stem === '') {
            $stem = 'export';
        }

        return $stem . '-' . date('Y-m-d') . '.xlsx';
    }

    /* =====================================================================
     | Internals
     ===================================================================== */

    /** Worksheet name in the requested locale, with a neutral fallback. */
    private function sheetName(string $locale): string
    {
        $name = Lang::t('panel.registrations_title', $locale);

        return $name === 'panel.registrations_title' ? 'Sheet1' : $name;
    }

    /**
     * The chosen directions as a readable, comma separated list.
     *
     * Emoji are dropped on purpose: a spreadsheet cell is filtered and sorted,
     * not decorated. Keys that are no longer in the catalogue survive verbatim
     * so an old application still shows what it was.
     *
     * @param array<string,mixed> $registration
     */
    private function directionsLabel(array $registration, string $locale): string
    {
        $labels = [];

        foreach (self::listValue($registration, 'directions') as $key) {
            $labels[] = Catalog::directionLabel($key, $locale, false);
        }

        return implode(', ', $labels);
    }

    /** Human readable status label, unknown values passed through untouched. */
    private function statusLabel(string $status, string $locale): string
    {
        if ($status === '') {
            return '';
        }

        $enum = RegistrationStatus::tryOrNull($status);

        return $enum === null ? $status : $enum->label($locale);
    }

    /**
     * A scalar field as a string, trying each candidate key in turn.
     *
     * `username` and `locale` live on the users table, so a repository that
     * joins them may expose them under a prefixed name.
     *
     * @param array<string,mixed> $row
     */
    private static function text(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }

            $value = $row[$key];

            if (is_string($value)) {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            if ($value instanceof \Stringable) {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * An integer field, or '' when it is absent — an empty cell reads better
     * than a zero for a missing birth year.
     *
     * @param array<string,mixed> $row
     */
    private static function integer(array $row, string $key): int|string
    {
        $value = $row[$key] ?? null;

        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return self::text($row, $key);
    }

    /**
     * A JSON list column as a plain string array.
     *
     * The repository already decodes `directions` and `portfolio_links`, but
     * the raw JSON is accepted too so the exporter also works on rows that come
     * straight from a query.
     *
     * @param array<string,mixed> $row
     *
     * @return string[]
     */
    private static function listValue(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return [];
            }

            $decoded = json_decode($trimmed, true);
            $value   = is_array($decoded) ? $decoded : [$trimmed];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $item = trim($item);
            } elseif (is_int($item) || is_float($item)) {
                $item = (string) $item;
            } else {
                continue;
            }

            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }
}
