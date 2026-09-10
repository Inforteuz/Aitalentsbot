<?php

declare(strict_types=1);

namespace AiTalents\Repository;

use AiTalents\App;
use AiTalents\Database;

/**
 * Broadcast campaigns and their per recipient delivery rows.
 *
 * A broadcast owns N rows in `broadcast_targets`; the sender walks them batch
 * by batch (see BroadcastService) and marks every target sent or failed, so a
 * cron job or the admin panel can resume exactly where it stopped.
 */
final class BroadcastRepository
{
    /** Lifecycle of a campaign. */
    private const STATUSES = ['draft', 'running', 'paused', 'done', 'failed'];

    /** Lifecycle of one recipient row. */
    private const TARGET_STATUSES = ['pending', 'sent', 'failed'];

    /** Parse modes Telegram accepts ('' means plain text). */
    private const PARSE_MODES = ['HTML', 'Markdown', 'MarkdownV2', ''];

    /** How many rows go into a single multi-row INSERT. */
    private const CHUNK = 200;

    public function __construct(private Database $db)
    {
    }

    /**
     * Create a campaign in the "draft" state and return its id.
     *
     * @param array<string,mixed> $filters audience filter, stored as JSON
     */
    public function create(?int $adminId, string $text, array $filters = [], string $parseMode = 'HTML'): int
    {
        $text = trim($text);

        if ($text === '') {
            throw new \InvalidArgumentException('A broadcast needs a message text.');
        }

        $now = App::now();

        return $this->db->insert('broadcasts', [
            'admin_id'    => $adminId !== null && $adminId !== 0 ? $adminId : null,
            'text'        => $text,
            'parse_mode'  => $this->normalizeParseMode($parseMode),
            'filters'     => $this->encodeFilters($filters),
            'status'      => 'draft',
            'total'       => 0,
            'sent'        => 0,
            'failed'      => 0,
            'created_at'  => $now,
            'updated_at'  => $now,
            'finished_at' => null,
        ]);
    }

    /**
     * One campaign with `filters` decoded and the counters cast to int.
     *
     * @return ?array<string,mixed>
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->fetch(
            'SELECT * FROM ' . $this->broadcasts() . ' WHERE ' . $this->q('id') . ' = ? LIMIT 1',
            [$id]
        );

        return $row === null ? null : $this->decode($row);
    }

    /**
     * Queue recipients for a campaign.
     *
     * Ids are de-duplicated (also against the rows already queued) and written
     * in multi-row INSERTs of self::CHUNK rows, which keeps a 50k recipient
     * broadcast to a few hundred statements inside one transaction.
     *
     * @param array<int,int|string> $telegramIds
     * @return int how many target rows were created
     */
    public function addTargets(int $broadcastId, array $telegramIds): int
    {
        if ($broadcastId <= 0 || $telegramIds === []) {
            return 0;
        }

        $ids = [];

        foreach ($telegramIds as $id) {
            $id = is_numeric($id) ? (int) $id : 0;

            if ($id !== 0) {
                $ids[$id] = $id;
            }
        }

        if ($ids === []) {
            return 0;
        }

        /** @var int $inserted */
        $inserted = $this->db->transaction(function () use ($broadcastId, $ids): int {
            // Skip recipients that are already queued for this campaign.
            $existing = $this->db->fetchAll(
                'SELECT ' . $this->q('telegram_id') . ' FROM ' . $this->targets()
                . ' WHERE ' . $this->q('broadcast_id') . ' = ?',
                [$broadcastId]
            );

            foreach ($existing as $row) {
                unset($ids[(int) ($row['telegram_id'] ?? 0)]);
            }

            $ids = array_values($ids);

            if ($ids === []) {
                return 0;
            }

            $sqlHead = 'INSERT INTO ' . $this->targets() . ' ('
                . $this->q('broadcast_id') . ', '
                . $this->q('telegram_id') . ', '
                . $this->q('status') . ', '
                . $this->q('error') . ', '
                . $this->q('sent_at')
                . ') VALUES ';

            $count = 0;

            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                $params = [];
                $tuples = [];

                foreach ($chunk as $telegramId) {
                    $tuples[] = '(?, ?, ?, NULL, NULL)';
                    $params[] = $broadcastId;
                    $params[] = $telegramId;
                    $params[] = 'pending';
                }

                $this->db->query($sqlHead . implode(', ', $tuples), $params);
                $count += count($chunk);
            }

            $this->db->update(
                'broadcasts',
                [
                    'total'      => $this->countTargets($broadcastId),
                    'updated_at' => App::now(),
                ],
                ['id' => $broadcastId]
            );

            return $count;
        });

        return $inserted;
    }

    /**
     * The next recipients still waiting to be delivered.
     *
     * @return array<int,array<string,mixed>>
     */
    public function nextBatch(int $broadcastId, int $limit = 25): array
    {
        if ($broadcastId <= 0) {
            return [];
        }

        $limit = max(1, min(500, $limit));

        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->targets()
            . ' WHERE ' . $this->q('broadcast_id') . ' = ?'
            . ' AND ' . $this->q('status') . ' = ?'
            . ' ORDER BY ' . $this->q('id') . ' ASC'
            // Clamped integer — inlined so the statement works on both drivers.
            . ' LIMIT ' . $limit,
            [$broadcastId, 'pending']
        );

        foreach ($rows as $index => $row) {
            $rows[$index]['id'] = (int) ($row['id'] ?? 0);
            $rows[$index]['broadcast_id'] = (int) ($row['broadcast_id'] ?? 0);
            $rows[$index]['telegram_id'] = (int) ($row['telegram_id'] ?? 0);
        }

        return $rows;
    }

    /**
     * Record the delivery result of one recipient.
     */
    public function markTarget(int $targetId, string $status, ?string $error = null): void
    {
        if ($targetId <= 0) {
            return;
        }

        $status = strtolower(trim($status));

        if (!in_array($status, self::TARGET_STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown broadcast target status: ' . $status);
        }

        $error = $error === null ? null : trim($error);

        $this->db->update(
            'broadcast_targets',
            [
                'status'  => $status,
                'error'   => $error === null || $error === '' ? null : mb_substr($error, 0, 255, 'UTF-8'),
                'sent_at' => $status === 'pending' ? null : App::now(),
            ],
            ['id' => $targetId]
        );
    }

    /**
     * Recount total/sent/failed from the target rows and return the fresh row.
     *
     * The status itself is left alone (the service owns the lifecycle), only a
     * finished campaign gets its `finished_at` stamp filled in.
     *
     * @return array<string,mixed> the broadcast row, [] when it is gone
     */
    public function refreshCounters(int $broadcastId): array
    {
        if ($broadcastId <= 0) {
            return [];
        }

        $current = $this->find($broadcastId);

        if ($current === null) {
            return [];
        }

        $patch = [
            'total'      => $this->countTargets($broadcastId),
            'sent'       => $this->countTargets($broadcastId, 'sent'),
            'failed'     => $this->countTargets($broadcastId, 'failed'),
            'updated_at' => App::now(),
        ];

        if (
            in_array((string) $current['status'], ['done', 'failed'], true)
            && ($current['finished_at'] ?? null) === null
        ) {
            $patch['finished_at'] = App::now();
        }

        $this->db->update('broadcasts', $patch, ['id' => $broadcastId]);

        return $this->find($broadcastId) ?? [];
    }

    /**
     * Move a campaign through its lifecycle.
     */
    public function setStatus(int $broadcastId, string $status): void
    {
        if ($broadcastId <= 0) {
            return;
        }

        $status = strtolower(trim($status));

        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown broadcast status: ' . $status);
        }

        $patch = [
            'status'     => $status,
            'updated_at' => App::now(),
        ];

        // Finished campaigns get a timestamp; a resumed one loses it again.
        $patch['finished_at'] = in_array($status, ['done', 'failed'], true) ? App::now() : null;

        $this->db->update('broadcasts', $patch, ['id' => $broadcastId]);
    }

    /**
     * One page of campaigns, newest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public function paginate(int $limit = 20, int $offset = 0): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->broadcasts()
            . ' ORDER BY ' . $this->q('created_at') . ' DESC, ' . $this->q('id') . ' DESC'
            // Clamped integers, inlined for driver portability.
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset
        );

        $decoded = [];

        foreach ($rows as $row) {
            $decoded[] = $this->decode($row);
        }

        return $decoded;
    }

    public function countAll(): int
    {
        return $this->db->count('broadcasts');
    }

    /**
     * Delete a campaign together with all of its target rows.
     */
    public function deleteById(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        /** @var bool $deleted */
        $deleted = $this->db->transaction(function () use ($id): bool {
            $this->db->delete('broadcast_targets', ['broadcast_id' => $id]);

            return $this->db->delete('broadcasts', ['id' => $id]) > 0;
        });

        return $deleted;
    }

    /**
     * How many recipients a campaign has (optionally in one status).
     */
    public function countTargets(int $broadcastId, ?string $status = null): int
    {
        if ($broadcastId <= 0) {
            return 0;
        }

        $where = ['broadcast_id' => $broadcastId];

        if ($status !== null) {
            $status = strtolower(trim($status));

            if (!in_array($status, self::TARGET_STATUSES, true)) {
                throw new \InvalidArgumentException('Unknown broadcast target status: ' . $status);
            }

            $where['status'] = $status;
        }

        return $this->db->count('broadcast_targets', $where);
    }

    /**
     * The campaigns that still have work to do (cron entry point).
     *
     * @return array<int,array<string,mixed>>
     */
    public function running(int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));

        $rows = $this->db->fetchAll(
            'SELECT * FROM ' . $this->broadcasts()
            . ' WHERE ' . $this->q('status') . ' = ?'
            . ' ORDER BY ' . $this->q('id') . ' ASC'
            . ' LIMIT ' . $limit,
            ['running']
        );

        $decoded = [];

        foreach ($rows as $row) {
            $decoded[] = $this->decode($row);
        }

        return $decoded;
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function decode(array $row): array
    {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['admin_id'] = isset($row['admin_id']) && $row['admin_id'] !== null ? (int) $row['admin_id'] : null;
        $row['total'] = (int) ($row['total'] ?? 0);
        $row['sent'] = (int) ($row['sent'] ?? 0);
        $row['failed'] = (int) ($row['failed'] ?? 0);

        $filters = $row['filters'] ?? null;

        if (is_string($filters) && trim($filters) !== '') {
            $decoded = json_decode($filters, true);
            $row['filters'] = is_array($decoded) ? $decoded : [];
        } else {
            $row['filters'] = [];
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function encodeFilters(array $filters): ?string
    {
        if ($filters === []) {
            return null;
        }

        $json = json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? null : $json;
    }

    private function normalizeParseMode(string $parseMode): string
    {
        $parseMode = trim($parseMode);

        foreach (self::PARSE_MODES as $mode) {
            if (strcasecmp($mode, $parseMode) === 0) {
                return $mode;
            }
        }

        return 'HTML';
    }

    /** Quoted + prefixed broadcasts table. */
    private function broadcasts(): string
    {
        return $this->db->quoteIdent($this->db->table('broadcasts'));
    }

    /** Quoted + prefixed broadcast_targets table. */
    private function targets(): string
    {
        return $this->db->quoteIdent($this->db->table('broadcast_targets'));
    }

    /** Quoted column identifier. */
    private function q(string $column): string
    {
        return $this->db->quoteIdent($column);
    }
}
