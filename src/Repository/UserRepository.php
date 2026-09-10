<?php

declare(strict_types=1);

namespace AiTalents\Repository;

use AiTalents\App;
use AiTalents\Database;
use AiTalents\Lang;

/**
 * Everything the bot knows about a Telegram user.
 *
 * The table doubles as the storage of the conversation state machine:
 * `state` holds the current step ("reg:phone", "admin:broadcast", "idle") and
 * `state_data` a JSON object with the answers collected so far.
 *
 * All queries are prepared statements; the only identifiers that ever reach the
 * SQL string come from the whitelists below.
 */
final class UserRepository
{
    /** Columns that may be used in ORDER BY (paginate()). */
    private const SORTABLE = ['id', 'created_at', 'last_seen_at', 'telegram_id'];

    /** The state of a user who is not in the middle of a conversation. */
    private const IDLE = 'idle';

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

        return $this->db->fetch(
            'SELECT * FROM ' . $this->users() . ' WHERE ' . $this->q('telegram_id') . ' = ? LIMIT 1',
            [$telegramId]
        );
    }

    /**
     * @return ?array<string,mixed>
     */
    public function findById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return $this->db->fetch(
            'SELECT * FROM ' . $this->users() . ' WHERE ' . $this->q('id') . ' = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Create or refresh the row for a Telegram `from` object and return it.
     *
     * The upsert is a SELECT followed by an INSERT/UPDATE inside a transaction,
     * so it behaves identically on MySQL and on SQLite (no driver specific
     * "ON DUPLICATE KEY" / "ON CONFLICT" syntax).
     *
     * `locale` is only written when the row is created: after that the user's
     * own choice in the bot wins over the Telegram client language.
     * `last_seen_at` is only bumped for private chats — a group event should
     * not look like the user talked to the bot.
     *
     * @param array<string,mixed> $from  Telegram User object (id, username, first_name, ...)
     * @param ?string             $chatType  'private', 'group', 'supergroup', 'channel' or null
     * @return array<string,mixed> the fresh row
     */
    public function touch(array $from, ?string $chatType = null): array
    {
        $telegramId = (int) ($from['id'] ?? 0);

        if ($telegramId === 0) {
            throw new \InvalidArgumentException('UserRepository::touch() needs a "from" object with an id.');
        }

        $username  = $this->clip($this->nullableString($from['username'] ?? null), 64);
        $firstName = $this->clip($this->nullableString($from['first_name'] ?? null), 128);
        $lastName  = $this->clip($this->nullableString($from['last_name'] ?? null), 128);
        $locale    = Lang::normalize($this->nullableString($from['language_code'] ?? null));
        $isPrivate = $chatType === null || strtolower(trim($chatType)) === 'private';
        $now       = App::now();

        /** @var array<string,mixed> $row */
        $row = $this->db->transaction(function () use (
            $telegramId,
            $username,
            $firstName,
            $lastName,
            $locale,
            $isPrivate,
            $now
        ): array {
            $existing = $this->findByTelegramId($telegramId);

            if ($existing === null) {
                $existing = $this->insertUser($telegramId, $username, $firstName, $lastName, $locale, $isPrivate, $now);
            }

            // Only write the columns that actually changed.
            $patch = [];

            if ($this->nullableString($existing['username'] ?? null) !== $username) {
                $patch['username'] = $username;
            }

            if ($this->nullableString($existing['first_name'] ?? null) !== $firstName) {
                $patch['first_name'] = $firstName;
            }

            if ($this->nullableString($existing['last_name'] ?? null) !== $lastName) {
                $patch['last_name'] = $lastName;
            }

            if ($isPrivate && (string) ($existing['last_seen_at'] ?? '') !== $now) {
                $patch['last_seen_at'] = $now;
            }

            if ($patch === []) {
                return $existing;
            }

            $patch['updated_at'] = $now;

            $this->db->update('users', $patch, ['telegram_id' => $telegramId]);

            return $this->findByTelegramId($telegramId) ?? array_merge($existing, $patch);
        });

        return $row;
    }

    /**
     * Move the user to another conversation state.
     *
     * Passing $data === null keeps the stored state_data untouched (the usual
     * case when the flow only advances one step); pass an array to replace it,
     * or [] to start from an empty object.
     *
     * @param ?array<string,mixed> $data
     */
    public function setState(int $telegramId, string $state, ?array $data = null): void
    {
        if ($telegramId === 0) {
            return;
        }

        $patch = [
            'state'      => $this->clipRequired($state, 64, self::IDLE),
            'updated_at' => App::now(),
        ];

        if ($data !== null) {
            $patch['state_data'] = $this->encodeStateData($data);
        }

        $this->db->update('users', $patch, ['telegram_id' => $telegramId]);
    }

    /**
     * Current state, 'idle' when the user is unknown.
     */
    public function state(int $telegramId): string
    {
        if ($telegramId === 0) {
            return self::IDLE;
        }

        $state = $this->db->fetchColumn(
            'SELECT ' . $this->q('state') . ' FROM ' . $this->users()
            . ' WHERE ' . $this->q('telegram_id') . ' = ? LIMIT 1',
            [$telegramId]
        );

        $state = is_string($state) ? trim($state) : '';

        return $state === '' ? self::IDLE : $state;
    }

    /**
     * The decoded state_data object ([] when empty or malformed).
     *
     * @return array<string,mixed>
     */
    public function stateData(int $telegramId): array
    {
        if ($telegramId === 0) {
            return [];
        }

        $raw = $this->db->fetchColumn(
            'SELECT ' . $this->q('state_data') . ' FROM ' . $this->users()
            . ' WHERE ' . $this->q('telegram_id') . ' = ? LIMIT 1',
            [$telegramId]
        );

        return $this->decodeStateData(is_string($raw) ? $raw : null);
    }

    /**
     * Merge $patch into state_data and return the merged array.
     *
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    public function mergeStateData(int $telegramId, array $patch): array
    {
        if ($telegramId === 0) {
            return $patch;
        }

        /** @var array<string,mixed> $merged */
        $merged = $this->db->transaction(function () use ($telegramId, $patch): array {
            $data = $this->stateData($telegramId);
            $data = array_replace($data, $patch);

            $this->db->update(
                'users',
                ['state_data' => $this->encodeStateData($data), 'updated_at' => App::now()],
                ['telegram_id' => $telegramId]
            );

            return $data;
        });

        return $merged;
    }

    /**
     * Back to 'idle' and forget every collected answer.
     */
    public function clearState(int $telegramId): void
    {
        if ($telegramId === 0) {
            return;
        }

        $this->db->update(
            'users',
            ['state' => self::IDLE, 'state_data' => null, 'updated_at' => App::now()],
            ['telegram_id' => $telegramId]
        );
    }

    /**
     * Store the interface language (normalized to a supported locale).
     */
    public function setLocale(int $telegramId, string $locale): void
    {
        if ($telegramId === 0) {
            return;
        }

        $this->db->update(
            'users',
            ['locale' => Lang::normalize($locale), 'updated_at' => App::now()],
            ['telegram_id' => $telegramId]
        );
    }

    /**
     * Mark the user as having blocked the bot (or as reachable again).
     */
    public function setBlocked(int $telegramId, bool $blocked): void
    {
        if ($telegramId === 0) {
            return;
        }

        $this->db->update(
            'users',
            ['is_blocked' => $blocked ? 1 : 0, 'updated_at' => App::now()],
            ['telegram_id' => $telegramId]
        );
    }

    /**
     * Grant or revoke the in-bot admin flag (config admins stay admins anyway).
     */
    public function setAdmin(int $telegramId, bool $admin): void
    {
        if ($telegramId === 0) {
            return;
        }

        $this->db->update(
            'users',
            ['is_admin' => $admin ? 1 : 0, 'updated_at' => App::now()],
            ['telegram_id' => $telegramId]
        );
    }

    /**
     * Telegram ids flagged with is_admin = 1.
     *
     * @return int[]
     */
    public function adminTelegramIds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->q('telegram_id') . ' FROM ' . $this->users()
            . ' WHERE ' . $this->q('is_admin') . ' = 1'
            . ' ORDER BY ' . $this->q('id') . ' ASC'
        );

        return $this->pluckIds($rows, 'telegram_id');
    }

    /**
     * Everyone who did not block the bot — the default broadcast audience.
     *
     * @return int[]
     */
    public function activeTelegramIds(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT ' . $this->q('telegram_id') . ' FROM ' . $this->users()
            . ' WHERE ' . $this->q('is_blocked') . ' = 0'
            . ' ORDER BY ' . $this->q('id') . ' ASC'
        );

        return $this->pluckIds($rows, 'telegram_id');
    }

    /**
     * One page of users; pair it with countAll() for the pager.
     *
     * Filters: q (name/username/telegram id), blocked (0|1), registered (0|1), locale.
     * Every row carries an extra `is_registered` flag (1 when the user has an
     * application) on top of the physical columns.
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

        $sql = 'SELECT u.*, (CASE WHEN EXISTS ('
            . 'SELECT 1 FROM ' . $this->registrations() . ' r WHERE r.' . $this->q('telegram_id')
            . ' = u.' . $this->q('telegram_id') . ') THEN 1 ELSE 0 END) AS ' . $this->q('is_registered')
            . ' FROM ' . $this->users() . ' u'
            . $where
            . ' ORDER BY u.' . $this->q($this->sortColumn($sort)) . ' ' . $this->sortDirection($dir)
            . ', u.' . $this->q('id') . ' DESC'
            // $limit/$offset are integers clamped just above: safe to inline and
            // portable (MySQL refuses a placeholder in LIMIT with some setups).
            . ' LIMIT ' . $this->clampLimit($limit) . ' OFFSET ' . $this->clampOffset($offset);

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * How many users match the filters.
     *
     * @param array<string,mixed> $filters
     */
    public function countAll(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        return (int) $this->db->fetchColumn(
            'SELECT COUNT(*) FROM ' . $this->users() . ' u' . $where,
            $params
        );
    }

    /**
     * Every user row ever created.
     */
    public function total(): int
    {
        return $this->db->count('users');
    }

    /**
     * Users who blocked the bot.
     */
    public function blockedCount(): int
    {
        return $this->db->count('users', ['is_blocked' => 1]);
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Insert a brand new user and return the stored row.
     *
     * A parallel webhook may have inserted the same telegram_id in the
     * meantime; in that case the unique key error is swallowed and the row
     * written by the other request is returned.
     *
     * @return array<string,mixed>
     */
    private function insertUser(
        int $telegramId,
        ?string $username,
        ?string $firstName,
        ?string $lastName,
        string $locale,
        bool $isPrivate,
        string $now
    ): array {
        try {
            $this->db->insert('users', [
                'telegram_id'  => $telegramId,
                'username'     => $username,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'locale'       => $locale,
                'state'        => self::IDLE,
                'state_data'   => null,
                'is_admin'     => 0,
                'is_blocked'   => 0,
                'last_seen_at' => $isPrivate ? $now : null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);
        } catch (\PDOException $e) {
            // Unique key violation from a concurrent request — fall through.
            if ($this->findByTelegramId($telegramId) === null) {
                throw $e;
            }
        }

        $row = $this->findByTelegramId($telegramId);

        if ($row === null) {
            throw new \RuntimeException('The user row for telegram id ' . $telegramId . ' could not be created.');
        }

        return $row;
    }

    /**
     * Build the WHERE clause of paginate()/countAll().
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
                $parts = [
                    'LOWER(u.' . $this->q('first_name') . ') LIKE ? ESCAPE \'' . self::LIKE_ESCAPE . '\'',
                    'LOWER(u.' . $this->q('last_name') . ') LIKE ? ESCAPE \'' . self::LIKE_ESCAPE . '\'',
                    'LOWER(u.' . $this->q('username') . ') LIKE ? ESCAPE \'' . self::LIKE_ESCAPE . '\'',
                ];
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;

                // A numeric term may be the telegram id itself.
                if (preg_match('/^\d{3,}$/', $word) === 1) {
                    $parts[] = 'u.' . $this->q('telegram_id') . ' = ?';
                    $params[] = (int) $word;
                }

                $conditions[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $blocked = $this->boolFilter($filters['blocked'] ?? null);

        if ($blocked !== null) {
            $conditions[] = 'u.' . $this->q('is_blocked') . ' = ?';
            $params[] = $blocked ? 1 : 0;
        }

        $admin = $this->boolFilter($filters['admin'] ?? null);

        if ($admin !== null) {
            $conditions[] = 'u.' . $this->q('is_admin') . ' = ?';
            $params[] = $admin ? 1 : 0;
        }

        $registered = $this->boolFilter($filters['registered'] ?? null);

        if ($registered !== null) {
            $exists = 'EXISTS (SELECT 1 FROM ' . $this->registrations() . ' r WHERE r.'
                . $this->q('telegram_id') . ' = u.' . $this->q('telegram_id') . ')';

            $conditions[] = $registered ? $exists : 'NOT ' . $exists;
        }

        $locale = strtolower(trim((string) ($filters['locale'] ?? '')));

        if ($locale !== '' && preg_match('/^[a-z]{2}$/', $locale) === 1) {
            $conditions[] = 'u.' . $this->q('locale') . ' = ?';
            $params[] = $locale;
        }

        return [$conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * Split a search box value into at most four terms.
     *
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

    /**
     * '1'/'0'/'yes'/'true' style filter values, null when the filter is unset.
     */
    private function boolFilter(mixed $value): ?bool
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        return match ($value) {
            '1', 'yes', 'true', 'on'  => true,
            '0', 'no', 'false', 'off' => false,
            default                   => null,
        };
    }

    /**
     * Turn a user term into a LIKE pattern with the wildcards escaped.
     */
    private function likeParam(string $value): string
    {
        $escaped = str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            mb_strtolower($value, 'UTF-8')
        );

        return '%' . $escaped . '%';
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return int[]
     */
    private function pluckIds(array $rows, string $column): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row[$column] ?? 0);

            if ($id !== 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function encodeStateData(array $data): ?string
    {
        if ($data === []) {
            return '{}';
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeStateData(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_array($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max, 'UTF-8');
    }

    private function clipRequired(string $value, int $max, string $fallback): string
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        return mb_substr($value, 0, $max, 'UTF-8');
    }

    /** Quoted + prefixed users table. */
    private function users(): string
    {
        return $this->db->quoteIdent($this->db->table('users'));
    }

    /** Quoted + prefixed registrations table (used by the "registered" filter). */
    private function registrations(): string
    {
        return $this->db->quoteIdent($this->db->table('registrations'));
    }

    /** Quoted column identifier. */
    private function q(string $column): string
    {
        return $this->db->quoteIdent($column);
    }
}
