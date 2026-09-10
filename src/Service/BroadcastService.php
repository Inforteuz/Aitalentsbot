<?php

declare(strict_types=1);

namespace AiTalents\Service;

use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Telegram\ApiException;
use AiTalents\Text;

/**
 * Queued, resumable message campaigns.
 *
 * A broadcast is prepared once (the audience is frozen into `broadcast_targets`)
 * and then delivered batch by batch. Nothing here loops until the whole audience
 * is served: {@see self::runBatch()} sends at most `$batchSize` messages and
 * returns, so the very same service can drive a webhook (a handful of batches
 * per callback), a cron job and the `fetch()` poller of the admin panel without
 * ever hitting an execution time limit.
 *
 * Telegram tolerates roughly 30 messages per second to different chats; the
 * sender keeps a 50 ms gap between two messages (~20/s) which stays comfortably
 * below that. Flood control answers (`retry_after`) are honoured once per
 * recipient, and a user who blocked the bot is flagged in `users.is_blocked` so
 * the next campaign skips them altogether.
 */
final class BroadcastService
{
    /** Pause between two deliveries — ~20 messages per second. */
    public const SPACING_US = 50000;

    /** Default number of recipients served by one {@see self::runBatch()} call. */
    public const DEFAULT_BATCH = 20;

    /** Upper bound for a single batch (a webhook must stay responsive). */
    private const MAX_BATCH = 100;

    /** Longest message a campaign may carry (Telegram's own limit is 4096). */
    private const MAX_TEXT = 4000;

    /** Never sleep longer than this when Telegram asks us to back off (seconds). */
    private const MAX_RETRY_AFTER = 30;

    /** Audience selectors accepted in the `audience` filter. */
    private const AUDIENCES = ['all', 'registered', 'status', 'district', 'direction'];

    /** Campaign statuses that will never deliver anything again. */
    private const TERMINAL = ['done', 'failed'];

    public function __construct(private App $app)
    {
    }

    /* --------------------------------------------------------------------
     | Audience
     */

    /**
     * Resolve the recipients of a campaign.
     *
     * Supported filters (every key is optional):
     *   - `audience`  — 'all' | 'registered' | 'status' | 'district' | 'direction'
     *   - `status`    — one status value, a comma separated list or a string[]
     *   - `district`  — a district key from the catalogue
     *   - `direction` — a direction key from the catalogue
     *
     * `all` means every bot user that did not block the bot; every other
     * selector walks the applications, so only people who actually registered
     * are addressed. When a concrete filter (status/district/direction) is
     * present it always wins over an `audience` of 'all' — asking for
     * "everybody from Asaka" can only ever mean the applicants from Asaka.
     *
     * @param array<string,mixed> $filters
     *
     * @return int[] Telegram ids, blocked users already removed.
     */
    public function audience(array $filters = []): array
    {
        $normalized = $this->normalizeFilters($filters);

        if ($normalized['audience'] === 'all' && $normalized['registration'] === []) {
            return $this->app->users()->activeTelegramIds();
        }

        return $this->app->registrations()->telegramIdsFor($normalized['registration']);
    }

    /**
     * How many people {@see self::audience()} would address — the number shown
     * next to the "send" button before anything is queued.
     *
     * @param array<string,mixed> $filters
     */
    public function audienceCount(array $filters = []): int
    {
        return count($this->audience($filters));
    }

    /* --------------------------------------------------------------------
     | Lifecycle
     */

    /**
     * Create a campaign, freeze its audience and mark it ready to run.
     *
     * A campaign without a single recipient is created anyway (so the attempt
     * stays visible in the history) but goes straight to "done".
     *
     * @param ?int                $adminId Telegram id of the author, null for the CLI.
     * @param array<string,mixed> $filters See {@see self::audience()}.
     *
     * @return int The broadcast id.
     *
     * @throws \InvalidArgumentException When the message text is empty.
     */
    public function prepare(?int $adminId, string $text, array $filters = []): int
    {
        $text = Text::clean($text, self::MAX_TEXT);

        if ($text === '') {
            throw new \InvalidArgumentException('A broadcast needs a message text.');
        }

        $normalized = $this->normalizeFilters($filters);
        $recipients = $this->audience($filters);
        $repository = $this->app->broadcasts();

        $id = $repository->create($adminId, $text, $normalized['stored'], 'HTML');
        $queued = $repository->addTargets($id, $recipients);

        $repository->setStatus($id, $queued > 0 ? 'running' : 'done');
        $repository->refreshCounters($id);

        $this->app->audit()->log(
            $this->actor($adminId),
            'broadcast_create',
            'broadcast:' . $id,
            ['total' => $queued, 'filters' => $normalized['stored']]
        );

        $this->app->logger()->info('Broadcast prepared', [
            'broadcast_id' => $id,
            'recipients'   => $queued,
            'admin_id'     => $adminId,
        ]);

        return $id;
    }

    /**
     * Deliver the next slice of a campaign.
     *
     * `done` is true when this campaign will not send anything more without an
     * explicit {@see self::resume()}: either every recipient was served or the
     * campaign is not in the "running" state (paused, cancelled or finished).
     * A caller may therefore loop `while (!$result['done'])` without any risk of
     * spinning forever.
     *
     * @return array{sent:int,failed:int,remaining:int,done:bool}
     */
    public function runBatch(int $broadcastId, int $batchSize = self::DEFAULT_BATCH): array
    {
        $repository = $this->app->broadcasts();
        $broadcast = $repository->find($broadcastId);

        if ($broadcast === null) {
            return ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'done' => true];
        }

        $status = (string) ($broadcast['status'] ?? '');
        $remaining = $repository->countTargets($broadcastId, 'pending');

        // Paused, cancelled, never started or already finished: hands off.
        if ($status !== 'running') {
            if ($remaining === 0 && !in_array($status, self::TERMINAL, true)) {
                $this->finish($broadcastId);
            }

            return ['sent' => 0, 'failed' => 0, 'remaining' => $remaining, 'done' => true];
        }

        if ($remaining === 0) {
            $this->finish($broadcastId);

            return ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'done' => true];
        }

        $targets = $repository->nextBatch($broadcastId, $this->clampBatch($batchSize));

        if ($targets === []) {
            $this->finish($broadcastId);

            return ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'done' => true];
        }

        $text = (string) ($broadcast['text'] ?? '');
        $parseMode = (string) ($broadcast['parse_mode'] ?? 'HTML');

        $sent = 0;
        $failed = 0;
        $position = 0;
        $count = count($targets);

        foreach ($targets as $target) {
            $position++;

            $targetId = (int) ($target['id'] ?? 0);
            $chatId = (int) ($target['telegram_id'] ?? 0);

            if ($targetId <= 0) {
                continue;
            }

            if ($chatId === 0) {
                $repository->markTarget($targetId, 'failed', 'invalid recipient');
                $failed++;

                continue;
            }

            $outcome = $this->deliver($chatId, $text, $parseMode);

            if ($outcome['ok']) {
                $repository->markTarget($targetId, 'sent');
                $sent++;
            } else {
                $repository->markTarget($targetId, 'failed', $outcome['error']);
                $failed++;

                if ($outcome['blocked']) {
                    $this->flagBlocked($chatId);
                }
            }

            // Stay under Telegram's rate limit; no pause after the last message.
            if ($position < $count) {
                usleep(self::SPACING_US);
            }
        }

        $repository->refreshCounters($broadcastId);

        $remaining = $repository->countTargets($broadcastId, 'pending');
        $done = $remaining === 0;

        if ($done) {
            $this->finish($broadcastId);
        }

        $this->app->logger()->debug('Broadcast batch finished', [
            'broadcast_id' => $broadcastId,
            'sent'         => $sent,
            'failed'       => $failed,
            'remaining'    => $remaining,
        ]);

        return ['sent' => $sent, 'failed' => $failed, 'remaining' => $remaining, 'done' => $done];
    }

    /**
     * Stop a running campaign.
     *
     * The queued recipients keep their "pending" row so the delivery report
     * stays honest and the campaign can still be resumed (or deleted) from the
     * admin panel; only the status changes, which is what makes
     * {@see self::runBatch()} refuse to send anything more.
     */
    public function cancel(int $broadcastId): void
    {
        $repository = $this->app->broadcasts();
        $broadcast = $repository->find($broadcastId);

        if ($broadcast === null) {
            return;
        }

        if (in_array((string) $broadcast['status'], self::TERMINAL, true)) {
            return;
        }

        $repository->setStatus($broadcastId, 'paused');
        $repository->refreshCounters($broadcastId);

        $this->app->audit()->log(
            $this->actor(isset($broadcast['admin_id']) ? (int) $broadcast['admin_id'] : null),
            'broadcast_cancel',
            'broadcast:' . $broadcastId
        );
    }

    /**
     * Put a paused campaign back to work (a finished one stays finished).
     */
    public function resume(int $broadcastId): void
    {
        $repository = $this->app->broadcasts();
        $broadcast = $repository->find($broadcastId);

        if ($broadcast === null || (string) $broadcast['status'] === 'running') {
            return;
        }

        if ($repository->countTargets($broadcastId, 'pending') === 0) {
            $this->finish($broadcastId);

            return;
        }

        $repository->setStatus($broadcastId, 'running');
    }

    /**
     * Everything a progress bar needs.
     *
     * `percent` counts processed recipients (delivered plus failed) and is
     * always between 0 and 100 — also for a campaign without recipients, where
     * a division would otherwise blow up.
     *
     * @return array{total:int,sent:int,failed:int,remaining:int,percent:float,status:string}
     */
    public function progress(int $broadcastId): array
    {
        $repository = $this->app->broadcasts();
        $broadcast = $repository->find($broadcastId);

        if ($broadcast === null) {
            return [
                'total'     => 0,
                'sent'      => 0,
                'failed'    => 0,
                'remaining' => 0,
                'percent'   => 0.0,
                'status'    => '',
            ];
        }

        $status = (string) ($broadcast['status'] ?? '');
        $sent = (int) ($broadcast['sent'] ?? 0);
        $failed = (int) ($broadcast['failed'] ?? 0);
        $remaining = $repository->countTargets($broadcastId, 'pending');
        $processed = $sent + $failed;

        // The stored total can lag behind when targets were added later on.
        $total = max((int) ($broadcast['total'] ?? 0), $processed + $remaining);

        if ($total > 0) {
            $percent = round($processed / $total * 100, 1);
        } else {
            $percent = in_array($status, self::TERMINAL, true) ? 100.0 : 0.0;
        }

        return [
            'total'     => $total,
            'sent'      => $sent,
            'failed'    => $failed,
            'remaining' => $remaining,
            'percent'   => max(0.0, min(100.0, (float) $percent)),
            'status'    => $status,
        ];
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * Send one message.
     *
     * Telegram's flood control is honoured exactly once per recipient: sleeping
     * a second time would block the whole batch behind a single chat.
     *
     * @return array{ok:bool,error:?string,blocked:bool}
     */
    private function deliver(int $chatId, string $text, string $parseMode): array
    {
        // A null parse_mode is dropped by the API client, which is how a
        // campaign asks for plain text.
        $extra = ['parse_mode' => $parseMode === '' ? null : $parseMode];
        $backedOff = false;

        while (true) {
            try {
                $this->app->api()->sendMessage($chatId, $text, $extra);

                return ['ok' => true, 'error' => null, 'blocked' => false];
            } catch (ApiException $e) {
                $retryAfter = $e->retryAfter();

                if ($retryAfter !== null && !$backedOff) {
                    $backedOff = true;
                    sleep(max(1, min(self::MAX_RETRY_AFTER, $retryAfter)));

                    continue;
                }

                if (!$e->isBlockedByUser()) {
                    $this->app->logger()->warning('Broadcast delivery failed', [
                        'chat_id' => $chatId,
                        'error'   => $e->description(),
                    ]);
                }

                return [
                    'ok'      => false,
                    'error'   => $e->description(),
                    'blocked' => $e->isBlockedByUser(),
                ];
            } catch (\Throwable $e) {
                $this->app->logger()->error('Unexpected broadcast failure', [
                    'chat_id' => $chatId,
                    'error'   => $e->getMessage(),
                ]);

                return ['ok' => false, 'error' => $e->getMessage(), 'blocked' => false];
            }
        }
    }

    /**
     * Remember that a recipient cannot receive messages any more.
     */
    private function flagBlocked(int $chatId): void
    {
        try {
            $this->app->users()->setBlocked($chatId, true);
        } catch (\Throwable $e) {
            $this->app->logger()->warning('Could not flag a blocked recipient', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark a campaign as finished and refresh its counters.
     */
    private function finish(int $broadcastId): void
    {
        $repository = $this->app->broadcasts();

        $repository->setStatus($broadcastId, 'done');
        $repository->refreshCounters($broadcastId);
    }

    /**
     * Split the incoming filter array into the audience selector, the filters
     * the registration repository understands and the copy stored on the
     * campaign row.
     *
     * @param array<string,mixed> $filters
     *
     * @return array{audience:string,registration:array<string,mixed>,stored:array<string,mixed>}
     */
    private function normalizeFilters(array $filters): array
    {
        $registration = [];

        $statuses = $this->statusFilter($filters['status'] ?? null);

        if ($statuses !== []) {
            $registration['status'] = $statuses;
        }

        $district = $this->key($filters['district'] ?? null, 48);

        if ($district !== null) {
            $registration['district'] = $district;
        }

        $direction = $this->key($filters['direction'] ?? null, 48);

        if ($direction !== null) {
            $registration['direction'] = $direction;
        }

        $audience = strtolower(trim((string) ($filters['audience'] ?? '')));

        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = $registration === [] ? 'all' : 'registered';
        }

        // An explicit filter always narrows the audience down to applicants.
        if ($audience === 'all' && $registration !== []) {
            $audience = 'registered';
        }

        $stored = $registration;
        $stored['audience'] = $audience;

        return [
            'audience'     => $audience,
            'registration' => $registration,
            'stored'       => $stored,
        ];
    }

    /**
     * Accept 'pending', 'pending,approved' or ['pending', 'approved'].
     *
     * @return string[] Known status values only.
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
     * A catalogue key from user input: trimmed, length limited, never an array.
     *
     * Unknown keys are kept on purpose — they simply match no application, which
     * is the safe direction (an audience of nobody instead of everybody).
     */
    private function key(mixed $value, int $max): ?string
    {
        if ($value === null || is_array($value) || is_bool($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max, 'UTF-8');
    }

    /**
     * Audit actor label of the campaign author.
     */
    private function actor(?int $adminId): string
    {
        return $adminId !== null && $adminId !== 0 ? 'tg:' . $adminId : 'system';
    }

    private function clampBatch(int $batchSize): int
    {
        return max(1, min(self::MAX_BATCH, $batchSize));
    }
}
