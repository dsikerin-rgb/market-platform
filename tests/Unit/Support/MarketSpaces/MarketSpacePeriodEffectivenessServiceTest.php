<?php

declare(strict_types=1);

namespace Tests\Unit\Support\MarketSpaces;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Support\MarketSpaces\MarketSpacePeriodEffectivenessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MarketSpacePeriodEffectivenessServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_counts_leased_accounting_area_by_period_without_contract_debt_coverage(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $ordinaryA = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A1',
            'area_sqm' => 100,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A2',
            'area_sqm' => 50,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'TECH',
            'area_sqm' => 25,
            'status' => 'maintenance',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G1',
            'area_sqm' => 40,
            'status' => 'vacant',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G1-1',
            'area_sqm' => 40,
            'status' => 'occupied',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $ordinaryA->id,
            'number' => 'A1-2026',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-01-31',
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $parent->id,
            'number' => 'G1-2026',
            'status' => 'active',
            'starts_at' => '2026-01-15',
            'ends_at' => '2026-02-15',
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $child->id,
            'number' => 'CHILD-IGNORED',
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'ends_at' => '2026-03-31',
            'is_active' => true,
        ]);

        if (Schema::hasTable('contract_debts')) {
            DB::table('contract_debts')->insert([
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => 'tenant-noise',
                'contract_external_id' => 'debt-noise',
                'period' => '2026-03',
                'accrued_amount' => 999999,
                'paid_amount' => 0,
                'debt_amount' => 999999,
                'calculated_at' => '2026-03-31 00:00:00',
                'hash' => hash('sha256', 'area-occupancy-contract-debt-noise-2026-03'),
                'source' => '1c',
            ]);
        }

        $series = app(MarketSpacePeriodEffectivenessService::class)
            ->areaOccupancyPercentSeries((int) $market->id, ['2026-01', '2026-02', '2026-03'], 'Europe/Moscow');

        self::assertSame(73.7, $series[0]);
        self::assertSame(21.1, $series[1]);
        self::assertNull($series[2]);
    }

    #[Test]
    public function it_uses_1c_debt_periods_as_historical_area_snapshots_by_unique_spaces(): void
    {
        if (! Schema::hasTable('contract_debts')) {
            self::markTestSkipped('contract_debts table is not available in this test database.');
        }

        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $spaceA = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A1',
            'area_sqm' => 100,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $spaceB = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B1',
            'area_sqm' => 50,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $spaceC = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'C1',
            'area_sqm' => 50,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'external_id' => 'contract-a',
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $spaceA->id,
            'number' => 'A1-2026',
            'status' => 'active',
            'starts_at' => '2026-06-01',
            'ends_at' => null,
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'external_id' => 'contract-b',
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $spaceB->id,
            'number' => 'B1-2026',
            'status' => 'active',
            'starts_at' => '2026-06-01',
            'ends_at' => null,
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'external_id' => 'contract-excluded',
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $spaceC->id,
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_EXCLUDED,
            'number' => 'C1-2026',
            'status' => 'active',
            'starts_at' => '2026-06-01',
            'ends_at' => null,
            'is_active' => true,
        ]);

        $this->insertDebtRow($market, $tenant, 'contract-a', '2026-03', '2026-03-30 00:00:00', 1000);
        $this->insertDebtRow($market, $tenant, 'contract-a', '2026-03', '2026-03-31 00:00:00', 2000);
        $this->insertDebtRow($market, $tenant, 'contract-b', '2026-03', '2026-03-31 00:00:00', 3000);
        $this->insertDebtRow($market, $tenant, 'contract-a', '2026-04', '2026-04-30 00:00:00', 4000);
        $this->insertDebtRow($market, $tenant, 'contract-excluded', '2026-04', '2026-04-30 00:00:00', 5000);
        $this->insertDebtRow($market, $tenant, 'contract-missing', '2026-05', '2026-05-31 00:00:00', 6000);

        $series = app(MarketSpacePeriodEffectivenessService::class)
            ->areaOccupancyPercentSeries((int) $market->id, ['2026-03', '2026-04', '2026-05', '2026-06'], 'Europe/Moscow');

        self::assertSame(75.0, $series[0]);
        self::assertSame(50.0, $series[1]);
        self::assertNull($series[2]);
        self::assertSame(75.0, $series[3]);
    }

    private function insertDebtRow(
        Market $market,
        Tenant $tenant,
        string $contractExternalId,
        string $period,
        string $calculatedAt,
        int $amount,
    ): void {
        DB::table('contract_debts')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_external_id' => 'tenant-' . $tenant->id,
            'contract_external_id' => $contractExternalId,
            'period' => $period,
            'accrued_amount' => $amount,
            'paid_amount' => 0,
            'debt_amount' => $amount,
            'calculated_at' => $calculatedAt,
            'hash' => hash('sha256', $contractExternalId . '|' . $period . '|' . $calculatedAt),
            'source' => '1c',
        ]);
    }
}
