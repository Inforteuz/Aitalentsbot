<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Service\BroadcastService;
use AiTalents\Telegram\ApiException;
use AiTalents\Text;

/**
 * The broadcast wizard and the history of past campaigns.
 *
 * Delivery is never done in one request: `start` freezes the audience into
 * `broadcast_targets` and `run` sends exactly one batch, so admin/assets/app.js
 * can drive a long campaign by polling `?p=broadcast&a=run&id=..` while showing
 * a progress bar. Those polled endpoints answer JSON and nothing else — an HTML
 * error page in the middle of a poll would break the sender silently.
 *
 * The JSON contract (identical for `count`, `start`, `run`, and for `cancel`,
 * `resume` and `delete` when the caller asks for JSON):
 *
 *     { "ok": true,  "id": 12, "total": 340, "sent": 60, "failed": 2,
 *       "remaining": 278, "percent": 18.2, "status": "running", "done": false }
 *     { "ok": false, "error": "…" }
 */
final class BroadcastController
{
    /** Longest campaign text; mirrors BroadcastService's own limit. */
    private const MAX_TEXT = 4000;

    /** Audience selectors understood by BroadcastService. */
    private const AUDIENCES = ['all', 'registered', 'status', 'district', 'direction'];

    /** Campaigns listed per page on the history screen. */
    private const PER_PAGE = 20;

    /** Recipients served by one `run` call, and the hard bounds around it. */
    private const BATCH_DEFAULT = 20;
    private const BATCH_MIN = 1;
    private const BATCH_MAX = 100;

    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        if ($action === '') {
            $action = $this->view->currentPage() === 'broadcasts' ? 'list' : 'compose';
        }

        switch ($action) {
            case 'count':
                $this->count();
                break;

            case 'test':
                $this->test();
                break;

            case 'start':
                $this->start();
                break;

            case 'run':
                $this->run();
                break;

            case 'cancel':
            case 'pause':
                $this->cancel();
                break;

            case 'resume':
                $this->resume();
                break;

            case 'delete':
                $this->delete();
                break;

            case 'list':
                $this->list();
                break;

            case 'compose':
            default:
                $this->compose();
                break;
        }
    }

    /* --------------------------------------------------------------------
     | Screens
     */

    /**
     * The composer: message, audience filter, live recipient count.
     */
    public function compose(): void
    {
        $filters = $this->filters();
        $draft = $this->draft($filters);

        try {
            $audienceCount = $this->service()->audienceCount($filters);
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.audience_failed', ['error' => $e->getMessage()]);
            $this->flash('error', $this->t('error.db'));

            $audienceCount = 0;
        }

        $this->view->render('broadcast', [
            'title'         => $this->t('panel.broadcast_title'),
            'subtitle'      => $this->t('panel.broadcast_subtitle'),
            'draft'         => $draft,
            'audienceCount' => $audienceCount,
            'filters'       => $filters,
            'presets'       => $this->presets(),
            'statuses'      => $this->statusOptions(),
            'districts'     => $this->districtOptions(),
            'directions'    => $this->directionOptions(),
            'maxChars'      => self::MAX_TEXT,
            'batchSize'     => self::BATCH_DEFAULT,
            'active'        => $this->activeCampaign(),
            'locale'        => $this->locale(),
        ]);
    }

    /**
     * The history of past campaigns.
     */
    public function list(): void
    {
        $repository = $this->app->broadcasts();

        $total = $repository->countAll();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min(max(1, Request::int('page', 1)), $pages);

        $this->view->render('broadcasts', [
            'title'    => $this->t('panel.broadcasts_title'),
            'subtitle' => $this->t('panel.broadcast_subtitle'),
            'rows'     => $repository->paginate(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'perPage'  => self::PER_PAGE,
            'statusLabels' => $this->campaignStatusLabels(),
            'locale'   => $this->locale(),
        ]);
    }

    /* --------------------------------------------------------------------
     | JSON endpoints (polled by admin/assets/app.js)
     */

    /**
     * How many people the current audience filter would reach.
     */
    public function count(): void
    {
        try {
            $filters = $this->filters();
            $count = $this->service()->audienceCount($filters);

            Request::json([
                'ok'       => true,
                'count'    => $count,
                'audience' => $filters['audience'],
                'label'    => $this->t('panel.broadcast_recipients', ['count' => $count]),
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'panel.audience_failed');
        }
    }

    /**
     * Create the campaign and freeze its audience.
     */
    public function start(): void
    {
        if (!Request::isPost()) {
            Request::json(['ok' => false, 'error' => $this->t('panel.flash_error')], 405);
        }

        try {
            $text = $this->submittedText();

            if ($text === '') {
                Request::json(['ok' => false, 'error' => $this->t('panel.broadcast_empty_text')], 422);
            }

            $service = $this->service();
            $filters = $this->filters();

            if ($service->audienceCount($filters) === 0) {
                Request::json(['ok' => false, 'error' => $this->t('panel.broadcast_empty_audience')], 422);
            }

            $id = $service->prepare($this->authorId(), $text, $filters);

            $this->audit('broadcast.start', 'broadcast:' . $id, [
                'filters' => $filters,
                'length'  => mb_strlen($text, 'UTF-8'),
            ]);

            Request::json($this->envelope($id, $service->progress($id), ['done' => false]));
        } catch (\InvalidArgumentException $e) {
            Request::json(['ok' => false, 'error' => $this->t('panel.broadcast_empty_text')], 422);
        } catch (\Throwable $e) {
            $this->fail($e, 'panel.broadcast_start_failed');
        }
    }

    /**
     * Deliver exactly one batch and report the progress.
     */
    public function run(): void
    {
        if (!Request::isPost()) {
            Request::json(['ok' => false, 'error' => $this->t('panel.flash_error')], 405);
        }

        try {
            $id = Request::int('id');

            if ($id <= 0 || $this->app->broadcasts()->find($id) === null) {
                Request::json(['ok' => false, 'error' => $this->t('panel.flash_not_found')], 404);
            }

            $service = $this->service();
            $batch = $service->runBatch($id, $this->batchSize());

            Request::json($this->envelope($id, $service->progress($id), [
                'done'         => (bool) $batch['done'],
                'batch_sent'   => (int) $batch['sent'],
                'batch_failed' => (int) $batch['failed'],
            ]));
        } catch (\Throwable $e) {
            $this->fail($e, 'panel.broadcast_run_failed');
        }
    }

    /* --------------------------------------------------------------------
     | Actions
     */

    /**
     * Send the composed message to the first configured administrator only.
     */
    public function test(): void
    {
        $this->requirePost();

        $text = $this->submittedText();

        if ($text === '') {
            $this->respond(false, $this->t('panel.broadcast_empty_text'));

            return; // unreachable: respond() always ends the request
        }

        $adminId = $this->authorId();

        if ($adminId === null || !$this->app->api()->hasToken()) {
            $this->respond(false, $this->t('panel.set_admin_ids_hint'));

            return; // unreachable: respond() always ends the request
        }

        try {
            $this->app->api()->sendMessage($adminId, $text, ['parse_mode' => 'HTML']);

            $this->audit('broadcast.test', 'user:' . $adminId, ['length' => mb_strlen($text, 'UTF-8')]);

            $this->respond(true, $this->t('panel.broadcast_test_sent'));
        } catch (ApiException $e) {
            $this->app->logger()->warning('panel.broadcast_test_failed', ['error' => $e->description()]);

            $this->respond(false, $e->description());
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.broadcast_test_failed', ['error' => $e->getMessage()]);

            $this->respond(false, $this->t('error.telegram'));
        }
    }

    /**
     * Pause a running campaign (its queue survives, so it can be resumed).
     */
    public function cancel(): void
    {
        $this->requirePost();

        $id = Request::int('id');

        if ($id <= 0 || $this->app->broadcasts()->find($id) === null) {
            $this->respond(false, $this->t('panel.flash_not_found'), $id);

            return; // unreachable: respond() always ends the request
        }

        $service = $this->service();
        $service->cancel($id);

        $this->audit('broadcast.cancel', 'broadcast:' . $id);

        if (Request::wantsJson()) {
            Request::json($this->envelope($id, $service->progress($id), ['done' => true]));
        }

        $this->flash('success', $this->t('panel.broadcast_paused'));

        Request::redirect(View::link(['p' => 'broadcast', 'id' => $id]));
    }

    /**
     * Put a paused campaign back to work.
     */
    public function resume(): void
    {
        $this->requirePost();

        $id = Request::int('id');

        if ($id <= 0 || $this->app->broadcasts()->find($id) === null) {
            $this->respond(false, $this->t('panel.flash_not_found'), $id);

            return; // unreachable: respond() always ends the request
        }

        $service = $this->service();
        $service->resume($id);

        $this->audit('broadcast.resume', 'broadcast:' . $id);

        if (Request::wantsJson()) {
            Request::json($this->envelope($id, $service->progress($id), ['done' => false]));
        }

        $this->flash('success', $this->t('panel.broadcast_running'));

        Request::redirect(View::link(['p' => 'broadcast', 'id' => $id]));
    }

    /**
     * Delete a campaign together with its target rows.
     */
    public function delete(): void
    {
        $this->requirePost();

        $id = Request::int('id');

        if ($id <= 0 || !$this->app->broadcasts()->deleteById($id)) {
            $this->respond(false, $this->t('panel.flash_not_found'), $id);

            return; // unreachable: respond() always ends the request
        }

        $this->audit('broadcast.delete', 'broadcast:' . $id);

        if (Request::wantsJson()) {
            Request::json(['ok' => true, 'id' => $id]);
        }

        $this->flash('success', $this->t('panel.flash_deleted'));

        Request::redirect(View::link(['p' => 'broadcasts']));
    }

    /* --------------------------------------------------------------------
     | Input
     */

    /**
     * The whitelisted audience filter of the current request.
     *
     * @return array<string,string>
     */
    private function filters(): array
    {
        $filters = [];

        $status = RegistrationStatus::tryOrNull(Request::str('status'));

        if ($status !== null) {
            $filters['status'] = $status->value;
        }

        $district = Request::str('district');

        if ($district !== '' && Catalog::hasDistrict($district)) {
            $filters['district'] = $district;
        }

        $direction = Request::str('direction');

        if ($direction !== '' && Catalog::hasDirection($direction)) {
            $filters['direction'] = $direction;
        }

        $audience = strtolower(Request::str('audience'));

        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = $filters === [] ? 'all' : 'registered';
        }

        $filters['audience'] = $audience;

        return $filters;
    }

    /**
     * The composed message, cleaned exactly the way the sender will store it.
     */
    private function submittedText(): string
    {
        $raw = Request::post('text', '');

        return Text::clean(is_string($raw) ? $raw : '', self::MAX_TEXT);
    }

    /**
     * Number of recipients one `run` call is allowed to serve.
     */
    private function batchSize(): int
    {
        $batch = Request::int('batch', self::BATCH_DEFAULT);

        return max(self::BATCH_MIN, min(self::BATCH_MAX, $batch));
    }

    /**
     * The Telegram id recorded as the author of a campaign, and the recipient of
     * the "test send to me" button: the first administrator from config.php.
     */
    private function authorId(): ?int
    {
        $raw = $this->app->config('telegram.admin_ids', []);

        if (is_array($raw)) {
            foreach ($raw as $id) {
                if (is_int($id) || (is_string($id) && preg_match('/^-?\d+$/', trim($id)) === 1)) {
                    $id = (int) $id;

                    if ($id !== 0) {
                        return $id;
                    }
                }
            }
        }

        // Nothing in config.php: fall back to an administrator flagged in the DB.
        $ids = $this->app->adminIds();

        return $ids === [] ? null : (int) $ids[0];
    }

    /* --------------------------------------------------------------------
     | View data
     */

    /**
     * The form state the composer renders.
     *
     * @param array<string,string> $filters
     * @return array<string,string>
     */
    private function draft(array $filters): array
    {
        $text = function_exists('old') ? old('text', '') : '';

        return [
            'text'      => is_string($text) ? $text : '',
            'audience'  => $filters['audience'],
            'status'    => $filters['status'] ?? '',
            'district'  => $filters['district'] ?? '',
            'direction' => $filters['direction'] ?? '',
        ];
    }

    /**
     * The audience presets offered by the radio group.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function presets(): array
    {
        $presets = [];

        foreach (self::AUDIENCES as $audience) {
            $presets[] = [
                'value' => $audience,
                'label' => $this->t('panel.broadcast_audience_' . $audience),
            ];
        }

        return $presets;
    }

    /**
     * The campaign the composer should show a progress bar for, if any.
     *
     * @return ?array<string,mixed>
     */
    private function activeCampaign(): ?array
    {
        $id = Request::int('id');

        try {
            if ($id <= 0) {
                $running = $this->app->broadcasts()->running(1);

                if ($running === []) {
                    return null;
                }

                $id = (int) $running[0]['id'];
            }

            $campaign = $this->app->broadcasts()->find($id);

            if ($campaign === null) {
                return null;
            }

            return ['id' => $id, 'campaign' => $campaign, 'progress' => $this->service()->progress($id)];
        } catch (\Throwable $e) {
            $this->app->logger()->warning('panel.active_broadcast_failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (RegistrationStatus::cases() as $status) {
            $options[] = [
                'value' => $status->value,
                'label' => Lang::t($status->labelKey(), $this->locale()),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{key:string,label:string,type:string}>
     */
    private function districtOptions(): array
    {
        $locale = $this->locale();
        $options = [];

        foreach (Catalog::districts() as $key => $district) {
            $options[] = [
                'key'   => (string) $key,
                'label' => Catalog::districtLabel((string) $key, $locale),
                'type'  => (string) ($district['type'] ?? 'district'),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{key:string,label:string,emoji:string}>
     */
    private function directionOptions(): array
    {
        $locale = $this->locale();
        $options = [];

        foreach (Catalog::directionKeys() as $key) {
            $options[] = [
                'key'   => $key,
                'label' => Catalog::directionLabel($key, $locale, false),
                'emoji' => Catalog::directionEmoji($key),
            ];
        }

        return $options;
    }

    /**
     * Campaign status => translated badge caption.
     *
     * @return array<string,string>
     */
    private function campaignStatusLabels(): array
    {
        return [
            'draft'   => $this->t('panel.broadcast_draft'),
            'running' => $this->t('panel.broadcast_running'),
            'paused'  => $this->t('panel.broadcast_paused'),
            'done'    => $this->t('panel.broadcast_done'),
            'failed'  => $this->t('panel.broadcast_failed'),
        ];
    }

    /* --------------------------------------------------------------------
     | Responses
     */

    /**
     * The JSON body every progress-aware endpoint returns.
     *
     * @param array{total:int,sent:int,failed:int,remaining:int,percent:float,status:string} $progress
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function envelope(int $id, array $progress, array $extra = []): array
    {
        return array_merge(
            [
                'ok'        => true,
                'id'        => $id,
                'total'     => (int) $progress['total'],
                'sent'      => (int) $progress['sent'],
                'failed'    => (int) $progress['failed'],
                'remaining' => (int) $progress['remaining'],
                'percent'   => (float) $progress['percent'],
                'status'    => (string) $progress['status'],
            ],
            $extra
        );
    }

    /**
     * Answer a form action either as JSON (fetch) or as a flash + redirect.
     */
    private function respond(bool $ok, string $message, int $id = 0): void
    {
        if (Request::wantsJson()) {
            Request::json(
                $ok ? ['ok' => true, 'id' => $id, 'message' => $message] : ['ok' => false, 'error' => $message],
                $ok ? 200 : 422
            );
        }

        $this->flash($ok ? 'success' : 'error', $message);

        Request::redirect(View::link($id > 0 ? ['p' => 'broadcast', 'id' => $id] : ['p' => 'broadcast']));
    }

    /**
     * Log an unexpected failure and answer the poller with JSON, never HTML.
     */
    private function fail(\Throwable $e, string $context): void
    {
        $this->app->logger()->error($context, ['error' => $e->getMessage()]);

        Request::json(['ok' => false, 'error' => $this->t('error.generic')], 500);
    }

    /* --------------------------------------------------------------------
     | Plumbing
     */

    private function service(): BroadcastService
    {
        return new BroadcastService($this->app);
    }

    private function requirePost(): void
    {
        if (Request::isPost()) {
            return;
        }

        if (Request::wantsJson()) {
            Request::json(['ok' => false, 'error' => $this->t('panel.flash_error')], 405);
        }

        $this->flash('error', $this->t('panel.flash_error'));

        Request::redirect(View::link(['p' => 'broadcast']));
    }

    private function actor(): string
    {
        $user = function_exists('panel_auth') ? panel_auth()->user() : null;

        return 'panel:' . ($user !== null && $user !== '' ? $user : 'unknown');
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function audit(string $action, ?string $target = null, array $meta = []): void
    {
        $this->app->audit()->log($this->actor(), $action, $target, $meta, Request::ip());
    }

    private function flash(string $type, string $message): void
    {
        if (function_exists('flash') && $message !== '') {
            flash($type, $message);
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    private function t(string $key, array $params = []): string
    {
        return Lang::t($key, $this->locale(), $params);
    }

    private function locale(): string
    {
        if (function_exists('panel_locale')) {
            return panel_locale();
        }

        return $this->app->defaultLocale();
    }
}
