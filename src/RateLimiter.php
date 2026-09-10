<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Fixed-window rate limiter backed by the "rate_limits" table.
 *
 * One row per (telegram_id, bucket): it stores the hit counter and the unix
 * timestamp the current window started at. When the window is older than
 * $perSeconds it is reset.
 *
 * The limiter always fails OPEN: if the table is missing or the database is
 * unhappy, allow() returns true so the bot keeps answering users.
 */
final class RateLimiter
{
    /** Cached availability of the rate_limits table for this request. */
    private ?bool $available = null;

    public function __construct(
        private Database $db,
        private bool $enabled = true,
        private int $max = 20,
        private int $perSeconds = 60
    ) {
        $this->max = max(1, $this->max);
        $this->perSeconds = max(1, $this->perSeconds);
    }

    /**
     * Count one hit and tell the caller whether it is still within the limit.
     */
    public function allow(int $telegramId, string $bucket = 'default'): bool
    {
        if (!$this->enabled || !$this->available()) {
            return true;
        }

        $bucket = $this->normalizeBucket($bucket);
        $now = time();

        try {
            $row = $this->row($telegramId, $bucket);

            // First hit ever for this bucket.
            if ($row === null) {
                return $this->createWindow($telegramId, $bucket, $now);
            }

            $startedAt = (int) ($row['window_started_at'] ?? 0);
            $hits = (int) ($row['hits'] ?? 0);

            // Window expired (or a clock jump) — start a fresh one.
            if ($now - $startedAt >= $this->perSeconds || $startedAt > $now) {
                $this->db->update(
                    'rate_limits',
                    ['hits' => 1, 'window_started_at' => $now],
                    ['telegram_id' => $telegramId, 'bucket' => $bucket]
                );

                return true;
            }

            // Over the limit: do not grow the counter any further.
            if ($hits >= $this->max) {
                return false;
            }

            $this->db->update(
                'rate_limits',
                ['hits' => $hits + 1],
                ['telegram_id' => $telegramId, 'bucket' => $bucket]
            );

            return true;
        } catch (\Throwable $e) {
            return true; // never block a user because of an infrastructure hiccup
        }
    }

    /**
     * Drop the window for this user/bucket.
     */
    public function reset(int $telegramId, string $bucket = 'default'): void
    {
        if (!$this->available()) {
            return;
        }

        try {
            $this->db->delete('rate_limits', [
                'telegram_id' => $telegramId,
                'bucket'      => $this->normalizeBucket($bucket),
            ]);
        } catch (\Throwable $e) {
            // Ignore: resetting a counter is never critical.
        }
    }

    /**
     * How many hits are left in the current window.
     */
    public function remaining(int $telegramId, string $bucket = 'default'): int
    {
        if (!$this->enabled || !$this->available()) {
            return $this->max;
        }

        try {
            $row = $this->row($telegramId, $this->normalizeBucket($bucket));

            if ($row === null) {
                return $this->max;
            }

            $startedAt = (int) ($row['window_started_at'] ?? 0);

            if (time() - $startedAt >= $this->perSeconds || $startedAt > time()) {
                return $this->max;
            }

            return max(0, $this->max - (int) ($row['hits'] ?? 0));
        } catch (\Throwable $e) {
            return $this->max;
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function max(): int
    {
        return $this->max;
    }

    public function perSeconds(): int
    {
        return $this->perSeconds;
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * @return ?array<string,mixed>
     */
    private function row(int $telegramId, string $bucket): ?array
    {
        $sql = 'SELECT ' . $this->db->quoteIdent('hits') . ', '
            . $this->db->quoteIdent('window_started_at')
            . ' FROM ' . $this->db->quoteIdent($this->db->table('rate_limits'))
            . ' WHERE ' . $this->db->quoteIdent('telegram_id') . ' = ?'
            . ' AND ' . $this->db->quoteIdent('bucket') . ' = ? LIMIT 1';

        return $this->db->fetch($sql, [$telegramId, $bucket]);
    }

    /**
     * Insert the first hit of a window. A concurrent request may have inserted
     * the same row already — in that case fall back to counting a hit on it.
     */
    private function createWindow(int $telegramId, string $bucket, int $now): bool
    {
        try {
            $this->db->insert('rate_limits', [
                'telegram_id'       => $telegramId,
                'bucket'            => $bucket,
                'hits'              => 1,
                'window_started_at' => $now,
            ]);

            return true;
        } catch (\PDOException $e) {
            // Unique key violation from a parallel update — count on the existing row.
            $this->db->query(
                'UPDATE ' . $this->db->quoteIdent($this->db->table('rate_limits'))
                . ' SET ' . $this->db->quoteIdent('hits') . ' = '
                . $this->db->quoteIdent('hits') . ' + 1'
                . ' WHERE ' . $this->db->quoteIdent('telegram_id') . ' = ?'
                . ' AND ' . $this->db->quoteIdent('bucket') . ' = ?',
                [$telegramId, $bucket]
            );

            $row = $this->row($telegramId, $bucket);

            return $row === null || (int) ($row['hits'] ?? 0) <= $this->max;
        }
    }

    /**
     * Buckets are short identifiers; keep them inside the VARCHAR(32) column.
     */
    private function normalizeBucket(string $bucket): string
    {
        $bucket = preg_replace('/[^A-Za-z0-9_:.-]/', '', $bucket) ?? '';
        $bucket = substr(trim($bucket), 0, 32);

        return $bucket === '' ? 'default' : $bucket;
    }

    private function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }

        try {
            return $this->available = $this->db->tableExists('rate_limits');
        } catch (\Throwable $e) {
            return $this->available = false;
        }
    }
}
