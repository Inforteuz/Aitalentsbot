<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Minimal daily-file logger.
 *
 * Files are written as {dir}/bot-Y-m-d.log and old files are pruned so that at
 * most $maxFiles remain. The logger NEVER throws: a broken/unwritable log
 * directory must never take the bot down.
 */
final class Logger
{
    /** Severity ranking used by the level filter. */
    public const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /** File name pattern of a daily log file. */
    private const FILE_PREFIX = 'bot-';
    private const FILE_SUFFIX = '.log';

    /** Guards purgeOld() so it runs at most once per request. */
    private bool $purged = false;

    public function __construct(
        private string $dir,
        private string $level = 'info',
        private bool $enabled = true,
        private int $maxFiles = 14
    ) {
        $this->dir = rtrim($this->dir, "/\\");
        $this->level = $this->normalizeLevel($this->level);
    }

    public function debug(string $m, array $c = []): void
    {
        $this->log('debug', $m, $c);
    }

    public function info(string $m, array $c = []): void
    {
        $this->log('info', $m, $c);
    }

    public function warning(string $m, array $c = []): void
    {
        $this->log('warning', $m, $c);
    }

    public function error(string $m, array $c = []): void
    {
        $this->log('error', $m, $c);
    }

    /**
     * Append one line to today's log file. Silently gives up on any failure.
     *
     * @param array<string,mixed> $c
     */
    public function log(string $level, string $m, array $c = []): void
    {
        try {
            if (!$this->enabled || $this->dir === '') {
                return;
            }

            $level = $this->normalizeLevel($level);

            if (self::LEVELS[$level] < self::LEVELS[$this->level]) {
                return;
            }

            if (!$this->ensureDir()) {
                return;
            }

            $file = $this->path();
            $isNewFile = !is_file($file);

            $line = sprintf(
                '[%s] %s: %s%s%s',
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $this->singleLine($m),
                $c === [] ? '' : ' ' . $this->encodeContext($c),
                PHP_EOL
            );

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

            // Rotate only when a new day started (cheap: once per request at most).
            if ($isNewFile && !$this->purged) {
                $this->purgeOld();
            }
        } catch (\Throwable $e) {
            // Logging must never break the caller.
        }
    }

    /**
     * Last $lines lines of a day's log file, oldest first (newest last).
     *
     * @param ?string $date 'Y-m-d'; defaults to today
     * @return string[]
     */
    public function tail(int $lines = 200, ?string $date = null): array
    {
        try {
            $lines = max(1, $lines);
            $file = $this->path($date);

            if (!is_file($file) || !is_readable($file)) {
                return [];
            }

            $size = (int) @filesize($file);

            if ($size <= 0) {
                return [];
            }

            $handle = @fopen($file, 'rb');

            if ($handle === false) {
                return [];
            }

            // Read backwards in chunks until enough newlines were collected.
            $chunkSize = 8192;
            $position = $size;
            $buffer = '';
            $found = 0;

            while ($position > 0 && $found <= $lines) {
                $read = (int) min($chunkSize, $position);
                $position -= $read;

                if (fseek($handle, $position) !== 0) {
                    break;
                }

                $chunk = (string) fread($handle, $read);
                $buffer = $chunk . $buffer;
                $found = substr_count($buffer, "\n");
            }

            fclose($handle);

            $all = preg_split('/\r\n|\n|\r/', rtrim($buffer, "\r\n")) ?: [];

            if (count($all) > $lines) {
                $all = array_slice($all, -$lines);
            }

            return array_values(array_filter($all, static fn (string $l): bool => $l !== ''));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Available log dates, newest first.
     *
     * @return string[] e.g. ['2026-09-10', '2026-09-09']
     */
    public function files(): array
    {
        try {
            $dates = [];

            foreach ($this->logFiles() as $file) {
                $date = $this->dateOf($file);

                if ($date !== null) {
                    $dates[] = $date;
                }
            }

            rsort($dates, SORT_STRING);

            return $dates;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Delete the oldest files so that at most $maxFiles remain.
     */
    public function purgeOld(): void
    {
        try {
            $this->purged = true;

            if ($this->maxFiles <= 0) {
                return; // keep everything
            }

            $files = $this->logFiles();

            // logFiles() is sorted oldest first — drop everything above the cap.
            $excess = count($files) - $this->maxFiles;

            for ($i = 0; $i < $excess; $i++) {
                @unlink($files[$i]);
            }
        } catch (\Throwable $e) {
            // Never throw from the logger.
        }
    }

    /**
     * Absolute path of a day's log file (today when $date is null).
     * The date is validated, so it is safe to pass user input here.
     */
    public function path(?string $date = null): string
    {
        $date = $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? $date
            : date('Y-m-d');

        return $this->dir . '/' . self::FILE_PREFIX . $date . self::FILE_SUFFIX;
    }

    /**
     * The configured log directory (without a trailing separator).
     */
    public function dir(): string
    {
        return $this->dir;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function level(): string
    {
        return $this->level;
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * All existing log files, oldest first.
     *
     * @return string[] absolute paths
     */
    private function logFiles(): array
    {
        if ($this->dir === '' || !is_dir($this->dir)) {
            return [];
        }

        $pattern = $this->dir . '/' . self::FILE_PREFIX . '*' . self::FILE_SUFFIX;
        $files = glob($pattern);

        if ($files === false) {
            return [];
        }

        // File names carry the date, so a plain string sort is chronological.
        $files = array_values(array_filter($files, fn (string $f): bool => $this->dateOf($f) !== null));
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Extract the 'Y-m-d' part from a log file path, or null when it does not match.
     */
    private function dateOf(string $file): ?string
    {
        $name = basename($file);
        $regex = '/^' . preg_quote(self::FILE_PREFIX, '/') . '(\d{4}-\d{2}-\d{2})'
            . preg_quote(self::FILE_SUFFIX, '/') . '$/';

        return preg_match($regex, $name, $m) === 1 ? $m[1] : null;
    }

    private function ensureDir(): bool
    {
        if (is_dir($this->dir)) {
            return true;
        }

        // @-suppressed: a concurrent request may create it first.
        @mkdir($this->dir, 0775, true);

        return is_dir($this->dir);
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtolower(trim($level));

        return isset(self::LEVELS[$level]) ? $level : 'info';
    }

    /**
     * Keep one log entry on one line.
     */
    private function singleLine(string $message): string
    {
        $message = str_replace(["\r\n", "\r", "\n"], ' ', $message);
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? $message;

        return trim($message);
    }

    /**
     * JSON encode the context, converting exceptions and objects into scalars.
     *
     * @param array<string,mixed> $context
     */
    private function encodeContext(array $context): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_PARTIAL_OUTPUT_ON_ERROR;

        $json = json_encode($this->normalizeContext($context), $flags);

        if (!is_string($json)) {
            return '{}';
        }

        return $this->singleLine($json);
    }

    /**
     * @param array<mixed> $context
     * @return array<mixed>
     */
    private function normalizeContext(array $context, int $depth = 0): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            if ($value instanceof \Throwable) {
                $out[$key] = [
                    'class'   => get_class($value),
                    'message' => $value->getMessage(),
                    'code'    => $value->getCode(),
                    'file'    => $value->getFile() . ':' . $value->getLine(),
                ];
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $depth >= 4 ? '[...]' : $this->normalizeContext($value, $depth + 1);
                continue;
            }

            if (is_object($value)) {
                $out[$key] = method_exists($value, '__toString')
                    ? (string) $value
                    : get_class($value);
                continue;
            }

            if (is_resource($value)) {
                $out[$key] = 'resource';
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
