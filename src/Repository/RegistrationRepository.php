<?php

declare(strict_types=1);

namespace AiTalents\Repository;

use AiTalents\App;
use AiTalents\Database;
use AiTalents\Enum\RegistrationStatus;

/**
 * Applications submitted through the bot.
 *
 * There is exactly one registration per Telegram user (unique `telegram_id`),
 * so {@see self::save()} transparently inserts or updates.
 *
 * The two JSON columns — `directions` and `portfolio_links` — are decoded into
 * PHP arrays on every read and encoded on every write, so callers never see raw
 * JSON. Rows are read together with a few columns of the owning user
 * (`username`, `user_first_name`, `user_last_name`, `user_locale`,
 * `user_is_blocked`) which the admin panel needs for links and search.
 */
final class RegistrationRepository
{
    /** Columns that may be used in ORDER BY. */
    private const SORTABLE = ['id', 'created_at', 'full_name', 'district', 'status'];

    /** Columns {@see self::save()} accepts in its $data array. */
    private const WRITABLE = [
        'full_name',
        'phone',
        'birth_year',
        'district',
        'directions',
        'direction_other',
        'portfolio',
        'portfolio_links',
        'status',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
        'source',
    ];

    /** Maximum length of the VARCHAR columns, enforced before writing. */
    private const LENGTHS = [
        'full_name'       => 160,
        'phone'           => 24,
        'district'        => 48,
        'direction_other' => 160,
        'status'          => 16,
        'source'          => 32,
    ];

    /** Escape character used by every LIKE filter. */
    private const LIKE_ESCAPE = '!';

    public function __construct(private Database $db)
    {
    }

    /**
     * @return ?array<string,mixed>
     */
    public function findByTelegramId(int $telegramId): ?array
    {
        if ($telegramId === 0) {
            return null;
        }

        $row = $this->db->fetch(
            $this->selectSql() . ' WHERE r.' . $this->q('telegram_id') . ' = ? LIMIT 1',
            [$telegramId]
        );

        return $row === null ? null : $this->decode($row);
    }

    /**
     * @return ?array<string,mixed>
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->fetch(
            $this->selectSql() . ' WHERE r.' . $this->q('id') . ' = ? LIMIT 1',
            [$id]
        );

        return $row === null ? null : $this->decode($row);
    }

    /**
     * Insert a new application or update the existing one of that Telegram user.
     *
     * Only the keys listed in self::WRITABLE are taken from $data; `directions`
     * and `portfolio_links` accept an array (preferred) or a plain string.
     * An INSERT requires at least a full name and a phone number.
     *
     * @param array<string,mixed> $data
     * @return int the registration id
     */
    public function save(int $userId, int $telegramId, array $data): int
    {
        if ($telegramId === 0) {
            throw new \InvalidArgumentException('RegistrationRepository::save() needs a telegram id.');
        }

        $values = $this->normalizeInput($data);

        /** @var int $id */
        $id = $this->db->transaction(function () use ($userId, $telegramId, $values): int {
            $existing = $this->db->fetch(
                'SELECT ' . $this->q('id') . ' FROM ' . $this->registrations()
                . ' WHERE ' . $this->q('telegram_id') . ' = ? LIMIT 1',
                [$telegramId]
            );

            $now = App::now();

            if ($existing === null) {
                return $this->insertRow($userId, $telegramId, $values, $now);
            }

            $id = (int) $existing['id'];

            if ($userId > 0) {
                $values['user_id'] = $userId;
            }

            $values['updated_at'] = $now;

            $this->db->update('registrations', $values, ['id' => $id]);

            return $id;
        });

        return $id;
    }

    /**
     * Drop the application of one Telegram user.
     */
    public function deleteByTelegramId(int $telegramId): bool
    {
        if ($telegramId === 0) {
            return false;
        }

        return $this->db->delete('registrations', ['telegram_id' => $telegramId]) > 0;
    }

    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        return $this->db->delete('registrations', ['id' => $id]) > 0;
    }

    /**
     * Moderate an application.
     *
     * $actor is a free form label ("panel:admin", "tg:12345"); its numeric part
     * — when there is one — is stored in the BIGINT column `reviewed_by`, the
     * full label belongs in the audit log. Passing $note replaces `admin_note`.
     */
    public function setStatus(int $id, string $status, ?string $actor = null, ?string $note = null): bool
    {
        $status = RegistrationStatus::tryOrNull($status);

        if ($id <= 0 || $status === null) {
            return false;
        }

        $patch = [
            'status'      => $status->value,
            'reviewed_by' => $this->actorId($actor),
            'reviewed_at' => App::now(),
            'updated_at'  => App::now(),
        ];

        if ($note !== null) {
            $note = trim($note);
            $patch['admin_note'] = $note === '' ? null : mb_substr($note, 0, 2000, 'UTF-8');
        }

        return $this->db->update('registrations', $patch, ['id' => $id]) > 0;
    }

    /**
     * One page of applications; pair it with countAll() for the pager.
     *
     * Filters: q, status (string or string[]), district, direction,
     * date_from, date_to (both 'Y-m-d' or 'Y-m-d H:i:s').
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function paginate(
        array $filters = [],
        int $limit = 25,
        int $offset = 0,
        string $sort = 'created_at',
        string $dir = 'desc'
    ): array {
        [$where, $params] = $this->buildFilters($filters);

        $sql = $this->selectSql()
            . $where
            . ' ORDER BY r.' . $this->q($this->sortColumn($sort)) . ' ' . $this->sortDirection($dir)
            . ', r.' . $this->q('id') . ' DESC'
            // Both values are clamped integers — inlining keeps LIMIT portable.
            . ' LIMIT ' . $this->clampLimit($limit) . ' OFFSET ' . $this->clampOffset($offset);

        return $this->decodeAll($this->db->fetchAll($sql, $params));
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function countAll(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->registrations() . ' r'
            . ' LEFT JOIN ' . $this->users() . ' u ON u.' . $this->q('telegram_id')
            . ' = r.' . $this->q('telegram_id')
            . $where,
            $params
        );
    }

    /**
     * Free text search over name, phone, username, id and telegram id.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);

        if ($q === '') {
            return [];
        }

        return $this->paginate(['q' => $q], $limit, 0, 'created_at', 'desc');
    }

    /**
     * The newest applications (dashboard widget, /stats in the bot).
     *
     * @return array<int,array<string,mixed>>
     */
    public function latest(int $limit = 10): array
    {
        return $this->paginate([], $limit, 0, 'created_at', 'desc');
    }

    public function total(): int
    {
        return $this->db->count('registrations');
    }

    /**
     * Telegram ids of everyone matching the filters — the broadcast audience.
     *
     * Blocked users are skipped: sending to them only produces 403 errors.
     *
     * @param array<string,mixed> $filters
     * @return int[]
     */
    public function telegramIdsFor(array $filters = []): array
    {
        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT DISTINCT r.' . $this->q('telegram_id') . ' AS ' . $this->q('telegram_id')
            . ' FROM ' . $this->registrations() . ' r'
            . ' LEFT JOIN ' . $this->users() . ' u ON u.' . $this->q('telegram_id')
            . ' = r.' . $this->q('telegram_id')
            . $where
            . ($where === '' ? ' WHERE ' : ' AND ')
            . '(u.' . $this->q('is_blocked') . ' IS NULL OR u.' . $this->q('is_blocked') . ' = 0)'
            . ' ORDER BY r.' . $this->q('telegram_id') . ' ASC';

        $ids = [];

        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $id = (int) ($row['telegram_id'] ?? 0);

            if ($id !== 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Walk every matching row without loading the whole table into memory.
     *
     * Rows are read in blocks of $chunk with LIMIT/OFFSET and yielded one by
     * one (already decoded), so a 50k row CSV export stays flat in memory.
     *
     * @param array<string,mixed> $filters
     * @return \Generator<int,array<string,mixed>>
     */
    public function each(array $filters = [], int $chunk = 500): \Generator
    {
        [$where, $params] = $this->buildFilters($filters);

        $chunk = max(1, min(5000, $chunk));
        $offset = 0;

        while (true) {
            $sql = $this->selectSql()
                . $where
                . ' ORDER BY r.' . $this->q('id') . ' ASC'
                . ' LIMIT ' . $chunk . ' OFFSET ' . $offset;

            $rows = $this->db->fetchAll($sql, $params);

            if ($rows === []) {
                return;
            }

            foreach ($rows as $row) {
                yield $this->decode($row);
            }

            if (count($rows) < $chunk) {
                return;
            }

            $offset += $chunk;
        }
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * SELECT with the user columns the admin panel needs joined in.
     */
    private function selectSql(): string
    {
        return 'SELECT r.*,'
            . ' u.' . $this->q('username') . ' AS ' . $this->q('username') . ','
            . ' u.' . $this->q('first_name') . ' AS ' . $this->q('user_first_name') . ','
            . ' u.' . $this->q('last_name') . ' AS ' . $this->q('user_last_name') . ','
            . ' u.' . $this->q('locale') . ' AS ' . $this->q('user_locale') . ','
            . ' u.' . $this->q('is_blocked') . ' AS ' . $this->q('user_is_blocked')
            . ' FROM ' . $this->registrations() . ' r'
            . ' LEFT JOIN ' . $this->users() . ' u ON u.' . $this->q('telegram_id')
            . ' = r.' . $this->q('telegram_id');
    }

    /**
     * Insert a fresh application.
     *
     * @param array<string,mixed> $values already normalized column => value
     */
    private function insertRow(int $userId, int $telegramId, array $values, string $now): int
    {
        $fullName = trim((string) ($values['full_name'] ?? ''));
        $phone = trim((string) ($values['phone'] ?? ''));

        if ($fullName === '' || $phone === '') {
            throw new \InvalidArgumentException(
                'A new registration needs at least "full_name" and "phone".'
            );
        }

        $row = array_merge(
            [
                'user_id'         => max(0, $userId),
                'telegram_id'     => $telegramId,
                'full_name'       => $fullName,
                'phone'           => $phone,
                'birth_year'      => null,
                'district'        => null,
                'directions'      => '[]',
                'direction_other' => null,
                'portfolio'       => null,
                'portfolio_links' => null,
                'status'          => RegistrationStatus::default()->value,
                'admin_note'      => null,
                'reviewed_by'     => null,
                'reviewed_at'     => null,
                'source'          => 'bot',
            ],
            $values
        );

        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        return $this->db->insert('registrations', $row);
    }

    /**
     * Filter $data down to the writable columns and cast every value.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeInput(array $data): array
    {
        $values = [];

        foreach (self::WRITABLE as $column) {
            if (!array_key_exists($column, $data)) {
                continue;
            }

            $value = $data[$column];

            switch ($column) {
                case 'directions':
                    // NOT NULL in the schema: an empty selection becomes "[]".
                    $values[$column] = $this->encodeList($value) ?? '[]';
                    break;

                case 'portfolio_links':
                    $values[$column] = $this->encodeList($value);
                    break;

                case 'birth_year':
                    $year = is_numeric($value) ? (int) $value : 0;
                    $values[$column] = $year > 0 ? $year : null;
                    break;

                case 'reviewed_by':
                    $reviewer = is_numeric($value) ? (int) $value : 0;
                    $values[$column] = $reviewer !== 0 ? $reviewer : null;
                    break;

                case 'status':
                    $status = RegistrationStatus::tryOrNull(is_scalar($value) ? (string) $value : null);

                    if ($status !== null) {
                        $values[$column] = $status->value;
                    }
                    break;

                case 'portfolio':
                case 'admin_note':
                    $text = $this->nullableString($value);
                    $values[$column] = $text === null ? null : mb_substr($text, 0, 4000, 'UTF-8');
                    break;

                case 'reviewed_at':
                    $values[$column] = $this->nullableString($value);
                    break;

                default:
                    $text = $this->nullableString($value);
                    $max = self::LENGTHS[$column] ?? 255;
                    $values[$column] = $text === null ? null : mb_substr($text, 0, $max, 'UTF-8');
                    break;
            }
        }

        return $values;
    }

    /**
     * Build the WHERE clause shared by paginate(), countAll(), each() and
     * telegramIdsFor().
     *
     * @param array<string,mixed> $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $conditions = [];
        $params = [];

        $search = trim((string) ($filters['q'] ?? ''));

        if ($search !== '') {
            foreach ($this->searchWords($search) as $word) {
                $like = $this->likeParam($word);
                $escape = ' ESCAPE \'' . self::LIKE_ESCAPE . '\'';

                $parts = [
                    'LOWER(r.' . $this->q('full_name') . ') LIKE ?' . $escape,
                    'LOWER(r.' . $this->q('phone') . ') LIKE ?' . $escape,
                    'LOWER(r.' . $this->q('direction_other') . ') LIKE ?' . $escape,
                    'LOWER(u.' . $this->q('username') . ') LIKE ?' . $escape,
                ];
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;

                // Numbers may be the application id or the telegram id.
                if (preg_match('/^\d+$/', $word) === 1) {
                    $parts[] = 'r.' . $this->q('id') . ' = ?';
                    $params[] = (int) $word;

                    $parts[] = 'r.' . $this->q('telegram_id') . ' = ?';
                    $params[] = (int) $word;
                }

                $conditions[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $statuses = $this->statusFilter($filters['status'] ?? null);

        if ($statuses !== []) {
            $conditions[] = 'r.' . $this->q('status') . ' IN ('
                . implode(', ', array_fill(0, count($statuses), '?')) . ')';

            foreach ($statuses as $status) {
                $params[] = $status;
            }
        }

        $district = $this->nullableString($filters['district'] ?? null);

        if ($district !== null) {
            $conditions[] = 'r.' . $this->q('district') . ' = ?';
            $params[] = mb_substr($district, 0, self::LENGTHS['district'], 'UTF-8');
        }

        $direction = $this->nullableString($filters['direction'] ?? null);

        if ($direction !== null) {
            // directions is a JSON list: ["ai_ml","web"] — match the quoted key.
            $conditions[] = 'r.' . $this->q('directions') . ' LIKE ? ESCAPE \'' . self::LIKE_ESCAPE . '\'';
            $params[] = '%' . $this->escapeLike('"' . $direction . '"') . '%';
        }

        $from = $this->dateBoundary($filters['date_from'] ?? null, false);

        if ($from !== null) {
            $conditions[] = 'r.' . $this->q('created_at') . ' >= ?';
            $params[] = $from;
        }

        $to = $this->dateBoundary($filters['date_to'] ?? null, true);

        if ($to !== null) {
            $conditions[] = 'r.' . $this->q('created_at') . ' <= ?';
            $params[] = $to;
        }

        $source = $this->nullableString($filters['source'] ?? null);

        if ($source !== null) {
            $conditions[] = 'r.' . $this->q('source') . ' = ?';
            $params[] = mb_substr($source, 0, self::LENGTHS['source'], 'UTF-8');
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Accept 'pending', ['pending','approved'] or 'pending,approved'.
     *
     * @return string[] valid status values only
     */
    private function statusFilter(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        $candidates = is_array($value) ? $value : explode(',', (string) $value);
        $statuses = [];

        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $status = RegistrationStatus::tryOrNull((string) $candidate);

            if ($status !== null) {
                $statuses[$status->value] = $status->value;
            }
        }

        return array_values($statuses);
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function decodeAll(array $rows): array
    {
        $decoded = [];

        foreach ($rows as $row) {
            $decoded[] = $this->decode($row);
        }

        return $decoded;
    }

    /**
     * Turn a raw database row into the shape the application works with.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['user_id'] = (int) ($row['user_id'] ?? 0);
        $row['telegram_id'] = (int) ($row['telegram_id'] ?? 0);
        $row['birth_year'] = isset($row['birth_year']) && $row['birth_year'] !== null
            ? (int) $row['birth_year']
            : null;
        $row['reviewed_by'] = isset($row['reviewed_by']) && $row['reviewed_by'] !== null
            ? (int) $row['reviewed_by']
            : null;

        $row['directions'] = $this->decodeList($row['directions'] ?? null);
        $row['portfolio_links'] = $this->decodeList($row['portfolio_links'] ?? null);

        return $row;
    }

    /**
     * JSON (or a plain string) => list of non empty strings.
     *
     * @return string[]
     */
    private function decodeList(mixed $raw): array
    {
        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            if (!is_array($decoded)) {
                // Legacy / hand edited value: treat it as a single entry.
                $decoded = [$raw];
            }
        } else {
            return [];
        }

        $list = [];

        foreach ($decoded as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);

            if ($item !== '') {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Array (or string) => JSON text for storage; null stays null.
     */
    private function encodeList(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            // Already JSON? Keep it (after a round trip that drops junk).
            $decoded = json_decode($trimmed, true);
            $value = is_array($decoded) ? $decoded : [$trimmed];
        }

        if (!is_array($value)) {
            $value = is_scalar($value) ? [(string) $value] : [];
        }

        $list = [];

        foreach ($value as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            $item = trim((string) $item);

            if ($item !== '') {
                $list[] = mb_substr($item, 0, 500, 'UTF-8');
            }
        }

        $json = json_encode(array_values($list), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '[]' : $json;
    }

    /**
     * The numeric part of an actor label ("tg:12345" => 12345).
     */
    private function actorId(?string $actor): ?int
    {
        if ($actor === null) {
            return null;
        }

        // Only a Telegram actor has a Telegram id. Digging digits out of any
        // label would invent one: a panel operator called "admin2024" would be
        // stored as reviewed_by = 2024, which is somebody else's account.
        // A panel decision leaves reviewed_by null; who made it is recorded in
        // audit_log, which the registration detail page renders.
        if (preg_match('/^tg:(-?\d+)$/', trim($actor), $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function searchWords(string $search): array
    {
        $words = preg_split('/\s+/u', mb_strtolower($search, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

        if (!is_array($words) || $words === []) {
            return [mb_strtolower($search, 'UTF-8')];
        }

        return array_slice($words, 0, 4);
    }

    private function likeParam(string $value): string
    {
        return '%' . $this->escapeLike(mb_strtolower($value, 'UTF-8')) . '%';
    }

    /**
     * Neutralize the LIKE wildcards inside a bound value.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $value
        );
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

    private function clampLimit(int $limit): int
    {
        return max(1, min(500, $limit));
    }

    private function clampOffset(int $offset): int
    {
        return max(0, $offset);
    }

    /** Quoted + prefixed registrations table. */
    private function registrations(): string
    {
        return $this->db->quoteIdent($this->db->table('registrations'));
    }

    /** Quoted + prefixed users table. */
    private function users(): string
    {
        return $this->db->quoteIdent($this->db->table('users'));
    }

    /** Quoted column identifier. */
    private function q(string $column): string
    {
        return $this->db->quoteIdent($column);
    }
}
