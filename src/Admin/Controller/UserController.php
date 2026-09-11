<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Lang;

/**
 * Bot users: who talked to the bot, who is blocked and who may use the
 * in-Telegram admin menu.
 *
 * Blocking here is the same flag the broadcast sender sets when Telegram
 * answers "bot was blocked by the user": a blocked row is skipped by every
 * campaign, and the bot router refuses to serve it.
 *
 * The admin flag only writes `users.is_admin`; administrators listed in
 * config.php (`telegram.admin_ids`) are compiled into the deployment and cannot
 * be revoked from the panel — the operator is told so instead of being lied to.
 */
final class UserController
{
    /** Columns the list may be ordered by — mirrors UserRepository. */
    private const SORTABLE = ['id', 'created_at', 'last_seen_at', 'telegram_id'];

    /** Page sizes offered by the selector. */
    private const PER_PAGE = [25, 50, 100];

    /** Query string keys carried across a redirect. */
    private const QUERY_KEYS = ['q', 'blocked', 'registered', 'locale', 'admin', 'sort', 'dir', 'page', 'per_page'];

    /** Longest search term accepted from the search box. */
    private const SEARCH_MAX = 120;

    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        switch ($action) {
            case 'block':
                $this->setBlocked(true);
                break;

            case 'unblock':
                $this->setBlocked(false);
                break;

            case 'admin':
            case 'make_admin':
                $this->setAdmin(true);
                break;

            case 'revoke':
            case 'revoke_admin':
                $this->setAdmin(false);
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
     * The paginated, filterable list of bot users.
     */
    public function list(): void
    {
        $repository = $this->app->users();

        $filters = $this->filters();
        $sort    = $this->sort();
        $dir     = $this->direction();
        $perPage = $this->perPage();

        $total = $repository->countAll($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, Request::int('page', 1)), $pages);

        $rows = $repository->paginate($filters, $perPage, ($page - 1) * $perPage, $sort, $dir);

        $this->view->render('users', [
            'title'          => $this->t('panel.users_title'),
            'subtitle'       => $this->t('panel.users_subtitle'),
            'rows'           => $rows,
            'total'          => $total,
            'page'           => $page,
            'pages'          => $pages,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'filters'        => $filters,
            'sort'           => $sort,
            'dir'            => $dir,
            'sortable'       => self::SORTABLE,
            'locales'        => $this->localeOptions(),
            'configAdminIds' => $this->configAdminIds(),
            'blockedCount'   => $repository->blockedCount(),
            'query'          => $this->currentQuery(),
            'locale'         => $this->locale(),
        ]);
    }

    /* --------------------------------------------------------------------
     | Actions
     */

    /**
     * Block or unblock one user.
     */
    public function setBlocked(bool $blocked): void
    {
        $this->requirePost();

        $user = $this->resolveUser();

        if ($user === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));
            $this->back();
        }

        $telegramId = (int) $user['telegram_id'];

        $this->app->users()->setBlocked($telegramId, $blocked);

        $this->audit($blocked ? 'user.block' : 'user.unblock', 'user:' . $telegramId, [
            'telegram_id' => $telegramId,
            'username'    => (string) ($user['username'] ?? ''),
        ]);

        $this->flash(
            'success',
            $blocked ? $this->t('panel.user_blocked_flash') : $this->t('panel.user_unblocked_flash')
        );

        $this->back();
    }

    /**
     * Grant or revoke the in-Telegram administrator flag.
     */
    public function setAdmin(bool $admin): void
    {
        $this->requirePost();

        $user = $this->resolveUser();

        if ($user === null) {
            $this->flash('error', $this->t('panel.flash_not_found'));
            $this->back();
        }

        $telegramId = (int) $user['telegram_id'];

        $this->app->users()->setAdmin($telegramId, $admin);

        $this->audit($admin ? 'user.grant_admin' : 'user.revoke_admin', 'user:' . $telegramId, [
            'telegram_id' => $telegramId,
            'username'    => (string) ($user['username'] ?? ''),
        ]);

        $this->flash(
            'success',
            $admin ? $this->t('panel.user_admin_flash') : $this->t('panel.user_revoked_flash')
        );

        // Revoking the flag of a config administrator changes nothing at all.
        if (!$admin && in_array($telegramId, $this->configAdminIds(), true)) {
            $this->flash('warning', $this->t('panel.set_admin_ids_hint'));
        }

        $this->back();
    }

    /* --------------------------------------------------------------------
     | Input
     */

    /**
     * The whitelisted filter set of the current request.
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

        foreach (['blocked', 'registered', 'admin'] as $flag) {
            $value = $this->flag(Request::str($flag));

            if ($value !== null) {
                $filters[$flag] = $value;
            }
        }

        $locale = strtolower(Request::str('locale'));

        if ($locale !== '' && in_array($locale, $this->app->locales(), true)) {
            $filters['locale'] = $locale;
        }

        return $filters;
    }

    /**
     * A tri-state filter: '1', '0' or null when the selector says "all".
     */
    private function flag(string $value): ?string
    {
        return match ($value) {
            '1'     => '1',
            '0'     => '0',
            default => null,
        };
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
     * The user a POST action refers to.
     *
     * `tid` is the Telegram id (what the repository works with); `id` is the
     * primary key of the users table and is accepted as a fallback so a link
     * built from either column keeps working.
     *
     * @return ?array<string,mixed>
     */
    private function resolveUser(): ?array
    {
        $repository = $this->app->users();

        $telegramId = Request::int('tid');

        if ($telegramId !== 0) {
            return $repository->findByTelegramId($telegramId);
        }

        $id = Request::int('id');

        return $id > 0 ? $repository->findById($id) : null;
    }

    /* --------------------------------------------------------------------
     | Helpers
     */

    /**
     * The interface languages offered by the locale filter.
     *
     * @return array<int,array{value:string,label:string}>
     */
    private function localeOptions(): array
    {
        $available = Lang::available();
        $options = [];

        foreach ($this->app->locales() as $locale) {
            $options[] = [
                'value' => $locale,
                'label' => (string) ($available[$locale] ?? strtoupper($locale)),
            ];
        }

        return $options;
    }

    /**
     * Administrators compiled into config.php — the panel cannot revoke them.
     *
     * @return int[]
     */
    private function configAdminIds(): array
    {
        $raw = $this->app->config('telegram.admin_ids', []);
        $ids = [];

        if (is_array($raw)) {
            foreach ($raw as $id) {
                if (is_int($id) || (is_string($id) && preg_match('/^-?\d+$/', trim($id)) === 1)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /* --------------------------------------------------------------------
     | Request plumbing
     */

    private function requirePost(): void
    {
        if (Request::isPost()) {
            return;
        }

        $this->flash('error', $this->t('panel.flash_error'));

        Request::redirect(View::link($this->currentQuery()));
    }

    /**
     * The query string of the current screen, ready for View::link().
     *
     * @param array<string,mixed> $overrides values to replace (null removes a key)
     * @return array<string,mixed>
     */
    private function currentQuery(array $overrides = []): array
    {
        $query = ['p' => 'users'];

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

    private function back(): void
    {
        Request::redirect(View::link($this->currentQuery()));
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
