<?php

declare(strict_types=1);

namespace AiTalents\Admin\Controller;

use AiTalents\Admin\Request;
use AiTalents\Admin\View;
use AiTalents\App;
use AiTalents\Enum\RegistrationStatus;
use AiTalents\Lang;

/**
 * The panel's landing page: headline numbers, charts and the newest applications.
 *
 * Every figure comes from {@see \AiTalents\Service\StatsService}; the charts
 * themselves are drawn as inline SVG inside the template, so this controller
 * only has to hand over clean, already aggregated series.
 *
 * A brand new installation has no tables yet, so all reads are wrapped: the
 * dashboard shows zeros and a warning instead of a 500 page.
 */
final class DashboardController
{
    /** How many days the trend chart covers. */
    private const TREND_DAYS = 14;

    /** How many applications the "latest" table shows. */
    private const LATEST_LIMIT = 10;

    /** Longest quick-search term accepted from the search box. */
    private const SEARCH_MAX = 120;

    public function __construct(private App $app, private View $view)
    {
    }

    /**
     * Entry point called by admin/index.php.
     */
    public function handle(string $action): void
    {
        // The quick-search box lives on the dashboard but belongs to the
        // registrations screen: forward it instead of duplicating the list.
        if ($action === 'search') {
            $query = mb_substr(Request::str('q'), 0, self::SEARCH_MAX, 'UTF-8');

            Request::redirect(View::link($query === ''
                ? ['p' => 'registrations']
                : ['p' => 'registrations', 'q' => $query]));
        }

        $this->index();
    }

    /**
     * Render the dashboard.
     */
    public function index(): void
    {
        $failed = false;

        try {
            $stats = $this->app->stats();

            $overview   = $stats->overview();
            $daily      = $stats->daily(self::TREND_DAYS);
            $hourly     = $stats->hourly();
            $byStatus   = $stats->byStatus();
            $byDirection = $stats->byDirection();
            $byDistrict = $stats->byDistrict();
            $topDay     = $stats->topDay();
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.dashboard_stats_failed', ['error' => $e->getMessage()]);

            $failed = true;
            $overview = $this->emptyOverview();
            $daily = [];
            $hourly = [];
            $byStatus = [];
            $byDirection = [];
            $byDistrict = [];
            $topDay = null;
        }

        try {
            $latest = $this->app->registrations()->latest(self::LATEST_LIMIT);
        } catch (\Throwable $e) {
            $this->app->logger()->error('panel.dashboard_latest_failed', ['error' => $e->getMessage()]);

            $failed = true;
            $latest = [];
        }

        if ($failed) {
            $this->flash('error', $this->t('error.db'));
        }

        $this->view->render('dashboard', [
            'title'       => $this->t('panel.dashboard_title'),
            'subtitle'    => $this->t('panel.dashboard_subtitle'),
            'overview'    => $overview,
            'daily'       => $daily,
            'hourly'      => $hourly,
            'byStatus'    => $byStatus,
            'byDirection' => $byDirection,
            'byDistrict'  => $byDistrict,
            'topDay'      => $topDay,
            'latest'      => $latest,
            'trendDays'   => self::TREND_DAYS,
            'statuses'    => $this->statuses(),
            'locale'      => $this->locale(),
            'q'           => '',
        ]);
    }

    /* --------------------------------------------------------------------
     | Internals
     */

    /**
     * The status catalogue used by the badges of the "latest" table.
     *
     * @return array<string,array{value:string,label:string,badge:string,icon:string}>
     */
    private function statuses(): array
    {
        $locale = $this->locale();
        $statuses = [];

        foreach (RegistrationStatus::cases() as $status) {
            $statuses[$status->value] = [
                'value' => $status->value,
                'label' => Lang::t($status->labelKey(), $locale),
                'badge' => $status->badge(),
                // An inline SVG name (partials/icon.php), not the enum's emoji:
                // emoji are for the Telegram messages, never for the panel.
                'icon'  => match ($status) {
                    RegistrationStatus::Pending  => 'clock',
                    RegistrationStatus::Approved => 'check',
                    RegistrationStatus::Rejected => 'x',
                },
            ];
        }

        return $statuses;
    }

    /**
     * The shape StatsService::overview() returns, with every counter at zero.
     *
     * @return array<string,int|float>
     */
    private function emptyOverview(): array
    {
        return [
            'users'         => 0,
            'registrations' => 0,
            'today'         => 0,
            'yesterday'     => 0,
            'week'          => 0,
            'month'         => 0,
            'pending'       => 0,
            'approved'      => 0,
            'rejected'      => 0,
            'blocked'       => 0,
            'conversion'    => 0.0,
        ];
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
