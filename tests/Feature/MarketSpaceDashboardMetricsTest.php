<?php
# tests/Feature/MarketSpaceDashboardMetricsTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpaceType;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MarketSpaceDashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_excludes_inactive_market_spaces(): void
    {
        $market = Market::query()->create([
            'name' => 'Metrics Market',
            'is_active' => true,
        ]);

        $activeTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Active Tenant',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A-1',
            'status' => 'occupied',
            'tenant_id' => (int) $activeTenant->id,
            'area_sqm' => 10,
            'is_active' => true,
        ]);

        $inactiveSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A-2',
            'status' => 'vacant',
            'area_sqm' => 20,
            'is_active' => false,
        ]);

        $activeSpace = MarketSpace::query()->where('number', 'A-1')->firstOrFail();

        $this->createShape($market, $activeSpace);
        $this->createShape($market, $inactiveSpace);

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        $this->assertSame(1, $summary['total_spaces']);
        $this->assertSame(10.0, $summary['total_area_sqm']);
        $this->assertSame(1, $summary['occupied_spaces']);
        $this->assertSame(10.0, $summary['occupied_area_sqm']);
        $this->assertSame(0, $summary['vacant_spaces']);
        $this->assertSame(0.0, $summary['vacant_area_sqm']);
    }

    public function test_current_tenants_count_excludes_historical_and_inactive_tenants(): void
    {
        $market = Market::query()->create([
            'name' => 'Tenant Count Market',
            'is_active' => true,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $historicalTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Historical Tenant',
            'is_active' => true,
        ]);

        $inactiveTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Inactive Tenant',
            'is_active' => false,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B-1',
            'status' => 'occupied',
            'tenant_id' => (int) $currentTenant->id,
            'area_sqm' => 10,
            'is_active' => true,
        ]);

        $historicalSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B-2',
            'status' => 'vacant',
            'tenant_id' => null,
            'area_sqm' => 10,
            'is_active' => true,
        ]);

        $inactiveTenantSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B-3',
            'status' => 'occupied',
            'tenant_id' => (int) $inactiveTenant->id,
            'area_sqm' => 10,
            'is_active' => true,
        ]);

        DB::table('market_space_tenant_histories')->insert([
            'market_space_id' => (int) $historicalSpace->id,
            'old_tenant_id' => (int) $historicalTenant->id,
            'new_tenant_id' => null,
            'changed_at' => now()->subDay(),
            'changed_by_user_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sharedSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B-4',
            'status' => 'vacant',
            'area_sqm' => 12,
            'is_active' => true,
        ]);

        $currentSpace = MarketSpace::query()->where('number', 'B-1')->firstOrFail();

        $this->createShape($market, $currentSpace);
        $this->createShape($market, $historicalSpace);
        $this->createShape($market, $inactiveTenantSpace);
        $this->createShape($market, $sharedSpace);

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sharedSpace->id,
            'tenant_id' => (int) $currentTenant->id,
            'binding_type' => 'shared_use',
            'source' => 'test',
            'area_sqm' => 12,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $this->assertSame(1, MarketSpaceDashboardMetrics::countCurrentTenants((int) $market->id));
    }

    public function test_summary_excludes_active_spaces_without_shapes(): void
    {
        $market = Market::query()->create([
            'name' => 'Shape Filter Market',
            'is_active' => true,
        ]);

        $shapedSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'C-1',
            'status' => 'vacant',
            'area_sqm' => 11,
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'C-2',
            'status' => 'vacant',
            'area_sqm' => 99,
            'is_active' => true,
        ]);

        $this->createShape($market, $shapedSpace);

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        $this->assertSame(1, $summary['total_spaces']);
        $this->assertSame(11.0, $summary['total_area_sqm']);
        $this->assertSame(1, $summary['vacant_spaces']);
        $this->assertSame(11.0, $summary['vacant_area_sqm']);
    }

    public function test_summary_excludes_common_area_and_infrastructure_from_commercial_metrics(): void
    {
        $market = Market::query()->create([
            'name' => 'Category Filter Market',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $this->createMarketSpaceType($market, [
            'name_ru' => 'Торговое',
            'code' => 'commercial',
            'category' => MarketSpaceType::CATEGORY_COMMERCIAL,
        ]);

        $this->createMarketSpaceType($market, [
            'name_ru' => 'Санузел',
            'code' => 'wc',
            'category' => MarketSpaceType::CATEGORY_COMMON_AREA,
        ]);

        $this->createMarketSpaceType($market, [
            'name_ru' => 'Инфраструктура',
            'code' => 'infra',
            'category' => MarketSpaceType::CATEGORY_INFRASTRUCTURE,
        ]);

        $commercialSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'COM-1',
            'tenant_id' => (int) $tenant->id,
            'type' => 'commercial',
            'status' => 'occupied',
            'area_sqm' => 10.0,
            'is_active' => true,
        ]);

        $commonSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'WC-1',
            'type' => 'wc',
            'status' => 'vacant',
            'area_sqm' => 20.0,
            'is_active' => true,
        ]);

        $infraSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'INF-1',
            'type' => 'infra',
            'status' => 'maintenance',
            'area_sqm' => 25.0,
            'is_active' => true,
        ]);

        $this->createShape($market, $commercialSpace);
        $this->createShape($market, $commonSpace);
        $this->createShape($market, $infraSpace);

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        $this->assertSame(1, $summary['total_spaces']);
        $this->assertSame(10.0, $summary['total_area_sqm']);
        $this->assertSame(1, $summary['occupied_spaces']);
        $this->assertSame(10.0, $summary['occupied_area_sqm']);
        $this->assertSame(0, $summary['vacant_spaces']);
        $this->assertSame(0.0, $summary['vacant_area_sqm']);
        $this->assertSame(0, $summary['maintenance_spaces']);
        $this->assertSame(0.0, $summary['maintenance_area_sqm']);
        $this->assertSame(10.0, $summary['rentable_area_sqm']);
        $this->assertNull($summary['average_rent_rate_per_sqm']);
    }

    public function test_summary_keeps_maintenance_metric_for_commercial_and_excludes_common_area_maintenance(): void
    {
        $market = Market::query()->create([
            'name' => 'Maintenance Category Market',
            'is_active' => true,
        ]);

        $this->createMarketSpaceType($market, [
            'name_ru' => 'Торговое',
            'code' => 'commercial',
            'category' => MarketSpaceType::CATEGORY_COMMERCIAL,
        ]);

        $this->createMarketSpaceType($market, [
            'name_ru' => 'Санузел',
            'code' => 'wc',
            'category' => MarketSpaceType::CATEGORY_COMMON_AREA,
        ]);

        $commercialMaintenanceSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'COM-M',
            'status' => 'maintenance',
            'type' => 'commercial',
            'area_sqm' => 11.0,
            'is_active' => true,
        ]);

        $commonMaintenanceSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'WC-M',
            'status' => 'maintenance',
            'type' => 'wc',
            'area_sqm' => 22.0,
            'is_active' => true,
        ]);

        $this->createShape($market, $commercialMaintenanceSpace);
        $this->createShape($market, $commonMaintenanceSpace);

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        $this->assertSame(1, $summary['total_spaces']);
        $this->assertSame(11.0, $summary['maintenance_area_sqm']);
        $this->assertSame(1, $summary['maintenance_spaces']);
        $this->assertSame(0.0, $summary['rentable_area_sqm']);
    }

    private function createShape(Market $market, MarketSpace $space): void
    {
        MarketSpaceMapShape::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 0, 'y' => 0],
                ['x' => 10, 'y' => 0],
                ['x' => 10, 'y' => 10],
            ],
            'bbox_x1' => 0,
            'bbox_y1' => 0,
            'bbox_x2' => 10,
            'bbox_y2' => 10,
            'is_active' => true,
        ]);
    }

    private function createMarketSpaceType(Market $market, array $attributes): MarketSpaceType
    {
        return MarketSpaceType::query()->create([
            'market_id' => (int) $market->id,
            'name_ru' => $attributes['name_ru'],
            'code' => $attributes['code'],
            'unit' => 'sqm',
            'price' => 0,
            'currency' => 'RUB',
            'category' => $attributes['category'],
            'is_active' => true,
        ]);
    }
}
