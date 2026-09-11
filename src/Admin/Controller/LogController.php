<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Lang;
use AiTalents\Logger;

/**
 * The two diagnostic screens of the panel.
 *
 * `?p=logs` tails one day of the bot's rotating log file (with a level filter
 * and a raw download), `?p=audit` paginates the audit trail written by every
 * administrative action.
 *
 * Log lines look like `[2026-09-10 14:03:11] INFO: message {"context":1}`; they
 * are parsed into `time` / `level` / `message` so the template can colour the
 * level badge without doing string surgery of its own.
 */
final class LogController
{
    /** Line counts offered by the selector. */
    private const LINE_OPTIONS = [100, 200, 500, 1000, 2000];

    /** Severity order, mirroring Logger::LEVELS. */
    private const LEVELS = ['debug', 'info', 'warning', 'error'];

    /** Page sizes offered by the audit screen. */
    private const PER_PAGE = [25, 50, 100];

    /** Audit query string keys carried across a redirect. */
    private const AUDIT_KEYS = ['q', 'actor', 'action', 'target', 'ip', 'date_from', 'date_to', 'page', 'per_page'];

    /** Bounds for the "purge older than" maintenance action, in days. */
    private const PURGE_MIN = 1;
    private const PURGE_MAX = 3650;
    private const PURGE_DEFAULT = 90;

    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        $page = $this->view->currentPage();

        if ($page === 'audit') {
            if ($action === 'purge') {
                $this->purge();

                return;
            }

            $this->audit();

            return;
        }

        if ($action === 'download') {
            $this->download();

            return;
        }

        $this->logs();
    }

    /* --------------------------------------------------------------------
     | Logs
     */

    /**
     * Tail one day of the log file.
     */
    public function logs(): void
    {
        $logger = $this->app->logger();

        $files = $logger->files();
        $date  = $this->date($files);
        $level = $this->level();
        $limit = $this->limit();

        $lines = $this->parse($logger->tail($limit, $date), $level);

        $this->view->render('logs', [
            'title'        => $this->t('panel.logs_title'),
            'subtitle'     => $this->t('panel.logs_subtitle'),
            'lines'        => $lines,
            'files'        => $files,
            'date'         => $date,
            'level'        => $level,
            'levels'       => self::LEVELS,
            'limit'        => $limit,
            'limitOptions' => self::LINE_OPTIONS,
            'logLevel'     => $logger->level(),
            'enabled'      => $logger->isEnabled(),
            'locale'       => $this->locale(),
        ]);
    }

    /**
     * Send the raw log file of one day as a download.
     */
    public function download(): void
    {
        $logger = $this->app->logger();

        $files = $logger->files();
        $date = $this->date($files);
        $file = $logger->path($date);

        if ($date === '' || !is_file($file) || !is_readable($file)) {
            $this->flash('error', $this->t('panel.logs_empty'));

            Request::redirect(View::link(['p' => 'logs']));
        }

        $this->auditLog('logs.download', 'log:' . $date);

        // Binary-safe delivery: no buffered byte may precede the file.
        while (ob_get_level() > 0 && ob_end_clean()) {
            // keep unwinding
        }

        if (!headers_sent()) {
            clearstatcache(true, $file);
            $size = filesize($file);

            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Disposition: attachment; filename="bot-' . $date . '.log"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, no-cache, must-revalidate');

            if ($size !== false) {
                header('Content-Length: ' . $size);
            }
        }

        readfile($file);

        exit;
    }

    /* --------------------------------------------------------------------
     | Audit trail
     */

    /**
     * Paginate the audit_log table.
     */
    public function audit(): void
    {
        $repository = $this->app->audit();

        $filters = $this->auditFilters();
        $perPage = $this->perPage();

        $total = $repository->countAll($filters);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min(max(1, Request::int('page', 1)), $pages);

        $this->view->render('audit', [
            'title'          => $this->t('panel.audit_title'),
            'subtitle'       => $this->t('panel.audit_subtitle'),
            'rows'           => $repository->paginate($filters, $perPage, ($page - 1) * $perPage),
            'total'          => $total,
            'page'           => $page,
            'pages'          => $pages,
            'perPage'        => $perPage,
            'perPageOptions' => self::PER_PAGE,
            'filters'        => $filters,
            'actions'        => $repository->actions(),
            'purgeDays'      => self::PURGE_DEFAULT,
            'query'          => $this->auditQuery(),
            'locale'         => $this->locale(),
        ]);
    }

    /**
     * Delete audit entries older than the requested number of days.
     */
    public function purge(): void
    {
        if (!Request::isPost()) {
            $this->flash('error', $this->t('panel.flash_error'));

            Request::redirect(View::link(['p' => 'audit']));
        }

        $days = max(self::PURGE_MIN, min(self::PURGE_MAX, Request::int('days', self::PURGE_DEFAULT)));
        $removed = $this->app->audit()->purgeOlderThan($days);

        $this->auditLog('audit.purge', null, ['days' => $days, 'removed' => $removed]);
        $this->flash('success', $this->t('panel.audit_purged', ['count' => $removed]));

        Request::redirect(View::link($this->auditQuery()));
    }

    /* --------------------------------------------------------------------
     | Log parsing
     */

    /**
     * Turn raw log lines into rows the template can render.
     *
     * A line that does not match the logger's own format keeps its text and is
     * reported with an empty level, so hand-appended content stays visible.
     *
     * @param string[] $lines
     * @return array<int,array{time:string,level:string,message:string,raw:string}>
     */
    private function parse(array $lines, string $level): array
    {
        $minimum = $level === '' ? -1 : (Logger::LEVELS[$level] ?? -1);
        $parsed = [];

        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $entry = ['time' => '', 'level' => '', 'message' => $line, 'raw' => $line];

            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] ([A-Z]+): ?(.*)$/s', $line, $m) === 1) {
                $entry = [
                    'time'    => $m[1],
                    'level'   => strtolower($m[2]),
                    'message' => $m[3],
                    'raw'     => $line,
                ];
            }

            // The filter keeps the chosen level and everything more severe.
            if ($minimum >= 0) {
                $severity = Logger::LEVELS[$entry['level']] ?? null;

                if ($severity === null || $severity < $minimum) {
                    continue;
                }
            }

            $parsed[] = $entry;
        }

        return $parsed;
    }

    /* --------------------------------------------------------------------
     | Input
     */

    /**
     * The log date to display: the requested one when a file exists for it,
     * the newest available one otherwise.
     *
     * @param string[] $files
     */
    private function date(array $files): string
    {
        $date = Request::str('date');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && in_array($date, $files, true)) {
            return $date;
        }

        return $files === [] ? '' : (string) $files[0];
    }

    /**
     * The minimum severity to display ('' = everything).
     */
    private function level(): string
    {
        $level = strtolower(Request::str('level'));

        return in_array($level, self::LEVELS, true) ? $level : '';
    }

    private function limit(): int
    {
        $limit = Request::int('lines', self::LINE_OPTIONS[1]);

        return in_array($limit, self::LINE_OPTIONS, true) ? $limit : self::LINE_OPTIONS[1];
    }

    private function perPage(): int
    {
        $perPage = Request::int('per_page', self::PER_PAGE[1]);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : self::PER_PAGE[1];
    }

    /**
     * The whitelisted audit filters of the current request.
     *
     * @return array<string,string>
     */
    private function auditFilters(): array
    {
        $filters = [];

        foreach (['q', 'actor', 'action', 'target', 'ip'] as $key) {
            $value = mb_substr(Request::str($key), 0, 120, 'UTF-8');

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        foreach (['date_from', 'date_to'] as $key) {
            $value = Request::str($key);

            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1])
            ) {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }

    /**
     * The query string of the audit screen, ready for View::link().
     *
     * @return array<string,mixed>
     */
    private function auditQuery(): array
    {
        $query = ['p' => 'audit'];

        foreach (self::AUDIT_KEYS as $key) {
            $value = Request::get($key);

            if ($value === null || is_array($value) || !is_scalar($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value !== '') {
                $query[$key] = $value;
            }
        }

        return $query;
    }

    /* --------------------------------------------------------------------
     | Plumbing
     */

    private function actor(): string
    {
        $user = function_exists('panel_auth') ? panel_auth()->user() : null;

        return 'panel:' . ($user !== null && $user !== '' ? $user : 'unknown');
    }

    /**
     * @param array<string,mixed> $meta
     */
    private function auditLog(string $action, ?string $target = null, array $meta = []): void
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
