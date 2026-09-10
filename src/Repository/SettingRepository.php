<?php

declare(strict_types=1);

namespace AiTalents\Repository;

use AiTalents\App;
use AiTalents\Database;

/**
 * Key/value store for the settings an operator can change at runtime
 * (registration_open, required_channel, ask_language, welcome_extra, ...).
 *
 * Values are stored JSON encoded, so booleans stay booleans and arrays survive
 * the round trip. Rows are cached per request and the cache is dropped whenever
 * something is written.
 */
final class SettingRepository
{
    /** Maximum length of the primary key column. */
    private const NAME_MAX = 64;

    /** name => decoded value, or null while nothing has been read yet. */
    private ?array $cache = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * Read one setting; $default is returned when it was never stored.
     */
    public function get(string $name, mixed $default = null): mixed
    {
        $name = $this->normalizeName($name);
        $all = $this->load();

        return array_key_exists($name, $all) ? $all[$name] : $default;
    }

    /**
     * Write one setting (insert or update — portable on MySQL and SQLite).
     */
    public function set(string $name, mixed $value): void
    {
        $name = $this->normalizeName($name);
        $encoded = $this->encode($value);
        $now = App::now();

        $this->db->transaction(function () use ($name, $encoded, $now): void {
            $exists = $this->db->exists('settings', ['name' => $name]);

            if ($exists) {
                $this->db->update(
                    'settings',
                    ['value' => $encoded, 'updated_at' => $now],
                    ['name' => $name]
                );

                return;
            }

            try {
                $this->db->insert('settings', [
                    'name'       => $name,
                    'value'      => $encoded,
                    'updated_at' => $now,
                ]);
            } catch (\PDOException $e) {
                // A parallel request inserted the same key first — update it.
                if (!$this->db->exists('settings', ['name' => $name])) {
                    throw $e;
                }

                $this->db->update(
                    'settings',
                    ['value' => $encoded, 'updated_at' => $now],
                    ['name' => $name]
                );
            }
        });

        $this->cache = null;
    }

    /**
     * Every setting as name => decoded value.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * Delete one setting (the config.php value takes over again).
     */
    public function forget(string $name): void
    {
        $this->db->delete('settings', ['name' => $this->normalizeName($name)]);
        $this->cache = null;
    }

    /**
     * Is the setting stored in the database at all?
     */
    public function has(string $name): bool
    {
        return array_key_exists($this->normalizeName($name), $this->load());
    }

    /**
     * Drop the per request cache (after an external write, e.g. in tests).
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Read the whole (tiny) table once per request.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->db->fetchAll(
            'SELECT ' . $this->q('name') . ', ' . $this->q('value')
            . ' FROM ' . $this->settings()
            . ' ORDER BY ' . $this->q('name') . ' ASC'
        );

        $values = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $values[$name] = $this->decode($row['value'] ?? null);
        }

        return $this->cache = $values;
    }

    /**
     * JSON encode a value for storage; scalars stay readable in the database.
     */
    private function encode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new \InvalidArgumentException('This setting value cannot be JSON encoded.');
        }

        return $json;
    }

    /**
     * Decode a stored value; anything that is not valid JSON is returned as the
     * plain string it is (hand edited rows must not break the panel).
     */
    private function decode(mixed $raw): mixed
    {
        if ($raw === null) {
            return null;
        }

        $raw = (string) $raw;

        if (trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw new \InvalidArgumentException('A setting name cannot be empty.');
        }

        return mb_substr($name, 0, self::NAME_MAX, 'UTF-8');
    }

    /** Quoted + prefixed settings table. */
    private function settings(): string
    {
        return $this->db->quoteIdent($this->db->table('settings'));
    }

    /** Quoted column identifier. */
    private function q(string $column): string
    {
        return $this->db->quoteIdent($column);
    }
}
