<?php

declare(strict_types=1);

namespace AiTalents\Export;

/**
 * A small, self-contained Office Open XML (.xlsx) writer.
 *
 * Composer is not available on the deployment target (shared hosting), so
 * PhpSpreadsheet is out of reach and this class writes the SpreadsheetML
 * package by hand. It deliberately implements only what the export needs:
 *
 *  - exactly one worksheet;
 *  - a styled, frozen, auto-filtered header row;
 *  - inline strings (no sharedStrings part), so text stays text — a phone
 *    number keeps its leading "+" and a Telegram id never turns into
 *    scientific notation;
 *  - real numbers and real dates for the columns that ask for them, so Excel
 *    can sort and filter them;
 *  - a ZIP container built either with ext-zip or, when that extension is
 *    missing, with the pure PHP writer at the bottom of this file.
 *
 * The emitted package is:
 *
 *     [Content_Types].xml
 *     _rels/.rels
 *     docProps/app.xml
 *     docProps/core.xml
 *     xl/workbook.xml
 *     xl/_rels/workbook.xml.rels
 *     xl/styles.xml
 *     xl/worksheets/sheet1.xml
 *
 * Memory: a row is turned into its `<row>` XML the moment it is added and
 * appended to a `php://temp` stream (which spills to disk past a few MB), so a
 * 50 000 row export never holds 50 000 PHP arrays. The finished worksheet is
 * assembled into a temporary file and streamed into the archive.
 *
 * Usage:
 *
 *     $xlsx = new XlsxWriter('Arizalar');
 *     $xlsx->setColumns([
 *         ['title' => 'ID',      'width' => 8,  'type' => 'number'],
 *         ['title' => 'Telefon', 'width' => 16, 'type' => 'text'],
 *         ['title' => 'Sana',    'width' => 18, 'type' => 'date'],
 *     ]);
 *     $xlsx->addRow([1, '+998901234567', '2026-09-10 12:30:00']);
 *     $xlsx->save('/tmp/arizalar.xlsx');
 *
 * PHP 8.1 compatible, no external dependencies.
 */
final class XlsxWriter
{
    /** Campaign navy used as the header background (ARGB). */
    public const HEADER_FILL = 'FF0A1B3D';

    /** Header font colour (ARGB). */
    public const HEADER_COLOR = 'FFFFFFFF';

    /** Column types understood by {@see setColumns()}. */
    public const TYPES = ['text', 'number', 'date', 'url'];

    /* Style indexes; they must match the order of <cellXfs> in stylesXml(). */
    private const STYLE_DEFAULT = 0;
    private const STYLE_HEADER  = 1;
    private const STYLE_TEXT    = 2;
    private const STYLE_DATE    = 3;
    private const STYLE_NUMBER  = 4;

    /** Excel's own limits for a column width, in characters. */
    private const MIN_WIDTH = 4.0;
    private const MAX_WIDTH = 120.0;

    /** Excel caps a worksheet name at 31 characters. */
    private const MAX_SHEET_NAME = 31;

    /** Number of days between 1899-12-30 (Excel serial 0) and the unix epoch. */
    private const EPOCH_OFFSET = 25569;

    /**
     * Test hook — NOT a public API.
     *
     * When true the pure PHP ZIP writer is used even though ext-zip is
     * available, which is how the test suite proves the fallback produces the
     * very same archive on hosts without the extension. Flipped through
     * reflection; there is deliberately no public setter.
     */
    private static bool $forceNativeZip = false;

    /** Worksheet name, already sanitised. */
    private string $sheetName = 'Sheet1';

    /**
     * Column definitions.
     *
     * @var array<int,array{title:string,width:float,type:string}>
     */
    private array $columns = [];

    /**
     * Serialised `<row>` elements. A php://temp stream, opened lazily.
     *
     * Untyped on purpose: PHP has no `resource` type declaration.
     *
     * @var resource|null
     */
    private $rows;

    /** Data rows written so far (the header row is not counted). */
    private int $rowCount = 0;

    /** Widest row seen, so the dimension covers rows longer than the header. */
    private int $maxColumns = 0;

    private bool $freezeHeader = true;

    private bool $autoFilter = true;

    /** @param string $sheetName worksheet name; sanitised like setSheetName() */
    public function __construct(string $sheetName = 'Sheet1')
    {
        $this->setSheetName($sheetName);
    }

    public function __destruct()
    {
        if (is_resource($this->rows)) {
            fclose($this->rows);
            $this->rows = null;
        }
    }

    /* =====================================================================
     | Configuration
     ===================================================================== */

    /**
     * Declare the columns.
     *
     * Every entry accepts `title` (string), `width` (characters, defaults to
     * 18) and `type` (`text`, `number`, `date` or `url`; anything else becomes
     * `text`). Extra keys are ignored, so callers may keep their own metadata
     * — {@see XlsxExporter::columns()} carries a `key` for instance.
     *
     * @param array<int|string,mixed> $columns
     */
    public function setColumns(array $columns): self
    {
        $this->columns = [];

        foreach ($columns as $column) {
            if (is_string($column)) {
                $column = ['title' => $column];
            }

            if (!is_array($column)) {
                continue;
            }

            $type = strtolower(trim((string) ($column['type'] ?? 'text')));

            if (!in_array($type, self::TYPES, true)) {
                $type = 'text';
            }

            $width = isset($column['width']) && is_numeric($column['width'])
                ? (float) $column['width']
                : 18.0;

            $this->columns[] = [
                'title' => self::stringify($column['title'] ?? ''),
                'width' => max(self::MIN_WIDTH, min(self::MAX_WIDTH, $width)),
                'type'  => $type,
            ];
        }

        $this->maxColumns = max($this->maxColumns, count($this->columns));

        return $this;
    }

    /**
     * Worksheet name: trimmed, stripped of the characters Excel forbids
     * (`[ ] : * ? / \`) and cut to 31 characters.
     */
    public function setSheetName(string $name): self
    {
        $name = str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name);
        $name = self::filterXmlChars($name);
        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = trim($name);
        $name = function_exists('mb_substr')
            ? mb_substr($name, 0, self::MAX_SHEET_NAME, 'UTF-8')
            : substr($name, 0, self::MAX_SHEET_NAME);

        // A leading or trailing apostrophe is illegal in a sheet reference.
        $name = trim($name, "'");

        $this->sheetName = $name === '' ? 'Sheet1' : $name;

        return $this;
    }

    public function sheetName(): string
    {
        return $this->sheetName;
    }

    /** Freeze the header row (pane split at A2). */
    public function setFreezeHeader(bool $freeze): self
    {
        $this->freezeHeader = $freeze;

        return $this;
    }

    /** Put filter dropdowns on the header row. */
    public function setAutoFilter(bool $enabled): self
    {
        $this->autoFilter = $enabled;

        return $this;
    }

    /* =====================================================================
     | Rows
     ===================================================================== */

    /**
     * Append one row, serialising it to XML immediately.
     *
     * Cells are matched to the columns by position; extra cells are written as
     * text and widen the sheet, missing cells are simply absent.
     *
     * @param array<int|string,mixed> $cells
     */
    public function addRow(array $cells): self
    {
        $this->rowCount++;
        $rowIndex = $this->rowCount + 1; // row 1 is the header

        $xml    = '<row r="' . $rowIndex . '">';
        $column = 0;

        foreach ($cells as $value) {
            $column++;
            $type = $this->columns[$column - 1]['type'] ?? 'text';
            $xml .= $this->cellXml(self::columnLetter($column) . $rowIndex, $value, $type);
        }

        $xml .= '</row>';

        $this->maxColumns = max($this->maxColumns, $column);

        fwrite($this->rowStream(), $xml);

        return $this;
    }

    /**
     * Append many rows — a generator is consumed lazily, one row at a time.
     *
     * @param iterable<mixed> $rows
     */
    public function addRows(iterable $rows): self
    {
        foreach ($rows as $row) {
            if (is_array($row)) {
                $this->addRow($row);
            }
        }

        return $this;
    }

    /** Data rows added so far (the header row excluded). */
    public function rowCount(): int
    {
        return $this->rowCount;
    }

    /** Number of declared columns. */
    public function columnCount(): int
    {
        return count($this->columns);
    }

    /* =====================================================================
     | Output
     ===================================================================== */

    /** The whole workbook as a binary string. */
    public function toString(): string
    {
        $temp = self::tempFile('xlsxpkg');

        try {
            $this->writePackage($temp);
            $bytes = file_get_contents($temp);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        if ($bytes === false) {
            throw new \RuntimeException('XlsxWriter: could not read the generated workbook back.');
        }

        return $bytes;
    }

    /**
     * Write the workbook to disk.
     *
     * @return int bytes written
     */
    public function save(string $path): int
    {
        $directory = dirname($path);

        if ($directory !== '' && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('XlsxWriter: cannot create the directory ' . $directory);
        }

        $this->writePackage($path);

        clearstatcache(true, $path);
        $size = filesize($path);

        return $size === false ? 0 : $size;
    }

    /**
     * Send the workbook to the browser as a download.
     *
     * Never calls exit(): the caller decides what happens next.
     */
    public function stream(string $filename): void
    {
        $filename = self::sanitiseFilename($filename);
        $temp     = self::tempFile('xlsxdl');

        try {
            $this->writePackage($temp);

            clearstatcache(true, $temp);
            $size = filesize($temp);

            if (!headers_sent()) {
                // A stray byte in an output buffer would corrupt the download.
                // Under CLI (the test harness) buffers are left alone.
                if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
                    while (ob_get_level() > 0 && ob_end_clean()) {
                        // keep unwinding
                    }
                }

                $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename);
                $ascii = trim($ascii, '-');

                if ($ascii === '' || $ascii === '.xlsx') {
                    $ascii = 'export.xlsx';
                }

                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header(
                    'Content-Disposition: attachment; filename="' . $ascii . '"; '
                    . "filename*=UTF-8''" . rawurlencode($filename)
                );

                if ($size !== false) {
                    header('Content-Length: ' . $size);
                }

                header('Content-Transfer-Encoding: binary');
                header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header('Expires: 0');
                header('X-Content-Type-Options: nosniff');
            }

            readfile($temp);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /** Which ZIP implementation this class will use: 'ziparchive' or 'native'. */
    public static function zipSupported(): string
    {
        if (self::$forceNativeZip) {
            return 'native';
        }

        return class_exists('\ZipArchive') && extension_loaded('zip') ? 'ziparchive' : 'native';
    }

    /* =====================================================================
     | Helpers that are useful on their own
     ===================================================================== */

    /**
     * A1 style column name for a 1 based index: 1 => A, 26 => Z, 27 => AA,
     * 703 => AAA. Values below 1 are clamped to A.
     */
    public static function columnLetter(int $index): string
    {
        if ($index < 1) {
            $index = 1;
        }

        $letters = '';

        while ($index > 0) {
            $index--;
            $letters = chr(65 + ($index % 26)) . $letters;
            $index   = intdiv($index, 26);
        }

        return $letters;
    }

    /* =====================================================================
     | Package assembly
     ===================================================================== */

    /** Build the whole .xlsx package at $path. */
    private function writePackage(string $path): void
    {
        $sheet = self::tempFile('xlsxsheet');

        try {
            $this->writeSheetXml($sheet);

            $parts = [
                ['name' => '[Content_Types].xml',      'data' => $this->contentTypesXml(),  'file' => null],
                ['name' => '_rels/.rels',              'data' => $this->rootRelsXml(),      'file' => null],
                ['name' => 'docProps/app.xml',         'data' => $this->appXml(),           'file' => null],
                ['name' => 'docProps/core.xml',        'data' => $this->coreXml(),          'file' => null],
                ['name' => 'xl/workbook.xml',          'data' => $this->workbookXml(),      'file' => null],
                ['name' => 'xl/_rels/workbook.xml.rels', 'data' => $this->workbookRelsXml(), 'file' => null],
                ['name' => 'xl/styles.xml',            'data' => $this->stylesXml(),        'file' => null],
                ['name' => 'xl/worksheets/sheet1.xml', 'data' => null,                      'file' => $sheet],
            ];

            if (self::zipSupported() === 'ziparchive') {
                $this->writeZipWithZipArchive($parts, $path);
            } else {
                $this->writeZipNative($parts, $path);
            }
        } finally {
            if (is_file($sheet)) {
                @unlink($sheet);
            }
        }
    }

    /** Assemble the worksheet: prologue + the buffered rows + epilogue. */
    private function writeSheetXml(string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('XlsxWriter: cannot open the temporary worksheet file.');
        }

        try {
            fwrite($handle, $this->sheetPrologue());

            if (is_resource($this->rows)) {
                $position = ftell($this->rows);
                rewind($this->rows);
                stream_copy_to_stream($this->rows, $handle);

                // Restore the append position so more rows may still be added.
                fseek($this->rows, $position === false ? 0 : $position);
            }

            fwrite($handle, $this->sheetEpilogue());
        } finally {
            fclose($handle);
        }
    }

    private function sheetPrologue(): string
    {
        $columns = $this->effectiveColumns();
        $lastCol = self::columnLetter(max(1, count($columns)));
        $lastRow = $this->rowCount + 1;

        $xml = self::XML_DECLARATION
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $lastCol . $lastRow . '"/>'
            . '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';

        if ($this->freezeHeader) {
            $xml .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A2" sqref="A2"/>';
        }

        $xml .= '</sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>';

        if ($columns !== []) {
            $xml .= '<cols>';
            $index = 0;

            foreach ($columns as $column) {
                $index++;
                $xml .= '<col min="' . $index . '" max="' . $index . '"'
                    . ' width="' . self::decimal($column['width'], 2) . '" customWidth="1"/>';
            }

            $xml .= '</cols>';
        }

        $xml .= '<sheetData>' . $this->headerRowXml($columns);

        return $xml;
    }

    private function sheetEpilogue(): string
    {
        $columns = $this->effectiveColumns();
        $lastCol = self::columnLetter(max(1, count($columns)));

        $xml = '</sheetData>';

        if ($this->autoFilter && $columns !== []) {
            // Excel writes the header AND the data rows into the filter range;
            // a header-only range leaves LibreOffice with nothing to filter.
            $lastRow = $this->rowCount > 0 ? $this->rowCount + 1 : 1;
            $xml    .= '<autoFilter ref="A1:' . $lastCol . $lastRow . '"/>';
        }

        $xml .= '<pageMargins left="0.5" right="0.5" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
            . '</worksheet>';

        return $xml;
    }

    /**
     * @param array<int,array{title:string,width:float,type:string}> $columns
     */
    private function headerRowXml(array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $xml   = '<row r="1" ht="26" customHeight="1" s="' . self::STYLE_HEADER . '" customFormat="1">';
        $index = 0;

        foreach ($columns as $column) {
            $index++;
            $ref   = self::columnLetter($index) . '1';
            $title = self::escape($column['title']);

            $xml .= $title === ''
                ? '<c r="' . $ref . '" s="' . self::STYLE_HEADER . '"/>'
                : '<c r="' . $ref . '" s="' . self::STYLE_HEADER . '" t="inlineStr">'
                    . '<is><t xml:space="preserve">' . $title . '</t></is></c>';
        }

        return $xml . '</row>';
    }

    /**
     * Column definitions padded to the widest row, so the dimension and the
     * autofilter always cover every cell that was actually written.
     *
     * @return array<int,array{title:string,width:float,type:string}>
     */
    private function effectiveColumns(): array
    {
        $columns = $this->columns;

        for ($i = count($columns); $i < $this->maxColumns; $i++) {
            $columns[] = ['title' => '', 'width' => 18.0, 'type' => 'text'];
        }

        return $columns;
    }

    /** Lazily opened row buffer. */
    private function rowStream()
    {
        if (!is_resource($this->rows)) {
            // Up to 4 MB in RAM, then transparently backed by a temp file.
            $stream = fopen('php://temp/maxmemory:4194304', 'w+b');

            if ($stream === false) {
                throw new \RuntimeException('XlsxWriter: cannot open the row buffer.');
            }

            $this->rows = $stream;
        }

        return $this->rows;
    }

    /* =====================================================================
     | Cells
     ===================================================================== */

    /**
     * One `<c>` element.
     *
     * Anything that is not explicitly a number or a date column is written as
     * an inline string, which is what keeps "+998901234567" and a ten digit
     * Telegram id intact.
     */
    private function cellXml(string $ref, mixed $value, string $type): string
    {
        if ($value === null) {
            return '<c r="' . $ref . '" s="' . self::STYLE_TEXT . '"/>';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        if ($type === 'number') {
            $number = self::toNumber($value);

            if ($number !== null) {
                return '<c r="' . $ref . '" s="' . self::STYLE_NUMBER . '"><v>' . $number . '</v></c>';
            }
        } elseif ($type === 'date') {
            $serial = self::toExcelSerial($value);

            if ($serial !== null) {
                return '<c r="' . $ref . '" s="' . self::STYLE_DATE . '"><v>' . $serial . '</v></c>';
            }
        }

        $text = self::escape(self::stringify($value));

        if ($text === '') {
            return '<c r="' . $ref . '" s="' . self::STYLE_TEXT . '"/>';
        }

        return '<c r="' . $ref . '" s="' . self::STYLE_TEXT . '" t="inlineStr">'
            . '<is><t xml:space="preserve">' . $text . '</t></is></c>';
    }

    /** Turn anything into a display string. */
    private static function stringify(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return $value === true ? '1' : ($value === false ? '0' : '');
        }

        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? self::floatToString($value) : '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_array($value)) {
            $flat = [];

            foreach ($value as $item) {
                if (is_scalar($item) || $item === null || $item instanceof \Stringable) {
                    $flat[] = self::stringify($item);
                }
            }

            return implode(', ', $flat);
        }

        if ($value instanceof \Stringable || (is_object($value) && method_exists($value, '__toString'))) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Render a value for a `number` column, or null when it is not numeric and
     * should therefore be written as text instead.
     */
    private static function toNumber(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? self::floatToString($value) : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        // Keep full precision for integers that survive as PHP ints.
        if (preg_match('/^[+-]?\d+$/', $trimmed) === 1) {
            $asInt = (int) $trimmed;

            if ((string) $asInt === ltrim($trimmed, '+')) {
                return (string) $asInt;
            }
        }

        return self::floatToString((float) $trimmed);
    }

    /** Locale independent float rendering (PHP 8 no longer uses LC_NUMERIC). */
    private static function floatToString(float $value): string
    {
        if (!is_finite($value)) {
            return '0';
        }

        if ($value === floor($value) && abs($value) < 1.0e15) {
            return number_format($value, 0, '.', '');
        }

        return (string) $value;
    }

    /** Fixed point rendering without a locale dependent separator. */
    private static function decimal(float $value, int $decimals): string
    {
        $formatted = number_format($value, $decimals, '.', '');

        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' || $formatted === '-' ? '0' : $formatted;
    }

    /* =====================================================================
     | Dates
     ===================================================================== */

    /**
     * Excel serial number for a date value, or null when the value cannot be
     * parsed (the caller then falls back to writing it as text, which is much
     * better than emitting a broken cell).
     *
     * Accepts a `Y-m-d H:i:s` string (also `Y-m-d`, an ISO `T` separator and a
     * trailing timezone), a unix timestamp and a DateTimeInterface.
     */
    private static function toExcelSerial(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return self::serialFromParts(
                (int) $value->format('Y'),
                (int) $value->format('n'),
                (int) $value->format('j'),
                (int) $value->format('G'),
                (int) $value->format('i'),
                (int) $value->format('s')
            );
        }

        if (is_int($value) || is_float($value)) {
            if (is_float($value) && !is_finite($value)) {
                return null;
            }

            return self::serialFromTimestamp((int) $value);
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === '0000-00-00 00:00:00' || $trimmed === '0000-00-00') {
            return null;
        }

        // The storage format used everywhere in this project.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?/', $trimmed, $m) === 1) {
            return self::serialFromParts(
                (int) $m[1],
                (int) $m[2],
                (int) $m[3],
                isset($m[4]) ? (int) $m[4] : 0,
                isset($m[5]) ? (int) $m[5] : 0,
                isset($m[6]) ? (int) $m[6] : 0
            );
        }

        // A unix timestamp that arrived as a string.
        if (preg_match('/^-?\d{1,11}$/', $trimmed) === 1) {
            return self::serialFromTimestamp((int) $trimmed);
        }

        $timestamp = strtotime($trimmed);

        return $timestamp === false ? null : self::serialFromTimestamp($timestamp);
    }

    private static function serialFromTimestamp(int $timestamp): ?string
    {
        $parts = explode('-', date('Y-n-j-G-i-s', $timestamp));

        if (count($parts) !== 6) {
            return null;
        }

        return self::serialFromParts(
            (int) $parts[0],
            (int) $parts[1],
            (int) $parts[2],
            (int) $parts[3],
            (int) $parts[4],
            (int) $parts[5]
        );
    }

    /**
     * Days since 1899-12-30 plus the time of day as a fraction.
     *
     * Excel keeps Lotus 1-2-3's bug of treating 1900 as a leap year, so serial
     * 60 is the non existent 1900-02-29 and everything from 1900-03-01 onwards
     * is simply "days since 1899-12-30". Dates before 1900-01-01 have no serial
     * at all and are rejected here.
     */
    private static function serialFromParts(int $y, int $mo, int $d, int $h, int $mi, int $s): ?string
    {
        if ($y < 1900 || $y > 9999 || $mo < 1 || $mo > 12 || $d < 1 || $d > 31) {
            return null;
        }

        if ($h < 0 || $h > 23 || $mi < 0 || $mi > 59 || $s < 0 || $s > 60) {
            return null;
        }

        if (!checkdate($mo, $d, $y)) {
            return null;
        }

        $days = self::daysFromCivil($y, $mo, $d);

        // 1900-01-01 .. 1900-02-28 sit one day below the modern offset because
        // the phantom 29 February 1900 has not been passed yet.
        $serial = ($y === 1900 && $mo <= 2)
            ? $days + self::EPOCH_OFFSET - 1
            : $days + self::EPOCH_OFFSET;

        if ($serial < 1) {
            return null;
        }

        $fraction = ($h * 3600 + $mi * 60 + min($s, 59)) / 86400.0;
        $value    = number_format($serial + $fraction, 8, '.', '');

        return rtrim(rtrim($value, '0'), '.');
    }

    /**
     * Days between 1970-01-01 and the given proleptic Gregorian date.
     * (Howard Hinnant's days_from_civil, which needs no calendar extension.)
     */
    private static function daysFromCivil(int $y, int $m, int $d): int
    {
        $y  -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;                                             // [0, 399]
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;      // [0, 365]
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;     // [0, 146096]

        return $era * 146097 + $doe - 719468;
    }

    /* =====================================================================
     | XML safety
     ===================================================================== */

    /** XML prologue shared by every part. */
    private const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

    /**
     * Escape a value for XML text content, after removing everything XML 1.0
     * cannot represent. One stray control byte inside a portfolio text must
     * never be able to corrupt the workbook.
     */
    private static function escape(string $value): string
    {
        $value = self::filterXmlChars($value);

        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    /** Escape a value for an XML attribute. */
    private static function escapeAttribute(string $value): string
    {
        return str_replace(['"', "'"], ['&quot;', '&apos;'], self::escape($value));
    }

    /**
     * Drop characters that are illegal in XML 1.0: the C0 controls except tab,
     * line feed and carriage return, plus U+FFFE/U+FFFF and lone surrogates.
     * Carriage returns are normalised away because an XML parser would rewrite
     * them anyway.
     */
    private static function filterXmlChars(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Invalid UTF-8 would make the /u regex below fail outright.
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            $value = function_exists('mb_scrub')
                ? mb_scrub($value, 'UTF-8')
                : (string) preg_replace('/[\x80-\xFF]/', '', $value);
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);

        $filtered = preg_replace(
            '/[^\x{0009}\x{000A}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            '',
            $value
        );

        if ($filtered === null) {
            // Last resort for input the regex engine refused: byte filtering.
            $filtered = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        }

        return $filtered;
    }

    /* =====================================================================
     | The static package parts
     ===================================================================== */

    private function contentTypesXml(): string
    {
        return self::XML_DECLARATION
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml"'
            . ' ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml"'
            . ' ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return self::XML_DECLARATION
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties"'
            . ' Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties"'
            . ' Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        return self::XML_DECLARATION
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<fileVersion appName="xl" lastEdited="5" lowestEdited="5" rupBuild="9302"/>'
            . '<workbookPr date1904="0" defaultThemeVersion="124226"/>'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="20490" windowHeight="9645"'
            . ' activeTab="0"/></bookViews>'
            . '<sheets><sheet name="' . self::escapeAttribute($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '<calcPr calcId="124519" fullCalcOnLoad="1"/>'
            . '</workbook>';
    }

    private function workbookRelsXml(): string
    {
        return self::XML_DECLARATION
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * The style sheet.
     *
     * A malformed styles part is the classic cause of Excel's "unreadable
     * content" dialog, so the whole chain is spelled out: numFmts, fonts,
     * fills (index 0 must be `none` and index 1 `gray125` — Excel insists),
     * borders, cellStyleXfs and finally cellXfs, whose order defines the
     * STYLE_* constants above.
     */
    private function stylesXml(): string
    {
        return self::XML_DECLARATION
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1">'
            . '<numFmt numFmtId="164" formatCode="yyyy\-mm\-dd\ hh:mm"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/>'
            . '<scheme val="minor"/></font>'
            . '<font><b/><sz val="11"/><color rgb="' . self::HEADER_COLOR . '"/><name val="Calibri"/>'
            . '<family val="2"/><scheme val="minor"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid">'
            . '<fgColor rgb="' . self::HEADER_FILL . '"/><bgColor indexed="64"/>'
            . '</patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FF1B3A66"/></left>'
            . '<right style="thin"><color rgb="FF1B3A66"/></right>'
            . '<top style="thin"><color rgb="FF1B3A66"/></top>'
            . '<bottom style="thin"><color rgb="FF1B3A66"/></bottom>'
            . '<diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            . '</cellStyleXfs>'
            . '<cellXfs count="5">'
            /* 0 — default */
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            /* 1 — header: bold white on navy, centred, wrapped */
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1"'
            . ' applyBorder="1" applyAlignment="1">'
            . '<alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            /* 2 — body text, wrapped and top aligned */
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            . '<alignment vertical="top" wrapText="1"/></xf>'
            /* 3 — date */
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"'
            . ' applyAlignment="1"><alignment horizontal="left" vertical="top"/></xf>'
            /* 4 — number */
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1">'
            . '<alignment horizontal="right" vertical="top"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/>'
            . '<tableStyles count="0" defaultTableStyle="TableStyleMedium9" defaultPivotStyle="PivotStyleLight16"/>'
            . '</styleSheet>';
    }

    private function coreXml(): string
    {
        $now   = gmdate('Y-m-d\TH:i:s\Z');
        $title = self::escape($this->sheetName);

        return self::XML_DECLARATION
            . '<cp:coreProperties'
            . ' xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:dcmitype="http://purl.org/dc/dcmitype/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title>'
            . '<dc:creator>Andijon AI Talents</dc:creator>'
            . '<cp:lastModifiedBy>Andijon AI Talents</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appXml(): string
    {
        $sheet = self::escape($this->sheetName);

        return self::XML_DECLARATION
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Andijon AI Talents</Application>'
            . '<DocSecurity>0</DocSecurity>'
            . '<ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant">'
            . '<vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant>'
            . '<vt:variant><vt:i4>1</vt:i4></vt:variant>'
            . '</vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="1" baseType="lpstr">'
            . '<vt:lpstr>' . $sheet . '</vt:lpstr>'
            . '</vt:vector></TitlesOfParts>'
            . '<Company></Company>'
            . '<LinksUpToDate>false</LinksUpToDate>'
            . '<SharedDoc>false</SharedDoc>'
            . '<HyperlinksChanged>false</HyperlinksChanged>'
            . '<AppVersion>1.0000</AppVersion>'
            . '</Properties>';
    }

    /* =====================================================================
     | ZIP containers
     ===================================================================== */

    /**
     * @param array<int,array{name:string,data:?string,file:?string}> $parts
     */
    private function writeZipWithZipArchive(array $parts, string $path): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('XlsxWriter: ZipArchive could not create ' . $path);
        }

        foreach ($parts as $part) {
            $added = $part['file'] !== null
                ? $zip->addFile($part['file'], $part['name'])
                : $zip->addFromString($part['name'], (string) $part['data']);

            if ($added === false) {
                $zip->close();

                throw new \RuntimeException('XlsxWriter: could not add ' . $part['name'] . ' to the archive.');
            }

            if (method_exists($zip, 'setCompressionName')) {
                $zip->setCompressionName($part['name'], \ZipArchive::CM_DEFLATE);
            }
        }

        if ($zip->close() === false) {
            throw new \RuntimeException('XlsxWriter: ZipArchive failed to finalise ' . $path);
        }
    }

    /**
     * Pure PHP ZIP writer for hosts without ext-zip.
     *
     * Writes a plain ZIP 2.0 archive: one local file header plus data per
     * entry, then the central directory and the end-of-central-directory
     * record. No data descriptors (sizes are known up front), no encryption
     * and no ZIP64, which caps an archive at 4 GiB and 65 535 entries — a
     * spreadsheet export never comes anywhere near either limit.
     *
     * @param array<int,array{name:string,data:?string,file:?string}> $parts
     */
    private function writeZipNative(array $parts, string $path): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new \RuntimeException('XlsxWriter: cannot open ' . $path . ' for writing.');
        }

        $stamp   = self::dosTimestamp(time());
        $dosTime = $stamp[0];
        $dosDate = $stamp[1];

        $central = '';
        $offset  = 0;
        $entries = 0;

        try {
            foreach ($parts as $part) {
                $name = $part['name'];

                if ($part['file'] !== null) {
                    $data = file_get_contents($part['file']);

                    if ($data === false) {
                        throw new \RuntimeException('XlsxWriter: cannot read ' . $part['file']);
                    }
                } else {
                    $data = (string) $part['data'];
                }

                $size   = strlen($data);
                $crc    = crc32($data);
                $method = 0;

                if ($size > 0 && function_exists('gzdeflate')) {
                    $deflated = gzdeflate($data, 6);

                    // STORE whenever deflate is unavailable or does not pay off.
                    if (is_string($deflated) && strlen($deflated) < $size) {
                        $data   = $deflated;
                        $method = 8;
                    }
                }

                $packed = strlen($data);

                $local = "PK\x03\x04"
                    . pack('v', 20)          // version needed to extract: 2.0
                    . pack('v', 0)           // general purpose flags
                    . pack('v', $method)
                    . pack('v', $dosTime)
                    . pack('v', $dosDate)
                    . pack('V', $crc)
                    . pack('V', $packed)
                    . pack('V', $size)
                    . pack('v', strlen($name))
                    . pack('v', 0)           // extra field length
                    . $name;

                fwrite($handle, $local);
                fwrite($handle, $data);

                $central .= "PK\x01\x02"
                    . pack('v', 20)          // version made by: 2.0, MS-DOS
                    . pack('v', 20)          // version needed to extract
                    . pack('v', 0)
                    . pack('v', $method)
                    . pack('v', $dosTime)
                    . pack('v', $dosDate)
                    . pack('V', $crc)
                    . pack('V', $packed)
                    . pack('V', $size)
                    . pack('v', strlen($name))
                    . pack('v', 0)           // extra field length
                    . pack('v', 0)           // file comment length
                    . pack('v', 0)           // disk number start
                    . pack('v', 0)           // internal file attributes
                    . pack('V', 0)           // external file attributes
                    . pack('V', $offset)
                    . $name;

                $offset += strlen($local) + $packed;
                $entries++;
            }

            fwrite($handle, $central);
            fwrite(
                $handle,
                "PK\x05\x06"
                . pack('v', 0)               // this disk
                . pack('v', 0)               // disk holding the central directory
                . pack('v', $entries)        // entries on this disk
                . pack('v', $entries)        // entries in total
                . pack('V', strlen($central))
                . pack('V', $offset)
                . pack('v', 0)               // archive comment length
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * MS-DOS time and date halves of a unix timestamp.
     *
     * @return array{0:int,1:int} [time, date]
     */
    private static function dosTimestamp(int $timestamp): array
    {
        $year = (int) date('Y', $timestamp);

        if ($year < 1980) {
            $timestamp = mktime(0, 0, 0, 1, 1, 1980);
            $timestamp = $timestamp === false ? 315532800 : $timestamp;
            $year      = 1980;
        }

        $time = ((int) date('G', $timestamp) << 11)
            | ((int) date('i', $timestamp) << 5)
            | intdiv((int) date('s', $timestamp), 2);

        $date = (($year - 1980) << 9)
            | ((int) date('n', $timestamp) << 5)
            | (int) date('j', $timestamp);

        return [$time & 0xFFFF, $date & 0xFFFF];
    }

    /** A fresh temporary file, or an exception when the temp dir is unusable. */
    private static function tempFile(string $prefix): string
    {
        $file = tempnam(sys_get_temp_dir(), $prefix);

        if ($file === false) {
            throw new \RuntimeException('XlsxWriter: cannot create a temporary file.');
        }

        return $file;
    }

    /** Keep a download name harmless (no path separators, always .xlsx). */
    private static function sanitiseFilename(string $filename): string
    {
        $filename = self::filterXmlChars($filename);
        $filename = str_replace(["\n", "\t", '"', '\\', '/'], '-', $filename);
        $filename = trim($filename);

        if ($filename === '') {
            $filename = 'export.xlsx';
        }

        if (substr(strtolower($filename), -5) !== '.xlsx') {
            $filename .= '.xlsx';
        }

        return $filename;
    }
}
