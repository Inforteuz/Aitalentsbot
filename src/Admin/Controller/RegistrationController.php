<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;
use AiTalents\Telegram\ApiException;
use AiTalents\Text;

/**
 * Applications: the searchable list, the detail screen and every moderation
 * action the panel offers.
 *
 * Two pages of the front controller land here — `?p=registrations` (the list)
 * and `?p=registration` (one application) — which is why the default action is
 * derived from the current page instead of being hard-coded.
 *
 * Every mutating action requires POST (the CSRF token is verified by the front
 * controller before this class is instantiated), writes an audit entry and then
 * redirects back to the screen the operator came from, filters and page number
 * included.
 */
final class RegistrationController
{
    /** Columns the list may be ordered by — mirrors RegistrationRepository. */
    private const SORTABLE = ['id', 'created_at', 'full_name', 'district', 'status'];

    /** Page sizes offered by the selector. */
    private const PER_PAGE = [25, 50, 100];

    /** Query string keys carried across a redirect. */
    private const QUERY_KEYS = [
        'q',
        'status',
        'district',
        'direction',
        'date_from',
        'date_to',
        'sort',
        'dir',
        'page',
        'per_page',
    ];

    /** Longest search term accepted from the search box. */
    private const SEARCH_MAX = 120;

    /** Longest internal note stored with an application. */
    private const NOTE_MAX = 2000;

    /** Longest message the "write to this user" box may send. */
    private const MESSAGE_MAX = 3800;

    /** Upper bound for one bulk operation. */
    private const BULK_MAX = 500;

    /** Entries of the per-application audit trail shown on the detail screen. */
    private const AUDIT_LIMIT = 50;

    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        if ($action === '') {
            // ?p=registration (singular) is the detail screen.
            $action = $this->view->currentPage() === 'registration' ? 'view' : 'list';
        }

        switch ($action) {
            case 'view':
                $this->view();
                break;

            case 'approve':
                $this->moderate(RegistrationStatus::Approved);
                break;

            case 'reject':
                $this->moderate(RegistrationStatus::Rejected);
                break;

            case 'delete':
                $this->delete();
                break;

            case 'bulk':
                $this->bulk();
                break;

            case 'note':
                $this->note();
                break;

            case 'message':
                $this->message();
                break;

            case 'list':
            default:
                $this->list();
                break;
        }
    }

    /* --------------------------------------------------------------------
     | Screens
     */

    /**
     * The paginated, filterable list of applications.
     */
    public function list(): void
    {
        $repository = $this->app->registrations();

        $filters = $this->filters();
        $sort    = $this->sort();
        $dir     = $this->direction();
        $perPage = $this->perPage();

        $total = $repository->countAll($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, Request::int('page', 1)), $pages);

        $rows = $repository->paginate($filters, $perPage, ($page - 1) * $perPage, $sort, $dir);

        $this->view->render('registrations', [
            'title'      => $this->t('panel.registrations_title'),
            'subtitle'   => $this->t('panel.registrations_subtitle'),
            'rows'       => $rows,
            'total'      => $total,
            'page'       => $page,
            'pages'      => $pages,
            'perPage'    => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'filters'    => $filters,
            'sort'       => $sort,
            'dir'        => $dir,
            'sortable'   => self::SORTABLE,
            'districts'  => $this->districtOptions(),
            'directions' => $this->directionOptions(),
            'statuses'   => $this->statusOptions(),
            'query'      => $this->currentQuery(),
            'locale'     => $this->locale(),
        ]);
    }

    /**
     * One application with its owner and its audit trail.
     */
    public function view(): void
    {
        $registration = $this->findRegistration(Request::int('id'));

        if ($registration === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));

            Request::redirect(View::link(['p' => 'registrations']));
        }

        $id = (int) $registration['id'];
        $telegramId = (int) ($registration['telegram_id'] ?? 0);

        $user = null;

        if ($telegramId !== 0) {
            $user = $this->app->users()->findByTelegramId($telegramId);
        }

        $status = RegistrationStatus::tryOrNull((string) ($registration['status'] ?? ''))
            ?? RegistrationStatus::default();

        $this->view->render('registration_view', [
            'title'           => $this->t('panel.registration_title', ['id' => $id]),
            'subtitle'        => (string) ($registration['full_name'] ?? ''),
            'reg'             => $registration,
            'user'            => $user,
            'audit'           => $this->app->audit()->forTarget($this->target($id), self::AUDIT_LIMIT),
            'status'          => $this->statusOption($status),
            'statuses'        => $this->statusOptions(),
            'directionLabels' => $this->directionLabels($registration),
            'districtLabel'   => $this->districtLabel($registration),
            'links'           => $this->portfolioLinks($registration),
            'query'           => $this->currentQuery(),
            'locale'          => $this->locale(),
        ]);
    }

    /* --------------------------------------------------------------------
     | Moderation
     */

    /**
     * Approve or reject one application and tell its owner about it.
     */
    public function moderate(RegistrationStatus $status): void
    {
        $this->requirePost();

        $registration = $this->findRegistration(Request::int('id'));

        if ($registration === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));
            $this->back();

            return; // unreachable: back() always redirects
        }

        $id = (int) $registration['id'];
        $note = $this->submittedNote();

        $changed = $this->app->registrations()->setStatus($id, $status->value, $this->actor(), $note);

        if (!$changed) {
            $this->flash('error', $this->t('panel.flash_error'));
            $this->back();
        }

        $notified = $this->notifyApplicant($registration, $status);

        $this->audit('registration.' . $status->value, $this->target($id), [
            'status'   => $status->value,
            'notified' => $notified,
            'note'     => $note !== null,
        ]);

        $this->flash(
            'success',
            $status === RegistrationStatus::Approved
                ? $this->t('panel.flash_approved')
                : $this->t('panel.flash_rejected')
        );

        if (!$notified) {
            $this->flash('warning', $this->t('admin.user_notify_failed'));
        }

        $this->back();
    }

    /**
     * Save the internal note, optionally together with a new status.
     */
    public function note(): void
    {
        $this->requirePost();

        $registration = $this->findRegistration(Request::int('id'));

        if ($registration === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));
            $this->back();

            return; // unreachable: back() always redirects
        }

        $id = (int) $registration['id'];
        $note = $this->submittedNote();
        $status = RegistrationStatus::tryOrNull(Request::str('status'));

        if ($status !== null && $status->value !== (string) ($registration['status'] ?? '')) {
            // The status form carries the note: one write covers both.
            $this->app->registrations()->setStatus($id, $status->value, $this->actor(), $note);
            $notified = $this->notifyApplicant($registration, $status);

            $this->audit('registration.status', $this->target($id), [
                'status'   => $status->value,
                'notified' => $notified,
            ]);
        } elseif ($note !== null) {
            // Note only: save() writes exactly the columns it is given.
            $this->app->registrations()->save(
                (int) ($registration['user_id'] ?? 0),
                (int) ($registration['telegram_id'] ?? 0),
                ['admin_note' => $note]
            );

            $this->audit('registration.note', $this->target($id), [
                'length' => mb_strlen($note, 'UTF-8'),
            ]);
        } else {
            // Neither a new status nor a note field was submitted.
            $this->flash('warning', $this->t('panel.flash_error'));
            $this->back();
        }

        $this->flash('success', $this->t('panel.flash_saved'));
        $this->back();
    }

    /**
     * Delete one application (the bot user itself is kept).
     */
    public function delete(): void
    {
        $this->requirePost();

        $id = Request::int('id');
        $registration = $this->findRegistration($id);

        if ($registration === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));
            $this->back();

            return; // unreachable: back() always redirects
        }

        if (!$this->app->registrations()->deleteById($id)) {
            $this->flash('error', $this->t('panel.flash_error'));
            $this->back();
        }

        $this->audit('registration.delete', $this->target($id), [
            'telegram_id' => (int) ($registration['telegram_id'] ?? 0),
            'full_name'   => (string) ($registration['full_name'] ?? ''),
        ]);

        $this->flash('success', $this->t('panel.flash_deleted'));

        // The detail screen of a deleted row cannot be rendered any more.
        Request::redirect(View::link($this->currentQuery(['p' => 'registrations', 'id' => null])));
    }

    /**
     * Approve, reject or delete every checked row of the list.
     */
    public function bulk(): void
    {
        $this->requirePost();

        $ids = $this->submittedIds();
        $operation = strtolower(Request::str('bulk'));

        if (!in_array($operation, ['approve', 'reject', 'delete'], true)) {
            $this->flash('error', $this->t('error.invalid_choice'));
            $this->back();
        }

        if ($ids === []) {
            $this->flash('warning', $this->t('panel.bulk_none_selected'));
            $this->back();
        }

        $repository = $this->app->registrations();
        $status = $operation === 'approve'
            ? RegistrationStatus::Approved
            : ($operation === 'reject' ? RegistrationStatus::Rejected : null);

        $affected = 0;

        foreach ($ids as $id) {
            if ($status === null) {
                if ($repository->deleteById($id)) {
                    $affected++;
                }

                continue;
            }

            if ($repository->setStatus($id, $status->value, $this->actor())) {
                $affected++;
            }
        }

        $this->audit('registration.bulk_' . $operation, null, [
            'ids'      => array_slice($ids, 0, 50),
            'count'    => count($ids),
            'affected' => $affected,
        ]);

        $this->flash('success', $this->t('panel.bulk_done', ['count' => $affected]));
        $this->back();
    }

    /**
     * Send a personal message to the applicant through the bot.
     */
    public function message(): void
    {
        $this->requirePost();

        $registration = $this->findRegistration(Request::int('id'));

        if ($registration === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));
            $this->back();

            return; // unreachable: back() always redirects
        }

        $id = (int) $registration['id'];
        $chatId = (int) ($registration['telegram_id'] ?? 0);
        $raw = Request::post('message', '');

        // Escaped, newline preserving and length capped: what the operator types
        // is content, never markup.
        $text = Text::multiline(is_string($raw) ? $raw : '', self::MESSAGE_MAX);

        if ($text === '') {
            $this->flash('error', $this->t('panel.detail_message_empty'));
            $this->back();
        }

        if ($chatId === 0 || !$this->app->api()->hasToken()) {
            $this->flash('error', $this->t('panel.detail_message_failed', ['error' => $this->t('panel.not_available')]));
            $this->back();
        }

        try {
            $this->app->api()->sendMessage($chatId, $text);

            $this->audit('registration.message', $this->target($id), [
                'telegram_id' => $chatId,
                'length'      => mb_strlen($text, 'UTF-8'),
            ]);

            $this->flash('success', $this->t('panel.detail_message_sent'));
        } catch (ApiException $e) {
            $this->app->logger()->warning('panel.message_failed', [
                'registration' => $id,
                'error'        => $e->description(),
            ]);

            $this->flash('error', $this->t('panel.detail_message_failed', ['error' => $e->description()]));
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.message_failed', [
                'registration' => $id,
                'error'        => $e->getMessage(),
            ]);

            $this->flash('error', $this->t('panel.detail_message_failed', ['error' => $this->t('error.telegram')]));
        }

        $this->back();
    }

    /* --------------------------------------------------------------------
     | Input
     */

    /**
     * The whitelisted filter set of the current request.
     *
     * Unknown districts, directions, statuses and malformed dates are dropped
     * rather than passed on, so the repository only ever sees values that exist.
     *
     * @return array<string,string>
     */
    private function filters(): array
    {
        $filters = [];

        $search = mb_substr(Request::str('q'), 0, self::SEARCH_MAX, 'UTF-8');

        if ($search !== '') {
            $filters['q'] = $search;
        }

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

        $from = $this->date(Request::str('date_from'));

        if ($from !== null) {
            $filters['date_from'] = $from;
        }

        $to = $this->date(Request::str('date_to'));

        if ($to !== null) {
            $filters['date_to'] = $to;
        }

        return $filters;
    }

    /**
     * A 'Y-m-d' date, or null when the value is missing or impossible.
     */
    private function date(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    private function sort(): string
    {
        $sort = strtolower(Request::str('sort'));

        return in_array($sort, self::SORTABLE, true) ? $sort : 'created_at';
    }

    private function direction(): string
    {
        return strtolower(Request::str('dir')) === 'asc' ? 'asc' : 'desc';
    }

    private function perPage(): int
    {
        $perPage = Request::int('per_page', self::PER_PAGE[0]);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[0];
    }

    /**
     * The checked ids of a bulk operation, deduplicated and capped.
     *
     * @return int[]
     */
    private function submittedIds(): array
    {
        $raw = Request::post('ids', []);

        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $ids = [];

        foreach ($raw as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $id = (int) $value;

            if ($id > 0) {
                $ids[$id] = $id;
            }

            if (count($ids) >= self::BULK_MAX) {
                break;
            }
        }

        return array_values($ids);
    }

    /**
     * The internal note of the submitted form, or null when the field was absent.
     *
     * An empty field means "clear the note", which is why it returns an empty
     * string instead of null in that case.
     */
    private function submittedNote(): ?string
    {
        $raw = Request::post('note');

        if ($raw === null || !is_scalar($raw)) {
            return null;
        }

        return Text::clean((string) $raw, self::NOTE_MAX);
    }

    /* --------------------------------------------------------------------
     | Helpers
     */

    /**
     * @return ?array<string,mixed>
     */
    private function findRegistration(int $id): ?array
    {
        return $id > 0 ? $this->app->registrations()->findById($id) : null;
    }

    /**
     * Tell an applicant that their application was approved or rejected.
     *
     * @param array<string,mixed> $registration
     */
    private function notifyApplicant(array $registration, RegistrationStatus $status): bool
    {
        $chatId = (int) ($registration['telegram_id'] ?? 0);

        if ($chatId === 0 || !$this->app->api()->hasToken()) {
            return false;
        }

        $key = match ($status) {
            RegistrationStatus::Approved => 'admin.approved_notice',
            RegistrationStatus::Rejected => 'admin.rejected_notice',
            default                      => null,
        };

        if ($key === null) {
            return false;
        }

        $locale = Lang::normalize((string) ($registration['user_locale'] ?? ''));
        $text = Lang::t($key, $locale, ['id' => (int) ($registration['id'] ?? 0)]);

        try {
            $this->app->api()->sendMessage($chatId, $text);

            return true;
        } catch (\Throwable $e) {
            $this->app->logger()->warning('panel.notify_failed', [
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Human readable direction labels of one application.
     *
     * @param array<string,mixed> $registration
     * @return string[]
     */
    private function directionLabels(array $registration): array
    {
        $keys = $registration['directions'] ?? [];

        if (!is_array($keys)) {
            return [];
        }

        $locale = $this->locale();
        $labels = [];

        foreach ($keys as $key) {
            if (!is_scalar($key)) {
                continue;
            }

            $labels[] = Catalog::directionLabel((string) $key, $locale, false);
        }

        $other = trim((string) ($registration['direction_other'] ?? ''));

        if ($other !== '') {
            $labels[] = $other;
        }

        return $labels;
    }

    /**
     * @param array<string,mixed> $registration
     */
    private function districtLabel(array $registration): string
    {
        $key = trim((string) ($registration['district'] ?? ''));

        return $key === '' ? '' : Catalog::districtLabel($key, $this->locale());
    }

    /**
     * The portfolio links of one application, http(s) only.
     *
     * @param array<string,mixed> $registration
     * @return string[]
     */
    private function portfolioLinks(array $registration): array
    {
        $links = $registration['portfolio_links'] ?? [];

        if (!is_array($links)) {
            return [];
        }

        $safe = [];

        foreach ($links as $link) {
            if (!is_scalar($link)) {
                continue;
            }

            $url = trim((string) $link);

            // Only absolute http(s) URLs become clickable anchors.
            if (preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $safe[] = $url;
            }
        }

        return $safe;
    }

    /**
     * The district catalogue as select options.
     *
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
     * The direction catalogue as select options.
     *
     * @return array<int,array{key:string,label:string}>
     */
    private function directionOptions(): array
    {
        $locale = $this->locale();
        $options = [];

        foreach (Catalog::directionKeys() as $key) {
            // Without the catalogue emoji: a <select> can hold text only, and
            // the panel does not print emoji (see admin/views/partials/icon.php).
            $options[] = [
                'key'   => $key,
                'label' => Catalog::directionLabel($key, $locale, false),
            ];
        }

        return $options;
    }

    /**
     * @return array<int,array{value:string,label:string,badge:string,icon:string}>
     */
    private function statusOptions(): array
    {
        $options = [];

        foreach (RegistrationStatus::cases() as $status) {
            $options[] = $this->statusOption($status);
        }

        return $options;
    }

    /**
     * @return array{value:string,label:string,badge:string,icon:string}
     */
    private function statusOption(RegistrationStatus $status): array
    {
        return [
            'value' => $status->value,
            'label' => Lang::t($status->labelKey(), $this->locale()),
            'badge' => $status->badge(),
            // The panel draws the status as an inline SVG (partials/icon.php);
            // the enum's emoji belongs to the Telegram messages, not here.
            'icon'  => match ($status) {
                RegistrationStatus::Pending  => 'clock',
                RegistrationStatus::Approved => 'check',
                RegistrationStatus::Rejected => 'x',
            },
        ];
    }

    /**
     * Audit target label of one application.
     */
    private function target(int $id): string
    {
        return 'registration:' . $id;
    }

    /* --------------------------------------------------------------------
     | Request plumbing
     */

    /**
     * Refuse a state-changing action that did not arrive as a POST.
     */
    private function requirePost(): void
    {
        if (Request::isPost()) {
            return;
        }

        $this->flash('error', $this->t('panel.flash_error'));

        Request::redirect(View::link($this->currentQuery(['id' => null])));
    }

    /**
     * The query string of the current screen, ready for View::link().
     *
     * @param array<string,mixed> $overrides values to replace (null removes a key)
     * @return array<string,mixed>
     */
    private function currentQuery(array $overrides = []): array
    {
        $query = ['p' => $this->view->currentPage()];

        // Only an id that is part of the URL belongs in the redirect target: an
        // id posted by a row action of the list must not follow it back.
        $rawId = Request::get('id');

        if (is_scalar($rawId) && preg_match('/^\d{1,18}$/', trim((string) $rawId)) === 1) {
            $query['id'] = (int) trim((string) $rawId);
        }

        foreach (self::QUERY_KEYS as $key) {
            $value = Request::get($key);

            if ($value === null || is_array($value) || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($query[$key]);

                continue;
            }

            $query[$key] = $value;
        }

        return $query;
    }

    /**
     * Redirect back to the screen the operator came from.
     */
    private function back(): void
    {
        Request::redirect(View::link($this->currentQuery()));
    }

    /**
     * Who is acting, for the audit trail and `registrations.reviewed_by`.
     */
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
