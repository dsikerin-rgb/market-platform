<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\MarketOverviewStatsWidget;
use Carbon\CarbonImmutable;
use ReflectionMethod;
use Tests\TestCase;

class MarketOverviewStatsWidgetMonthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
    }

    public function test_auto_mode_advances_financial_month_to_latest_debt_month(): void
    {
        session()->put([
            'dashboard_month' => '2026-03',
            'dashboard_period' => '2026-03-01',
            'dashboard_month_explicit' => false,
        ]);

        $widget = new class extends MarketOverviewStatsWidget
        {
            protected function resolveLatestDebtMonth(int $marketId): ?string
            {
                return '2026-04';
            }
        };

        [$monthYm, $start, $end] = $this->invokeResolveFinancialMonthRange($widget, 123, 'UTC');

        $this->assertSame('2026-04', $monthYm);
        $this->assertSame('2026-04-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-01 00:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function test_manual_mode_keeps_selected_financial_month(): void
    {
        session()->put([
            'dashboard_month' => '2026-03',
            'dashboard_period' => '2026-03-01',
            'dashboard_month_explicit' => true,
        ]);

        $widget = new class extends MarketOverviewStatsWidget
        {
            protected function resolveLatestDebtMonth(int $marketId): ?string
            {
                return '2026-04';
            }
        };

        [$monthYm, $start, $end] = $this->invokeResolveFinancialMonthRange($widget, 123, 'UTC');

        $this->assertSame('2026-03', $monthYm);
        $this->assertSame('2026-03-01 00:00:00', $start->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-01 00:00:00', $end->format('Y-m-d H:i:s'));
    }

    /**
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable}
     */
    private function invokeResolveFinancialMonthRange(
        MarketOverviewStatsWidget $widget,
        int $marketId,
        string $tz
    ): array {
        $method = new ReflectionMethod($widget, 'resolveFinancialMonthRange');
        $method->setAccessible(true);

        /** @var array{0:string,1:CarbonImmutable,2:CarbonImmutable} $result */
        $result = $method->invoke($widget, $marketId, $tz);

        return $result;
    }
}
