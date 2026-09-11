<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Export\XlsxExporter;
use AiTalents\Lang;
use AiTalents\Registration\Catalog;

/**
 * Downloads the applications as an Excel workbook.
 *
 * The screen has no template of its own: `?p=export` (optionally carrying the
 * very same filters as the registrations list) answers with the .xlsx file
 * itself, which is what the "Export current filter" button on the list links to.
 *
 * Rows are streamed straight out of the repository by
 * {@see \AiTalents\Export\XlsxExporter}, so a 50k row export never builds a
 * PHP array of the whole table. An empty result set is not turned into an empty
 * workbook: the operator is sent back with a message instead.
 */
final class ExportController
{
    /** Query string keys shared with the registrations list. */
    private const QUERY_KEYS = ['q', 'status', 'district', 'direction', 'date_from', 'date_to'];

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
        // Clicking "Eksport" in the sidebar should not fire a download at an
        // operator who only wanted to look; the landing page shows what the
        // current filter selects and asks for a deliberate click. The button on
        // the registrations list links straight to ?a=download.
        if ($action === 'download') {
            $this->download();

            return;
        }

        $this->overview();
    }

    /**
     * The landing page: what the current filter selects, and a download button.
     */
    private function overview(): void
    {
        $filters = $this->filters();
        $total   = 0;

        try {
            $total = $this->app->registrations()->countAll($filters);
        } catch (\Throwable $e) {
            $this->app->logger()->error('export_count_failed', ['error' => $e->getMessage()]);
        }

        $query = $filters;
        $query['p'] = 'export';
        $query['a'] = 'download';

        $this->view->render('export', [
            'filters'     => $filters,
            'total'       => $total,
            'downloadUrl' => View::link($query),
        ]);
    }

    /**
     * Build and stream the workbook for the current filter set.
     */
    public function download(): void
    {
        $filters = $this->filters();
        $repository = $this->app->registrations();

        try {
            $total = $repository->countAll($filters);
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.export_count_failed', ['error' => $e->getMessage()]);

            $this->flash('error', $this->t('error.db'));
            $this->back($filters);

            return; // unreachable: back() always redirects
        }

        if ($total === 0) {
            $this->flash('warning', $this->t('panel.export_empty'));
            $this->back($filters);

            return; // unreachable: back() always redirects
        }

        $locale = $this->locale();
        $exporter = new XlsxExporter($repository);
        $filename = $exporter->filename($locale);

        $this->audit('export.xlsx', null, [
            'rows'    => $total,
            'filters' => $filters,
            'file'    => $filename,
        ]);

        try {
            // XlsxWriter::stream() unwinds the output buffers itself and sends
            // the Content-Type/Content-Disposition headers before the bytes.
            $exporter->stream($filters, $locale, $filename);
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.export_failed', ['error' => $e->getMessage()]);

            // Headers may already be out; a redirect is still the best effort.
            $this->flash('error', $this->t('panel.flash_error'));
            $this->back($filters);

            return; // unreachable: back() always redirects
        }

        exit;
    }

    /* --------------------------------------------------------------------
     | Input
     */

    /**
     * The whitelisted filter set — identical to the registrations list, so the
     * download always matches what the operator is looking at.
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

    /* --------------------------------------------------------------------
     | Plumbing
     */

    /**
     * Back to the registrations list, keeping the filters that were exported.
     *
     * @param array<string,string> $filters
     */
    private function back(array $filters): void
    {
        $query = ['p' => 'registrations'];

        foreach (self::QUERY_KEYS as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $query[$key] = $filters[$key];
            }
        }

        Request::redirect(View::link($query));
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
