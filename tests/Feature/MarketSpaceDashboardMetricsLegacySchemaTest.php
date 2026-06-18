<?php
# tests/Feature/MarketSpaceDashboardMetricsLegacySchemaTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceType;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketSpaceDashboardMetricsLegacySchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_tolerates_market_space_types_without_category_column(): void
    {
        $market = Market::query()->create([
            'name' => 'Legacy Type Schema Market',
            'is_active' => true,
        ]);

        MarketSpaceType::query()->create([
            'market_id' => (int) $market->id,
            'name_ru' => 'Торговое',
            'code' => 'commercial',
            'unit' => 'sqm',
            'price' => 0,
            'currency' => 'RUB',
            'category' => MarketSpaceType::CATEGORY_COMMERCIAL,
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'LEGACY-1',
            'type' => 'commercial',
            'status' => 'vacant',
            'area_sqm' => 10,
            'is_active' => true,
        ]);

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

        if (Schema::hasColumn('market_space_types', 'category')) {
            Schema::table('market_space_types', function ($table): void {
                $table->dropColumn('category');
            });
        }

        $summary = MarketSpaceDashboardMetrics::summarize((int) $market->id);

        $this->assertSame(1, $summary['total_spaces']);
        $this->assertSame(10.0, $summary['total_area_sqm']);
        $this->assertSame(1, $summary['vacant_spaces']);
    }
}
