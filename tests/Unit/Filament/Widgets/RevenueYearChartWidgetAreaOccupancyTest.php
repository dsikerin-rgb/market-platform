<?php
# tests/Unit/Filament/Widgets/RevenueYearChartWidgetAreaOccupancyTest.php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\RevenueYearChartWidget;
use App\Models\Market;
use App\Models\MarketSpaceType;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    #[Test]
    public function build_payable_series_ignores_non_accounting_space_rows_and_keeps_unmapped_rows(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 1, 15, 12, 0, 0, 'UTC'));

        $market = Market::query()->create([
            'name' => 'Payable Test Market',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        MarketSpaceType::query()->create([
            'market_id' => (int) $market->id,
            'name_ru' => 'Торговое',
            'code' => 'commercial-space',
            'unit' => 'sqm',
            'price' => 0,
            'currency' => 'RUB',
            'category' => MarketSpaceType::CATEGORY_COMMERCIAL,
            'is_active' => true,
        ]);

        MarketSpaceType::query()->create([
            'market_id' => (int) $market->id,
            'name_ru' => 'Санузел',
            'code' => 'sanuzel',
            'unit' => 'sqm',
            'price' => 0,
            'currency' => 'RUB',
            'category' => MarketSpaceType::CATEGORY_COMMON_AREA,
            'is_active' => true,
        ]);

        $commercialSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'COM-1',
            'type' => 'commercial-space',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $commonSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'SANUZEL-1',
            'type' => 'sanuzel',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $commercialSpace->id,
            'number' => 'COM-CONTRACT',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'is_active' => true,
            'external_id' => 'COM-EXT',
        ]);

        TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $commonSpace->id,
            'number' => 'SANUZEL-CONTRACT',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-12-31',
            'is_active' => true,
            'external_id' => 'SANUZEL-EXT',
        ]);

        DB::table('contract_debts')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => 'TEN-1',
                'contract_external_id' => 'COM-EXT',
                'period' => '2026-01',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => CarbonImmutable::create(2026, 1, 1, 12, 0, 0, 'UTC')->toDateTimeString(),
                'hash' => md5('COM-EXT-2026-01'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => 'TEN-1',
                'contract_external_id' => 'SANUZEL-EXT',
                'period' => '2026-01',
                'accrued_amount' => 500,
                'paid_amount' => 0,
                'debt_amount' => 500,
                'calculated_at' => CarbonImmutable::create(2026, 1, 1, 12, 5, 0, 'UTC')->toDateTimeString(),
                'hash' => md5('SANUZEL-EXT-2026-01'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => 'TEN-1',
                'contract_external_id' => 'UNMAPPED-1',
                'period' => '2026-01',
                'accrued_amount' => 70,
                'paid_amount' => 0,
                'debt_amount' => 70,
                'calculated_at' => CarbonImmutable::create(2026, 1, 1, 12, 10, 0, 'UTC')->toDateTimeString(),
                'hash' => md5('UNMAPPED-1-2026-01'),
            ],
        ]);

        $widget = new RevenueYearChartWidget();
        $method = new \ReflectionMethod(RevenueYearChartWidget::class, 'buildPayableSeries');
        $method->setAccessible(true);

        $series = $method->invoke($widget, (int) $market->id, ['2026-01', '2026-02']);

        self::assertSame([1070, null], $series);
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
