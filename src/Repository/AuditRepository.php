<?php

declare(strict_types=1);

namespace AiTalents\Repository;

use AiTalents\App;
use AiTalents\Database;

/**
 * Append-only trail of everything an administrator does.
 *
 * `actor` is a short label of who acted ("panel:admin", "tg:12345", "cli"),
 * `action` what happened ("registration.approve", "login.fail") and `target`
 * the object it happened to ("registration:42"). `meta` holds arbitrary JSON.
 *
 * Writing to this table must never break the operation it is documenting, so
 * {@see self::log()} swallows every error.
 */
final class AuditRepository
{
    /** Columns that may be used in ORDER BY. */
    private const SORTABLE = ['id', 'created_at', 'actor', 'action'];

    /** Column widths enforced before writing. */
    private const LENGTHS = [
        'actor'  => 64,
        'action' => 64,
        'target' => 64,
        'ip'     => 45,
    ];

    /** Escape character used by the LIKE filters. */
    private const LIKE_ESCAPE = '!';

    public function __construct(private Database $db)
    {
    }

    /**
     * Record one event. Never throws — a failing audit write is logged nowhere
     * and simply dropped, because losing the trail is better than losing the
     * user's action.
     *
     * @param array<string,mixed> $meta
     */
    public function log(string $actor, string $action, ?string $target = null, array $meta = [], ?string $ip = null): void
    {
        try {
            $actor = $this->clip($actor, self::LENGTHS['actor']);
            $action = $this->clip($action, self::LENGTHS['action']);

            if ($actor === '' || $action === '') {
                return;
            }

            $this->db->insert('audit_log', [
                'actor'      => $actor,
                'action'     => $action,
                'target'     => $target === null ? null : ($this->clip($target, self::LENGTHS['target']) ?: null),
                'meta'       => $this->encodeMeta($meta),
                'ip'         => $ip === null ? null : ($this->clip($ip, self::LENGTHS['ip']) ?: null),
                'created_at' => App::now(),
            ]);
        } catch (\Throwable $e) {
            // Intentionally silent: the audit log must never break a request.
        }
    }

    /**
     * One page of the trail, newest first.
     *
     * Filters: actor, action, target, ip (all exact), q (free text over actor/
     * action/target), date_from, date_to.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function paginate(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);

        $sort = $this->sortColumn((string) ($filters['sort'] ?? 'created_at'));
        $dir = $this->sortDirection((string) ($filters['dir'] ?? 'desc'));

        $sql = 'SELECT * FROM ' . $this->audit() . $where
            . ' ORDER BY ' . $this->q($sort) . ' ' . $dir . ', ' . $this->q('id') . ' DESC'
            // Clamped integers — inlined so LIMIT works on both drivers.
            . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);

        $rows = $this->db->fetchAll($sql, $params);
        $decoded = [];

        foreach ($rows as $row) {
            $decoded[] = $this->decode($row);
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function countAll(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->audit() . $where,
            $params
        );
    }

    /**
     * Delete entries older than $days days; returns how many rows went away.
     *
     * The minimum is one day so a wrong argument can never wipe the table.
     */
    public function purgeOlderThan(int $days): int
    {
        $days = max(1, $days);
        $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));

        return $this->db->query(
            'DELETE FROM ' . $this->audit() . ' WHERE ' . $this->q('created_at') . ' < ?',
            [$cutoff]
        )->rowCount();
    }

    /**
     * The trail of one object, e.g. forTarget('registration:42').
     *
     * @return array<int,array<string,mixed>>
     */
    public function forTarget(string $target, int $limit = 50): array
    {
        $target = trim($target);

        if ($target === '') {
            return [];
        }

        return $this->paginate(['target' => $target], $limit, 0);
    }

    /**
     * Distinct action names, handy for the filter dropdown of the audit page.
     *
     * @return string[]
     */
    public function actions(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));

        $rows = $this->db->fetchAll(
            'SELECT DISTINCT ' . $this->q('action') . ' FROM ' . $this->audit()
            . ' ORDER BY ' . $this->q('action') . ' ASC LIMIT ' . $limit
        );

        $actions = [];

        foreach ($rows as $row) {
            $action = trim((string) ($row['action'] ?? ''));

            if ($action !== '') {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    public function total(): int
    {
        return $this->db->count('audit_log');
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        foreach (['actor', 'action', 'target', 'ip'] as $column) {
            $value = $this->nullableString($filters[$column] ?? null);

            if ($value === null) {
                continue;
            }

            $conditions[] = $this->q($column) . ' = ?';
            $params[] = mb_substr($value, 0, self::LENGTHS[$column], 'UTF-8');
        }

        $search = $this->nullableString($filters['q'] ?? null);

        if ($search !== null) {
            $like = $this->likeParam($search);
            $escape = ' ESCAPE \'' . self::LIKE_ESCAPE . '\'';

            $conditions[] = '('
                . 'LOWER(' . $this->q('actor') . ') LIKE ?' . $escape
                . ' OR LOWER(' . $this->q('action') . ') LIKE ?' . $escape
                . ' OR LOWER(' . $this->q('target') . ') LIKE ?' . $escape
                . ')';

            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $from = $this->dateBoundary($filters['date_from'] ?? null, false);

        if ($from !== null) {
            $conditions[] = $this->q('created_at') . ' >= ?';
            $params[] = $from;
        }

        $to = $this->dateBoundary($filters['date_to'] ?? null, true);

        if ($to !== null) {
            $conditions[] = $this->q('created_at') . ' <= ?';
            $params[] = $to;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);

        $meta = $row['meta'] ?? null;

        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            $row['meta'] = is_array($decoded) ? $decoded : ['raw' => $meta];
        } else {
            $row['meta'] = [];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function encodeMeta(array $meta): ?string
    {
        if ($meta === []) {
            return null;
        }

        $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_string($json)) {
            return null;
        }

        // The column is TEXT; keep a runaway payload from filling the table.
        return mb_substr($json, 0, 4000, 'UTF-8');
    }

    /**
     * Normalize a date filter into a comparable 'Y-m-d H:i:s' string.
     */
    private function dateBoundary(mixed $value, bool $endOfDay): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(:\d{2})?$/', $value, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5]
                . ($m[6] ?? ($endOfDay ? ':59' : ':00'));
        }

        return null;
    }

    private function likeParam(string $value): string
    {
        $escaped = str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            mb_strtolower($value, 'UTF-8')
        );

        return '%' . $escaped . '%';
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function clip(string $value, int $max): string
    {
        return mb_substr(trim($value), 0, $max, 'UTF-8');
    }

    private function sortColumn(string $sort): string
    {
        $sort = strtolower(trim($sort));

        return in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
    }

    private function sortDirection(string $dir): string
    {
        return strtolower(trim($dir)) === 'asc' ? 'ASC' : 'DESC';
    }

    /** Quoted + prefixed audit_log table. */
    private function audit(): string
    {
        return $this->db->quoteIdent($this->db->table('audit_log'));
    }

    /** Quoted column identifier. */
    private function q(string $column): string
    {
        return $this->db->quoteIdent($column);
    }
}
