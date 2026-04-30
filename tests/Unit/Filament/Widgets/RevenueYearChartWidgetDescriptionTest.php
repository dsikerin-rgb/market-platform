<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\RevenueYearChartWidget;
use App\Models\Market;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueYearChartWidgetDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    public function test_description_only_keeps_period_context(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 15, 12, 0, 0, 'UTC'));

        $market = Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Barnaul',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
        ]);

        Filament::setCurrentPanel(app(\Filament\Panel::class));
        auth()->login($user);

        $widget = new class extends RevenueYearChartWidget
        {
            protected function resolveLatestDebtMonth(int $marketId): ?string
            {
                return '2026-04';
            }
        };

        $description = $widget->getDescription();

        self::assertIsString($description);
        self::assertStringContainsString('Период графика: до 04.2026', $description);
        self::assertStringNotContainsString('Локация:', $description);
        self::assertStringNotContainsString('TZ:', $description);
        self::assertStringNotContainsString('Источник: 1С', $description);
    }
}
