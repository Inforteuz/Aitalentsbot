<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Thin PDO wrapper supporting SQLite (default, shared hosting friendly) and MySQL.
 *
 * Everything goes through prepared statements. Table and column identifiers are
 * quoted per driver; callers must only pass identifiers they control or that
 * come from a whitelist (e.g. ORDER BY columns in the repositories).
 */
final class Database
{
    /** Lazily created connection. */
    private ?\PDO $pdo = null;

    /** Cached, normalized driver name. */
    private ?string $driver = null;

    /** Table prefix, resolved once. */
    private ?string $prefix = null;

    /** tableExists() results for this request. */
    private array $tableCache = [];

    /**
     * @param array<string,mixed> $config the 'database' section of config.php
     */
    public function __construct(private array $config)
    {
    }

    /**
     * The live PDO connection (connects on first use).
     */
    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        $this->pdo = $this->driver() === 'mysql'
            ? $this->connectMysql()
            : $this->connectSqlite();

        return $this->pdo;
    }

    /**
     * 'mysql' or 'sqlite'.
     */
    public function driver(): string
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $driver = strtolower(trim((string) ($this->config['driver'] ?? 'sqlite')));

        if ($driver === '') {
            $driver = 'sqlite';
        }

        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new \RuntimeException(
                'Unsupported database driver "' . $driver . '". Use "sqlite" or "mysql".'
            );
        }

        return $this->driver = $driver;
    }

    /**
     * Prefixed physical table name for a logical table name.
     */
    public function table(string $name): string
    {
        $name = trim($name);

        if ($this->prefix === null) {
            $this->prefix = (string) ($this->config['prefix'] ?? '');
        }

        $full = str_starts_with($name, $this->prefix) && $this->prefix !== ''
            ? $name              // already prefixed
            : $this->prefix . $name;

        if (preg_match('/^[A-Za-z0-9_]+$/', $full) !== 1) {
            throw new \InvalidArgumentException('Invalid table name: ' . $name);
        }

        return $full;
    }

    /**
     * Prepare + execute a statement.
     *
     * @param array<int|string,mixed> $params positional or named parameters
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);

        foreach ($params as $key => $value) {
            $placeholder = is_int($key)
                ? $key + 1
                : (str_starts_with($key, ':') ? $key : ':' . $key);

            $stmt->bindValue($placeholder, $this->castValue($value), $this->paramType($value));
        }

        $stmt->execute();

        return $stmt;
    }

    /**
     * First row or null.
     *
     * @param array<int|string,mixed> $params
     * @return ?array<string,mixed>
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * All rows.
     *
     * @param array<int|string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $rows = $this->query($sql, $params)->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    /**
     * One column of the first row (null when there is no row).
     *
     * @param array<int|string,mixed> $params
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn($column);

        return $value === false ? null : $value;
    }

    /**
     * Insert one row and return the new id.
     *
     * @param array<string,mixed> $data column => value
     */
    public function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('insert() needs at least one column.');
        }

        $columns = [];
        $placeholders = [];
        $params = [];

        foreach ($data as $column => $value) {
            $columns[] = $this->quoteIdent((string) $column);
            $placeholders[] = '?';
            $params[] = $value;
        }

        $sql = 'INSERT INTO ' . $this->quoteIdent($this->table($table))
            . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $this->query($sql, $params);

        $id = $this->pdo()->lastInsertId();

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Update rows matching $where; returns the number of affected rows.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($data === []) {
            throw new \InvalidArgumentException('update() needs at least one column.');
        }

        if ($where === []) {
            throw new \InvalidArgumentException('update() refuses to run without a WHERE clause.');
        }

        $set = [];
        $params = [];

        foreach ($data as $column => $value) {
            $set[] = $this->quoteIdent((string) $column) . ' = ?';
            $params[] = $value;
        }

        [$clause, $whereParams] = $this->buildWhere($where);

        $sql = 'UPDATE ' . $this->quoteIdent($this->table($table))
            . ' SET ' . implode(', ', $set) . $clause;

        return $this->query($sql, array_merge($params, $whereParams))->rowCount();
    }

    /**
     * Delete rows matching $where; returns the number of affected rows.
     *
     * @param array<string,mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \InvalidArgumentException('delete() refuses to run without a WHERE clause.');
        }

        [$clause, $params] = $this->buildWhere($where);

        $sql = 'DELETE FROM ' . $this->quoteIdent($this->table($table)) . $clause;

        return $this->query($sql, $params)->rowCount();
    }

    /**
     * @param array<string,mixed> $where
     */
    public function exists(string $table, array $where): bool
    {
        [$clause, $params] = $this->buildWhere($where);

        $sql = 'SELECT 1 FROM ' . $this->quoteIdent($this->table($table)) . $clause . ' LIMIT 1';

        return $this->fetchColumn($sql, $params) !== null;
    }

    /**
     * @param array<string,mixed> $where
     */
    public function count(string $table, array $where = []): int
    {
        [$clause, $params] = $this->buildWhere($where);

        $sql = 'SELECT COUNT(*) FROM ' . $this->quoteIdent($this->table($table)) . $clause;

        return (int) $this->fetchColumn($sql, $params);
    }

    /**
     * Run $fn inside a transaction. Nested calls join the running transaction.
     * The callback receives this Database instance.
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $fn($this);
        }

        $pdo->beginTransaction();

        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Quote an identifier for the active driver: `col` on MySQL, "col" on SQLite.
     */
    public function quoteIdent(string $ident): string
    {
        $ident = trim($ident);

        if ($ident === '' || strpbrk($ident, "\0\r\n") !== false) {
            throw new \InvalidArgumentException('Invalid SQL identifier.');
        }

        if ($this->driver() === 'mysql') {
            return '`' . str_replace('`', '``', $ident) . '`';
        }

        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * Does the (logical or already prefixed) table exist?
     */
    public function tableExists(string $table): bool
    {
        $name = $this->table($table);

        if (array_key_exists($name, $this->tableCache)) {
            return $this->tableCache[$name];
        }

        try {
            if ($this->driver() === 'mysql') {
                $sql = 'SELECT COUNT(*) FROM information_schema.tables '
                    . 'WHERE table_schema = DATABASE() AND table_name = ?';
            } else {
                $sql = "SELECT COUNT(*) FROM sqlite_master WHERE type IN ('table','view') AND name = ?";
            }

            $exists = ((int) $this->fetchColumn($sql, [$name])) > 0;
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $this->tableCache[$name] = $exists;
    }

    /**
     * Forget cached tableExists() answers (used right after migrations run).
     */
    public function flushTableCache(): void
    {
        $this->tableCache = [];
    }

    /**
     * Absolute path of the SQLite file, or null on MySQL.
     */
    public function sqlitePath(): ?string
    {
        return $this->driver() === 'sqlite' ? $this->resolveSqlitePath() : null;
    }

    /**
     * Close the connection (mainly useful for tests and long running CLI jobs).
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->tableCache = [];
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Build " WHERE a = ? AND b IS NULL AND c IN (?, ?)".
     *
     * @param array<string,mixed> $where
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildWhere(array $where): array
    {
        if ($where === []) {
            return ['', []];
        }

        $parts = [];
        $params = [];

        foreach ($where as $column => $value) {
            $ident = $this->quoteIdent((string) $column);

            if ($value === null) {
                $parts[] = $ident . ' IS NULL';
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $parts[] = '1 = 0'; // an empty IN () matches nothing
                    continue;
                }

                $parts[] = $ident . ' IN (' . implode(', ', array_fill(0, count($value), '?')) . ')';

                foreach ($value as $item) {
                    $params[] = $item;
                }

                continue;
            }

            $parts[] = $ident . ' = ?';
            $params[] = $value;
        }

        return [' WHERE ' . implode(' AND ', $parts), $params];
    }

    private function paramType(mixed $value): int
    {
        return match (true) {
            $value === null       => \PDO::PARAM_NULL,
            is_bool($value)       => \PDO::PARAM_INT,
            is_int($value)        => \PDO::PARAM_INT,
            default               => \PDO::PARAM_STR,
        };
    }

    /**
     * Booleans are stored as 0/1; floats keep full precision as strings.
     */
    private function castValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            throw new \InvalidArgumentException('Array values cannot be bound to a single placeholder.');
        }

        return $value;
    }

    private function connectSqlite(): \PDO
    {
        $path = $this->resolveSqlitePath();

        if ($path !== ':memory:') {
            $dir = dirname($path);

            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('Cannot create the SQLite directory: ' . $dir);
            }
        }

        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, $this->pdoOptions());
        } catch (\PDOException $e) {
            throw new \RuntimeException(
                'SQLite connection failed (' . $path . '): ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        // Pragmas: referential integrity, WAL for concurrent readers, lock patience.
        $pdo->exec('PRAGMA foreign_keys = ON');

        if ($path !== ':memory:') {
            $pdo->exec('PRAGMA journal_mode = WAL');
        }

        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    private function connectMysql(): \PDO
    {
        $host = (string) ($this->config['host'] ?? 'localhost');
        $port = (int) ($this->config['port'] ?? 3306);
        $name = (string) ($this->config['database'] ?? '');
        $user = (string) ($this->config['username'] ?? '');
        $pass = (string) ($this->config['password'] ?? '');
        $charset = (string) ($this->config['charset'] ?? 'utf8mb4');

        if ($charset === '') {
            $charset = 'utf8mb4';
        }

        if ($name === '') {
            throw new \RuntimeException('MySQL is selected but database.database is empty in config.php.');
        }

        $dsn = 'mysql:host=' . $host . ';port=' . ($port > 0 ? $port : 3306)
            . ';dbname=' . $name . ';charset=' . $charset;

        $options = $this->pdoOptions();

        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET NAMES ' . $charset;
        }

        try {
            $pdo = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            // Never echo the password back to the caller.
            throw new \RuntimeException(
                'MySQL connection failed (' . $user . '@' . $host . '/' . $name . '): ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $pdo->exec('SET NAMES ' . $charset);

        return $pdo;
    }

    /**
     * @return array<int,mixed>
     */
    private function pdoOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
    }

    private function resolveSqlitePath(): string
    {
        $path = trim((string) ($this->config['path'] ?? ''));

        if ($path === '') {
            $root = defined('AITALENTS_ROOT') ? (string) AITALENTS_ROOT : dirname(__DIR__);
            $path = $root . '/data/aitalents.sqlite';
        }

        return $path;
    }
}
