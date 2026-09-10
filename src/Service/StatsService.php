<?php

declare(strict_types=1);

namespace AiTalents\Service;

use AiTalents\Database;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;

/**
 * Read-only reporting layer used by the admin dashboard, the `/stats` bot
 * command and the CLI.
 *
 * Design notes:
 *  - Every number leaves this class as a real `int` (PDO hands back strings on
 *    several drivers, so every aggregate is cast explicitly).
 *  - Queries stay portable between MySQL and SQLite. Where the two dialects
 *    differ (date truncation) a tiny driver switch produces the expression;
 *    the `directions` column is a JSON array, so it is aggregated in PHP while
 *    streaming the table in chunks instead of relying on JSON_TABLE/json_each.
 *  - Nothing here throws when the schema is not installed yet: a fresh
 *    deployment must be able to render the dashboard before the first
 *    migration run, so missing tables simply produce zeroes.
 */
final class StatsService
{
    /** Rows pulled per round trip while aggregating the JSON directions column. */
    private const CHUNK = 500;

    /** Upper bound for {@see self::daily()} so a bad caller cannot build a huge array. */
    private const MAX_DAYS = 366;

    /** Number of buckets returned by {@see self::hourly()}. */
    private const HOURS = 24;

    public function __construct(private Database $db)
    {
    }

    /**
     * The headline numbers of the dashboard cards.
     *
     * `week` and `month` are rolling windows (the last 7 and the last 30 days
     * including today), not calendar periods. `conversion` is the share of bot
     * users that finished a registration, in percent with one decimal.
     *
     * @return array{users:int,registrations:int,today:int,yesterday:int,week:int,month:int,
     *               pending:int,approved:int,rejected:int,blocked:int,conversion:float}
     */
    public function overview(): array
    {
        $users   = $this->countUsers();
        $blocked = $this->countUsers(['is_blocked' => 1]);

        $registrations = $this->hasRegistrations()
            ? (int) $this->db->count('registrations')
            : 0;

        $todayStart     = $this->dayStart(0);
        $tomorrowStart  = $this->dayStart(1);
        $yesterdayStart = $this->dayStart(-1);

        $statuses = $this->statusCounts();

        // Guard against a division by zero on a brand new installation.
        $conversion = $users > 0
            ? round($registrations / $users * 100, 1)
            : 0.0;

        return [
            'users'         => $users,
            'registrations' => $registrations,
            'today'         => $this->registrationsBetween($todayStart, $tomorrowStart),
            'yesterday'     => $this->registrationsBetween($yesterdayStart, $todayStart),
            'week'          => $this->registrationsBetween($this->dayStart(-6), $tomorrowStart),
            'month'         => $this->registrationsBetween($this->dayStart(-29), $tomorrowStart),
            'pending'       => $statuses[RegistrationStatus::Pending->value] ?? 0,
            'approved'      => $statuses[RegistrationStatus::Approved->value] ?? 0,
            'rejected'      => $statuses[RegistrationStatus::Rejected->value] ?? 0,
            'blocked'       => $blocked,
            'conversion'    => (float) $conversion,
        ];
    }

    /**
     * How many candidates picked each direction, most popular first.
     *
     * A registration can carry several directions, so the counts add up to more
     * than the number of registrations. Directions nobody picked are omitted.
     *
     * @return array<int,array{key:string,label_uz:string,label_ru:string,count:int}>
     */
    public function byDirection(): array
    {
        $counts = [];

        foreach ($this->streamDirections() as $keys) {
            foreach ($keys as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return [];
        }

        $ordered = [];

        // Catalogue order first, so ties keep a predictable, human order...
        foreach (Catalog::directionKeys() as $key) {
            if (isset($counts[$key])) {
                $ordered[$key] = $counts[$key];
                unset($counts[$key]);
            }
        }

        // ...then whatever unknown keys survived from an older catalogue.
        ksort($counts);

        foreach ($counts as $key => $count) {
            $ordered[$key] = $count;
        }

        $rows = [];

        foreach ($ordered as $key => $count) {
            $rows[] = [
                'key'      => (string) $key,
                'label_uz' => Catalog::directionLabel((string) $key, 'uz', false),
                'label_ru' => Catalog::directionLabel((string) $key, 'ru', false),
                'count'    => (int) $count,
            ];
        }

        return $this->sortByCount($rows);
    }

    /**
     * Registrations per city/district, most populated first.
     *
     * Rows without a district (the step can be switched off in the config) are
     * not counted.
     *
     * @return array<int,array{key:string,label_uz:string,label_ru:string,count:int}>
     */
    public function byDistrict(): array
    {
        if (!$this->hasRegistrations()) {
            return [];
        }

        $district = $this->db->quoteIdent('district');

        $sql = 'SELECT ' . $district . ' AS bucket, COUNT(*) AS total'
            . ' FROM ' . $this->registrationsTable()
            . ' WHERE ' . $district . ' IS NOT NULL AND ' . $district . " <> ''"
            . ' GROUP BY ' . $district;

        $rows = [];

        foreach ($this->db->fetchAll($sql) as $row) {
            $key = trim((string) ($row['bucket'] ?? ''));

            if ($key === '') {
                continue;
            }

            $rows[] = [
                'key'      => $key,
                'label_uz' => Catalog::districtLabel($key, 'uz'),
                'label_ru' => Catalog::districtLabel($key, 'ru'),
                'count'    => (int) ($row['total'] ?? 0),
            ];
        }

        return $this->sortByCount($rows);
    }

    /**
     * The moderation funnel. The three known statuses are always present (with a
     * zero count when unused); unexpected values found in the column are
     * appended so nothing silently disappears from the totals.
     *
     * @return array<int,array{key:string,label_uz:string,label_ru:string,count:int}>
     */
    public function byStatus(): array
    {
        $counts = $this->statusCounts();
        $rows   = [];

        foreach (RegistrationStatus::cases() as $status) {
            $rows[] = [
                'key'      => $status->value,
                'label_uz' => Lang::t($status->labelKey(), 'uz'),
                'label_ru' => Lang::t($status->labelKey(), 'ru'),
                'count'    => $counts[$status->value] ?? 0,
            ];

            unset($counts[$status->value]);
        }

        ksort($counts);

        foreach ($counts as $key => $count) {
            $rows[] = [
                'key'      => (string) $key,
                'label_uz' => (string) $key,
                'label_ru' => (string) $key,
                'count'    => (int) $count,
            ];
        }

        return $rows;
    }

    /**
     * Registrations per day for the chart, oldest first.
     *
     * The result always contains exactly `$days` entries ending with today:
     * days without a single registration are filled with a zero so the chart
     * keeps an even horizontal scale.
     *
     * @return array<int,array{date:string,count:int}>
     */
    public function daily(int $days = 14): array
    {
        $days   = max(1, min(self::MAX_DAYS, $days));
        $counts = [];

        if ($this->hasRegistrations()) {
            $expression = $this->dateExpression();
            $createdAt  = $this->db->quoteIdent('created_at');

            $sql = 'SELECT ' . $expression . ' AS bucket, COUNT(*) AS total'
                . ' FROM ' . $this->registrationsTable()
                . ' WHERE ' . $createdAt . ' >= ? AND ' . $createdAt . ' < ?'
                . ' GROUP BY ' . $expression;

            $rows = $this->db->fetchAll($sql, [$this->dayStart(-($days - 1)), $this->dayStart(1)]);

            foreach ($rows as $row) {
                $bucket = (string) ($row['bucket'] ?? '');

                if ($bucket !== '') {
                    $counts[$bucket] = (int) ($row['total'] ?? 0);
                }
            }
        }

        $series = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = date('Y-m-d', $this->dayStamp(-$offset));

            $series[] = [
                'date'  => $date,
                'count' => $counts[$date] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * The last 24 hourly buckets, oldest first and gap filled like {@see self::daily()}.
     *
     * `hour` is the sortable bucket key (`Y-m-d H`), `label` the short axis
     * caption (`H:00`).
     *
     * @return array<int,array{hour:string,label:string,count:int}>
     */
    public function hourly(): array
    {
        $counts = [];

        if ($this->hasRegistrations()) {
            $expression = $this->hourExpression();
            $createdAt  = $this->db->quoteIdent('created_at');

            $sql = 'SELECT ' . $expression . ' AS bucket, COUNT(*) AS total'
                . ' FROM ' . $this->registrationsTable()
                . ' WHERE ' . $createdAt . ' >= ? AND ' . $createdAt . ' < ?'
                . ' GROUP BY ' . $expression;

            $from = date('Y-m-d H:00:00', $this->hourStamp(-(self::HOURS - 1)));
            $to   = date('Y-m-d H:00:00', $this->hourStamp(1));

            foreach ($this->db->fetchAll($sql, [$from, $to]) as $row) {
                $bucket = (string) ($row['bucket'] ?? '');

                if ($bucket !== '') {
                    $counts[$bucket] = (int) ($row['total'] ?? 0);
                }
            }
        }

        $series = [];

        for ($offset = self::HOURS - 1; $offset >= 0; $offset--) {
            $stamp  = $this->hourStamp(-$offset);
            $bucket = date('Y-m-d H', $stamp);

            $series[] = [
                'hour'  => $bucket,
                'label' => date('H:00', $stamp),
                'count' => $counts[$bucket] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * The busiest day since the programme started, or null when nobody has
     * registered yet.
     *
     * @return ?array{date:string,count:int}
     */
    public function topDay(): ?array
    {
        if (!$this->hasRegistrations()) {
            return null;
        }

        $expression = $this->dateExpression();

        $sql = 'SELECT ' . $expression . ' AS bucket, COUNT(*) AS total'
            . ' FROM ' . $this->registrationsTable()
            . ' GROUP BY ' . $expression
            . ' ORDER BY total DESC, bucket DESC'
            . ' LIMIT 1';

        $row = $this->db->fetch($sql);

        if ($row === null) {
            return null;
        }

        $date = (string) ($row['bucket'] ?? '');

        if ($date === '') {
            return null;
        }

        return [
            'date'  => $date,
            'count' => (int) ($row['total'] ?? 0),
        ];
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Yield the decoded `directions` array of every registration, reading the
     * table in keyset paginated chunks so the memory profile stays flat even
     * with a hundred thousand applications.
     *
     * @return \Generator<int,string[]>
     */
    private function streamDirections(): \Generator
    {
        if (!$this->hasRegistrations()) {
            return;
        }

        $id     = $this->db->quoteIdent('id');
        $column = $this->db->quoteIdent('directions');
        $table  = $this->registrationsTable();

        $sql = 'SELECT ' . $id . ' AS id, ' . $column . ' AS directions'
            . ' FROM ' . $table
            . ' WHERE ' . $id . ' > ?'
            . ' ORDER BY ' . $id . ' ASC'
            . ' LIMIT ' . self::CHUNK;

        $lastId = 0;

        do {
            $rows = $this->db->fetchAll($sql, [$lastId]);

            foreach ($rows as $row) {
                $lastId = (int) ($row['id'] ?? 0);

                yield $this->decodeDirections($row['directions'] ?? null);
            }
        } while (count($rows) === self::CHUNK);
    }

    /**
     * Turn the stored JSON array into a list of unique, non-empty string keys.
     *
     * @return string[]
     */
    private function decodeDirections(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!is_array($raw)) {
            return [];
        }

        $keys = [];

        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);

            // A duplicate inside one registration must not count twice.
            if ($value !== '' && !in_array($value, $keys, true)) {
                $keys[] = $value;
            }
        }

        return $keys;
    }

    /**
     * Registrations grouped by their raw status value.
     *
     * @return array<string,int>
     */
    private function statusCounts(): array
    {
        if (!$this->hasRegistrations()) {
            return [];
        }

        $status = $this->db->quoteIdent('status');

        $sql = 'SELECT ' . $status . ' AS bucket, COUNT(*) AS total'
            . ' FROM ' . $this->registrationsTable()
            . ' GROUP BY ' . $status;

        $counts = [];

        foreach ($this->db->fetchAll($sql) as $row) {
            $key = trim((string) ($row['bucket'] ?? ''));

            if ($key !== '') {
                $counts[$key] = (int) ($row['total'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * Registrations created in `[$from, $to)`; both bounds are optional.
     */
    private function registrationsBetween(?string $from, ?string $to): int
    {
        if (!$this->hasRegistrations()) {
            return 0;
        }

        $createdAt  = $this->db->quoteIdent('created_at');
        $conditions = [];
        $params     = [];

        if ($from !== null) {
            $conditions[] = $createdAt . ' >= ?';
            $params[]     = $from;
        }

        if ($to !== null) {
            $conditions[] = $createdAt . ' < ?';
            $params[]     = $to;
        }

        $sql = 'SELECT COUNT(*) FROM ' . $this->registrationsTable();

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        return (int) $this->db->fetchColumn($sql, $params);
    }

    /**
     * @param array<string,mixed> $where
     */
    private function countUsers(array $where = []): int
    {
        if (!$this->hasTable('users')) {
            return 0;
        }

        return (int) $this->db->count('users', $where);
    }

    /**
     * Sort a `['count' => int]` list descending. PHP's sort is stable since 8.0,
     * so rows with an equal count keep the order they were built in.
     *
     * @param array<int,array{key:string,label_uz:string,label_ru:string,count:int}> $rows
     *
     * @return array<int,array{key:string,label_uz:string,label_ru:string,count:int}>
     */
    private function sortByCount(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $rows;
    }

    /** SQL expression truncating `created_at` to `YYYY-MM-DD`. */
    private function dateExpression(): string
    {
        $column = $this->db->quoteIdent('created_at');

        return $this->db->driver() === 'mysql'
            ? 'DATE(' . $column . ')'
            : 'substr(' . $column . ', 1, 10)';
    }

    /** SQL expression truncating `created_at` to `YYYY-MM-DD HH`. */
    private function hourExpression(): string
    {
        $column = $this->db->quoteIdent('created_at');

        return $this->db->driver() === 'mysql'
            ? "DATE_FORMAT(" . $column . ", '%Y-%m-%d %H')"
            : 'substr(' . $column . ', 1, 13)';
    }

    /** Fully qualified, quoted `registrations` table name. */
    private function registrationsTable(): string
    {
        return $this->db->quoteIdent($this->db->table('registrations'));
    }

    private function hasRegistrations(): bool
    {
        return $this->hasTable('registrations');
    }

    /** Never let a missing table (or a dead connection) break the dashboard. */
    private function hasTable(string $table): bool
    {
        try {
            return $this->db->tableExists($table);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Midnight of "today + $offsetDays" as `Y-m-d H:i:s`. */
    private function dayStart(int $offsetDays): string
    {
        return date('Y-m-d H:i:s', $this->dayStamp($offsetDays));
    }

    /** Unix timestamp of midnight, `$offsetDays` away from today. */
    private function dayStamp(int $offsetDays): int
    {
        $stamp = mktime(0, 0, 0, (int) date('n'), (int) date('j') + $offsetDays, (int) date('Y'));

        return $stamp === false ? time() : $stamp;
    }

    /** Unix timestamp of the full hour, `$offsetHours` away from the current one. */
    private function hourStamp(int $offsetHours): int
    {
        $stamp = mktime((int) date('G') + $offsetHours, 0, 0, (int) date('n'), (int) date('j'), (int) date('Y'));

        return $stamp === false ? time() : $stamp;
    }
}
