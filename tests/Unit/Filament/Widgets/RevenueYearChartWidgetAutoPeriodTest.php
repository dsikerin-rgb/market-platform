<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\RevenueYearChartWidget;
use Tests\TestCase;

class RevenueYearChartWidgetAutoPeriodTest extends TestCase
{
    public function test_auto_mode_prefers_latest_debt_month_over_older_dashboard_month(): void
    {
        session([
            'dashboard_month' => '2026-03',
            'dashboard_period' => '2026-03-01',
            'dashboard_month_explicit' => false,
        ]);

        $widget = new class extends RevenueYearChartWidget
        {
            public ?array $pageFilters = null;
            public ?array $filters = null;

            public function exposedResolveEndMonth(string $tz, ?int $marketId = null): array
            {
                $method = new \ReflectionMethod(RevenueYearChartWidget::class, 'resolveEndMonth');
                $method->setAccessible(true);

                return $method->invoke($this, $tz, $marketId);
            }

            protected function resolveLatestDebtMonth(int $marketId): ?string
            {
                return '2026-04';
            }
        };

        [$month] = $widget->exposedResolveEndMonth('Asia/Barnaul', 1);

        self::assertSame('2026-04', $month);
    }

    public function test_manual_mode_keeps_selected_dashboard_month(): void
    {
        session([
            'dashboard_month' => '2026-03',
            'dashboard_period' => '2026-03-01',
            'dashboard_month_explicit' => true,
        ]);

        $widget = new class extends RevenueYearChartWidget
        {
            public ?array $pageFilters = null;
            public ?array $filters = null;

            public function exposedResolveEndMonth(string $tz, ?int $marketId = null): array
            {
                $method = new \ReflectionMethod(RevenueYearChartWidget::class, 'resolveEndMonth');
                $method->setAccessible(true);

                return $method->invoke($this, $tz, $marketId);
            }

            protected function resolveLatestDebtMonth(int $marketId): ?string
            {
                return '2026-04';
            }
        };

        [$month] = $widget->exposedResolveEndMonth('Asia/Barnaul', 1);

        self::assertSame('2026-03', $month);
    }
}
