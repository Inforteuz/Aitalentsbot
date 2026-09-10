<?php

declare(strict_types=1);

namespace AiTalents;

/**
 * Schema installer.
 *
 * Every migration is a named set of DDL statements per driver. Applied names
 * are recorded in the "migrations" table, so run() is safe to call as often as
 * you like — already applied migrations are skipped and every CREATE uses
 * IF NOT EXISTS as a second line of defence.
 */
final class Migrator
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Apply every pending migration.
     *
     * @return string[] human readable log lines
     * @throws \RuntimeException when a statement fails
     */
    public function run(): array
    {
        $log = [];
        $driver = $this->db->driver();

        // The bookkeeping table itself is not part of definitions().
        if (!$this->db->tableExists('migrations')) {
            foreach ($this->migrationsTableSql()[$driver] as $sql) {
                $this->exec($sql, '000_create_migrations');
            }

            $this->db->flushTableCache();
            $log[] = 'created  ' . $this->db->table('migrations');
        }

        $applied = $this->appliedNames();

        foreach ($this->definitions() as $name => $perDriver) {
            if (in_array($name, $applied, true)) {
                $log[] = 'skipped  ' . $name . ' (already applied)';
                continue;
            }

            $statements = $perDriver[$driver] ?? null;

            if (!is_array($statements) || $statements === []) {
                throw new \RuntimeException(
                    'Migration ' . $name . ' has no statements for driver "' . $driver . '".'
                );
            }

            foreach ($statements as $sql) {
                $this->exec($sql, $name);
            }

            $this->db->insert('migrations', [
                'name'       => $name,
                'applied_at' => App::now(),
            ]);

            $log[] = 'applied  ' . $name;
        }

        $this->db->flushTableCache();

        return $log;
    }

    /**
     * True when the schema is in place (bookkeeping table + users table).
     */
    public function isInstalled(): bool
    {
        $this->db->flushTableCache();

        return $this->db->tableExists('migrations') && $this->db->tableExists('users');
    }

    /**
     * Names of the migrations that still have to run.
     *
     * @return string[]
     */
    public function pending(): array
    {
        $applied = $this->appliedNames();

        return array_values(array_diff(array_keys($this->definitions()), $applied));
    }

    /**
     * Names of the migrations already recorded in the database.
     *
     * @return string[]
     */
    public function applied(): array
    {
        return $this->appliedNames();
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    private function exec(string $sql, string $migration): void
    {
        try {
            $this->db->pdo()->exec($sql);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Migration ' . $migration . ' failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * @return string[]
     */
    private function appliedNames(): array
    {
        if (!$this->db->tableExists('migrations')) {
            return [];
        }

        try {
            $rows = $this->db->fetchAll(
                'SELECT ' . $this->db->quoteIdent('name')
                . ' FROM ' . $this->db->quoteIdent($this->db->table('migrations'))
                . ' ORDER BY ' . $this->db->quoteIdent('name') . ' ASC'
            );
        } catch (\Throwable $e) {
            return [];
        }

        $names = [];

        foreach ($rows as $row) {
            $names[] = (string) ($row['name'] ?? '');
        }

        return $names;
    }

    /**
     * DDL of the bookkeeping table, per driver.
     *
     * @return array<string,string[]>
     */
    private function migrationsTableSql(): array
    {
        $t = $this->db->table('migrations');

        return [
            'mysql' => [
                "CREATE TABLE IF NOT EXISTS `$t` (\n"
                . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                . "  `name` VARCHAR(128) NOT NULL,\n"
                . "  `applied_at` DATETIME NOT NULL,\n"
                . "  PRIMARY KEY (`id`),\n"
                . "  UNIQUE KEY `uq_{$t}_name` (`name`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            ],
            'sqlite' => [
                "CREATE TABLE IF NOT EXISTS \"$t\" (\n"
                . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                . "  \"name\" VARCHAR(128) NOT NULL UNIQUE,\n"
                . "  \"applied_at\" DATETIME NOT NULL\n"
                . ")",
            ],
        ];
    }

    /**
     * The ordered list of migrations.
     *
     * @return array<string,array<string,string[]>>
     */
    private function definitions(): array
    {
        $users      = $this->db->table('users');
        $regs       = $this->db->table('registrations');
        $settings   = $this->db->table('settings');
        $broadcasts = $this->db->table('broadcasts');
        $targets    = $this->db->table('broadcast_targets');
        $limits     = $this->db->table('rate_limits');
        $audit      = $this->db->table('audit_log');
        $logins     = $this->db->table('login_attempts');

        return [
            /* ---------------------------------------------------------- users */
            '001_create_users' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$users` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `telegram_id` BIGINT NOT NULL,\n"
                    . "  `username` VARCHAR(64) NULL,\n"
                    . "  `first_name` VARCHAR(128) NULL,\n"
                    . "  `last_name` VARCHAR(128) NULL,\n"
                    . "  `locale` VARCHAR(5) NOT NULL DEFAULT 'uz',\n"
                    . "  `state` VARCHAR(64) NOT NULL DEFAULT 'idle',\n"
                    . "  `state_data` TEXT NULL,\n"
                    . "  `is_admin` TINYINT NOT NULL DEFAULT 0,\n"
                    . "  `is_blocked` TINYINT NOT NULL DEFAULT 0,\n"
                    . "  `last_seen_at` DATETIME NULL,\n"
                    . "  `created_at` DATETIME NOT NULL,\n"
                    . "  `updated_at` DATETIME NOT NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  UNIQUE KEY `uq_{$users}_telegram_id` (`telegram_id`),\n"
                    . "  KEY `idx_{$users}_state` (`state`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$users\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"telegram_id\" INTEGER NOT NULL UNIQUE,\n"
                    . "  \"username\" VARCHAR(64) NULL,\n"
                    . "  \"first_name\" VARCHAR(128) NULL,\n"
                    . "  \"last_name\" VARCHAR(128) NULL,\n"
                    . "  \"locale\" VARCHAR(5) NOT NULL DEFAULT 'uz',\n"
                    . "  \"state\" VARCHAR(64) NOT NULL DEFAULT 'idle',\n"
                    . "  \"state_data\" TEXT NULL,\n"
                    . "  \"is_admin\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"is_blocked\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"last_seen_at\" DATETIME NULL,\n"
                    . "  \"created_at\" DATETIME NOT NULL,\n"
                    . "  \"updated_at\" DATETIME NOT NULL\n"
                    . ")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$users}_state\" ON \"$users\" (\"state\")",
                ],
            ],

            /* -------------------------------------------------- registrations */
            '002_create_registrations' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$regs` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `user_id` INT UNSIGNED NOT NULL,\n"
                    . "  `telegram_id` BIGINT NOT NULL,\n"
                    . "  `full_name` VARCHAR(160) NOT NULL,\n"
                    . "  `phone` VARCHAR(24) NOT NULL,\n"
                    . "  `birth_year` INT NULL,\n"
                    . "  `district` VARCHAR(48) NULL,\n"
                    . "  `directions` TEXT NOT NULL,\n"
                    . "  `direction_other` VARCHAR(160) NULL,\n"
                    . "  `portfolio` TEXT NULL,\n"
                    . "  `portfolio_links` TEXT NULL,\n"
                    . "  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',\n"
                    . "  `admin_note` TEXT NULL,\n"
                    . "  `reviewed_by` BIGINT NULL,\n"
                    . "  `reviewed_at` DATETIME NULL,\n"
                    . "  `source` VARCHAR(32) NOT NULL DEFAULT 'bot',\n"
                    . "  `created_at` DATETIME NOT NULL,\n"
                    . "  `updated_at` DATETIME NOT NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  UNIQUE KEY `uq_{$regs}_telegram_id` (`telegram_id`),\n"
                    . "  KEY `idx_{$regs}_status` (`status`),\n"
                    . "  KEY `idx_{$regs}_district` (`district`),\n"
                    . "  KEY `idx_{$regs}_created_at` (`created_at`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$regs\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"user_id\" INTEGER NOT NULL,\n"
                    . "  \"telegram_id\" INTEGER NOT NULL UNIQUE,\n"
                    . "  \"full_name\" VARCHAR(160) NOT NULL,\n"
                    . "  \"phone\" VARCHAR(24) NOT NULL,\n"
                    . "  \"birth_year\" INTEGER NULL,\n"
                    . "  \"district\" VARCHAR(48) NULL,\n"
                    . "  \"directions\" TEXT NOT NULL,\n"
                    . "  \"direction_other\" VARCHAR(160) NULL,\n"
                    . "  \"portfolio\" TEXT NULL,\n"
                    . "  \"portfolio_links\" TEXT NULL,\n"
                    . "  \"status\" VARCHAR(16) NOT NULL DEFAULT 'pending',\n"
                    . "  \"admin_note\" TEXT NULL,\n"
                    . "  \"reviewed_by\" INTEGER NULL,\n"
                    . "  \"reviewed_at\" DATETIME NULL,\n"
                    . "  \"source\" VARCHAR(32) NOT NULL DEFAULT 'bot',\n"
                    . "  \"created_at\" DATETIME NOT NULL,\n"
                    . "  \"updated_at\" DATETIME NOT NULL\n"
                    . ")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$regs}_status\" ON \"$regs\" (\"status\")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$regs}_district\" ON \"$regs\" (\"district\")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$regs}_created_at\" ON \"$regs\" (\"created_at\")",
                ],
            ],

            /* ------------------------------------------------------- settings */
            '003_create_settings' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$settings` (\n"
                    . "  `name` VARCHAR(64) NOT NULL,\n"
                    . "  `value` TEXT NULL,\n"
                    . "  `updated_at` DATETIME NOT NULL,\n"
                    . "  PRIMARY KEY (`name`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$settings\" (\n"
                    . "  \"name\" VARCHAR(64) NOT NULL PRIMARY KEY,\n"
                    . "  \"value\" TEXT NULL,\n"
                    . "  \"updated_at\" DATETIME NOT NULL\n"
                    . ")",
                ],
            ],

            /* ----------------------------------------------------- broadcasts */
            '004_create_broadcasts' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$broadcasts` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `admin_id` BIGINT NULL,\n"
                    . "  `text` TEXT NOT NULL,\n"
                    . "  `parse_mode` VARCHAR(16) NOT NULL DEFAULT 'HTML',\n"
                    . "  `filters` TEXT NULL,\n"
                    . "  `status` VARCHAR(16) NOT NULL DEFAULT 'draft',\n"
                    . "  `total` INT NOT NULL DEFAULT 0,\n"
                    . "  `sent` INT NOT NULL DEFAULT 0,\n"
                    . "  `failed` INT NOT NULL DEFAULT 0,\n"
                    . "  `created_at` DATETIME NOT NULL,\n"
                    . "  `updated_at` DATETIME NOT NULL,\n"
                    . "  `finished_at` DATETIME NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  KEY `idx_{$broadcasts}_status` (`status`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$broadcasts\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"admin_id\" INTEGER NULL,\n"
                    . "  \"text\" TEXT NOT NULL,\n"
                    . "  \"parse_mode\" VARCHAR(16) NOT NULL DEFAULT 'HTML',\n"
                    . "  \"filters\" TEXT NULL,\n"
                    . "  \"status\" VARCHAR(16) NOT NULL DEFAULT 'draft',\n"
                    . "  \"total\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"sent\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"failed\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"created_at\" DATETIME NOT NULL,\n"
                    . "  \"updated_at\" DATETIME NOT NULL,\n"
                    . "  \"finished_at\" DATETIME NULL\n"
                    . ")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$broadcasts}_status\" ON \"$broadcasts\" (\"status\")",
                ],
            ],

            /* ---------------------------------------------- broadcast_targets */
            '005_create_broadcast_targets' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$targets` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `broadcast_id` INT UNSIGNED NOT NULL,\n"
                    . "  `telegram_id` BIGINT NOT NULL,\n"
                    . "  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',\n"
                    . "  `error` VARCHAR(255) NULL,\n"
                    . "  `sent_at` DATETIME NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  KEY `idx_{$targets}_broadcast_status` (`broadcast_id`, `status`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$targets\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"broadcast_id\" INTEGER NOT NULL,\n"
                    . "  \"telegram_id\" INTEGER NOT NULL,\n"
                    . "  \"status\" VARCHAR(16) NOT NULL DEFAULT 'pending',\n"
                    . "  \"error\" VARCHAR(255) NULL,\n"
                    . "  \"sent_at\" DATETIME NULL\n"
                    . ")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$targets}_broadcast_status\""
                    . " ON \"$targets\" (\"broadcast_id\", \"status\")",
                ],
            ],

            /* ---------------------------------------------------- rate_limits */
            '006_create_rate_limits' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$limits` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `telegram_id` BIGINT NOT NULL,\n"
                    . "  `bucket` VARCHAR(32) NOT NULL,\n"
                    . "  `hits` INT NOT NULL DEFAULT 0,\n"
                    . "  `window_started_at` INT NOT NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  UNIQUE KEY `uq_{$limits}_id_bucket` (`telegram_id`, `bucket`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$limits\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"telegram_id\" INTEGER NOT NULL,\n"
                    . "  \"bucket\" VARCHAR(32) NOT NULL,\n"
                    . "  \"hits\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"window_started_at\" INTEGER NOT NULL\n"
                    . ")",
                    "CREATE UNIQUE INDEX IF NOT EXISTS \"uq_{$limits}_id_bucket\""
                    . " ON \"$limits\" (\"telegram_id\", \"bucket\")",
                ],
            ],

            /* ------------------------------------------------------ audit_log */
            '007_create_audit_log' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$audit` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `actor` VARCHAR(64) NOT NULL,\n"
                    . "  `action` VARCHAR(64) NOT NULL,\n"
                    . "  `target` VARCHAR(64) NULL,\n"
                    . "  `meta` TEXT NULL,\n"
                    . "  `ip` VARCHAR(45) NULL,\n"
                    . "  `created_at` DATETIME NOT NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  KEY `idx_{$audit}_created_at` (`created_at`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$audit\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"actor\" VARCHAR(64) NOT NULL,\n"
                    . "  \"action\" VARCHAR(64) NOT NULL,\n"
                    . "  \"target\" VARCHAR(64) NULL,\n"
                    . "  \"meta\" TEXT NULL,\n"
                    . "  \"ip\" VARCHAR(45) NULL,\n"
                    . "  \"created_at\" DATETIME NOT NULL\n"
                    . ")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$audit}_created_at\" ON \"$audit\" (\"created_at\")",
                ],
            ],

            /* ------------------------------------------------- login_attempts */
            '008_create_login_attempts' => [
                'mysql' => [
                    "CREATE TABLE IF NOT EXISTS `$logins` (\n"
                    . "  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
                    . "  `ip` VARCHAR(45) NOT NULL,\n"
                    . "  `username` VARCHAR(64) NULL,\n"
                    . "  `success` TINYINT NOT NULL DEFAULT 0,\n"
                    . "  `created_at` DATETIME NOT NULL,\n"
                    . "  PRIMARY KEY (`id`),\n"
                    . "  KEY `idx_{$logins}_ip_created` (`ip`, `created_at`)\n"
                    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
                ],
                'sqlite' => [
                    "CREATE TABLE IF NOT EXISTS \"$logins\" (\n"
                    . "  \"id\" INTEGER PRIMARY KEY AUTOINCREMENT,\n"
                    . "  \"ip\" VARCHAR(45) NOT NULL,\n"
                    . "  \"username\" VARCHAR(64) NULL,\n"
                    . "  \"success\" INTEGER NOT NULL DEFAULT 0,\n"
                    . "  \"created_at\" DATETIME NOT NULL\n"
                    . ")",
                    "CREATE INDEX IF NOT EXISTS \"idx_{$logins}_ip_created\" ON \"$logins\" (\"ip\", \"created_at\")",
                ],
            ],
        ];
    }
}
