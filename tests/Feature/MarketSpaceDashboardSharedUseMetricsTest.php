<?php
# tests/Feature/MarketSpaceDashboardSharedUseMetricsTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSpaceDashboardSharedUseMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_uses_shared_binding_area_for_shared_use_totals(): void
    {
        $market = Market::query()->create([
            'name' => 'Shared Use Metrics Market',
            'is_active' => true,
        ]);

        $tenantA = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Shared Tenant A',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Shared Tenant B',
            'is_active' => true,
        ]);

        $sharedSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'SH-1',
            'status' => 'occupied',
            'area_sqm' => 2,
            'is_active' => true,
        ]);

        $this->createShape($market, $sharedSpace);

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sharedSpace->id,
            'tenant_id' => (int) $tenantA->id,
            'binding_type' => 'shared_use',
            'source' => 'test',
            'area_sqm' => 5,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sharedSpace->id,
            'tenant_id' => (int) $tenantB->id,
            'binding_type' => 'shared_use',
            'source' => 'test',
            'area_sqm' => 7,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        self::assertSame(1, $summary['total_spaces']);
        self::assertSame(12.0, $summary['total_area_sqm']);
        self::assertSame(1, $summary['occupied_spaces']);
        self::assertSame(12.0, $summary['occupied_area_sqm']);
        self::assertSame(0, $summary['vacant_spaces']);
        self::assertSame(0.0, $summary['vacant_area_sqm']);
        self::assertSame(12.0, $summary['rentable_area_sqm']);
        self::assertSame(2, MarketSpaceDashboardMetrics::countCurrentTenants((int) $market->id));
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
}
