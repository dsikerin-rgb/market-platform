<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\RevenueYearChartWidget;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RevenueYearChartWidgetAreaOccupancyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    #[Test]
    public function non_finance_user_sees_area_occupancy_even_without_1c_debt_data(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 1, 15, 12, 0, 0, 'UTC'));

        $market = $this->createMarketWithSingleLeasedSpace();

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'non-finance-area-widget@example.test',
        ]);

        Filament::setCurrentPanel(app(\Filament\Panel::class));
        auth()->login($user);

        $data = $this->makeWidget()->exposedGetData();

        self::assertCount(1, $data['datasets']);
        self::assertSame('Заполняемость площади', $data['datasets'][0]['label']);
        self::assertNotContains('Охват ' . 'мест', array_column($data['datasets'], 'label'));

        $areaData = $data['datasets'][0]['data'];
        self::assertSame(100.0, $areaData[count($areaData) - 1]);
    }

    #[Test]
    public function finance_user_sees_payable_and_area_occupancy_series(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 1, 15, 12, 0, 0, 'UTC'));

        $market = $this->createMarketWithSingleLeasedSpace();

        Role::findOrCreate('market-finance', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'finance-area-widget@example.test',
        ]);
        $user->assignRole('market-finance');

        Filament::setCurrentPanel(app(\Filament\Panel::class));
        auth()->login($user);

        $data = $this->makeWidget()->exposedGetData();

        self::assertSame(
            ['К оплате', 'Заполняемость площади'],
            array_column($data['datasets'], 'label'),
        );
    }

    private function createMarketWithSingleLeasedSpace(): Market
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A1',
            'area_sqm' => 100,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'number' => 'A1-2026',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => null,
            'is_active' => true,
        ]);

        return $market;
    }

    private function makeWidget(): object
    {
        return new class extends RevenueYearChartWidget
        {
            public ?array $pageFilters = null;

            public ?array $filters = null;

            public function exposedGetData(): array
            {
                return $this->getData();
            }

            protected function resolveLatestDebtMonth(int $marketId): ?string
            {
                return '2026-01';
            }
        };
    }
}
