<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets\Concerns;

use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use Tests\TestCase;

class ResolvesDashboardFilterMonthTest extends TestCase
{
    public function test_prefers_normalized_dashboard_month_from_page_filters(): void
    {
        session([
            'dashboard_month' => '2026-04',
            'dashboard_period' => '2026-04-01',
        ]);

        $widget = new class
        {
            use ResolvesDashboardFilterMonth;

            public ?array $pageFilters = [
                'month' => '2026-03',
                'dashboard_month' => '2026-04',
            ];

            public ?array $filters = null;

            public function resolve(): mixed
            {
                return $this->resolveDashboardFilterMonthRaw();
            }
        };

        self::assertSame('2026-04', $widget->resolve());
    }

    public function test_falls_back_to_normalized_dashboard_period_from_filters(): void
    {
        session([
            'dashboard_month' => '2026-04',
            'dashboard_period' => '2026-04-01',
        ]);

        $widget = new class
        {
            use ResolvesDashboardFilterMonth;

            public ?array $pageFilters = null;

            public ?array $filters = [
                'month' => '2026-03',
                'dashboard_period' => '2026-04-01',
            ];

            public function resolve(): mixed
            {
                return $this->resolveDashboardFilterMonthRaw();
            }
        };

        self::assertSame('2026-04-01', $widget->resolve());
    }

    public function test_prefers_session_month_over_stale_raw_page_filter_month(): void
    {
        session([
            'dashboard_month' => '2026-04',
            'dashboard_period' => '2026-04-01',
        ]);

        $widget = new class
        {
            use ResolvesDashboardFilterMonth;

            public ?array $pageFilters = [
                'month' => '2026-03',
            ];

            public ?array $filters = null;

            public function resolve(): mixed
            {
                return $this->resolveDashboardFilterMonthRaw();
            }
        };

        self::assertSame('2026-04', $widget->resolve());
    }

    public function test_prefers_session_month_over_stale_raw_filters_month(): void
    {
        session([
            'dashboard_month' => '2026-04',
            'dashboard_period' => '2026-04-01',
        ]);

        $widget = new class
        {
            use ResolvesDashboardFilterMonth;

            public ?array $pageFilters = null;

            public ?array $filters = [
                'month' => '2026-03',
            ];

            public function resolve(): mixed
            {
                return $this->resolveDashboardFilterMonthRaw();
            }
        };

        self::assertSame('2026-04', $widget->resolve());
    }
}
