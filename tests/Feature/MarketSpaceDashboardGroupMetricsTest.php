<?php
# tests/Feature/MarketSpaceDashboardGroupMetricsTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSpaceDashboardGroupMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_counts_parent_group_without_shape_and_excludes_child_segments(): void
    {
        $market = Market::query()->create([
            'name' => 'Group Metrics Market',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Parent Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G-1',
            'tenant_id' => (int) $tenant->id,
            'status' => 'occupied',
            'area_sqm' => 100,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G-1-1',
            'status' => 'occupied',
            'area_sqm' => 40,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'is_active' => true,
        ]);

        $ordinaryWithShape = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A-1',
            'status' => 'vacant',
            'area_sqm' => 25,
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A-2',
            'status' => 'vacant',
            'area_sqm' => 99,
            'is_active' => true,
        ]);

        $this->createShape($market, $child);
        $this->createShape($market, $ordinaryWithShape);

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        self::assertSame(2, $summary['total_spaces']);
        self::assertSame(125.0, $summary['total_area_sqm']);
        self::assertSame(1, $summary['occupied_spaces']);
        self::assertSame(100.0, $summary['occupied_area_sqm']);
        self::assertSame(1, $summary['vacant_spaces']);
        self::assertSame(25.0, $summary['vacant_area_sqm']);
        self::assertSame(125.0, $summary['rentable_area_sqm']);
        self::assertSame(1, MarketSpaceDashboardMetrics::countCurrentTenants((int) $market->id));
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
