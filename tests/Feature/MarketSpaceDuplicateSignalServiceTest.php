<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Services\MarketSpaces\MarketSpaceDuplicateSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSpaceDuplicateSignalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_duplicate_active_spaces_by_normalized_number(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenantA = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Tenant A',
            'short_name' => 'Tenant A',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Tenant B',
            'short_name' => 'Tenant B',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => $market->id,
            'number' => 'ФК 8/1',
            'display_name' => 'Office',
            'tenant_id' => $tenantA->id,
            'status' => 'occupied',
            'is_active' => true,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
        ]);

        MarketSpace::query()->create([
            'market_id' => $market->id,
            'number' => '  фк   8/1 ',
            'display_name' => 'Storage',
            'tenant_id' => $tenantB->id,
            'status' => 'occupied',
            'is_active' => true,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
        ]);

        $signals = app(MarketSpaceDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertCount(1, $signals);
        $this->assertSame('market_space_duplicate_number', $signals[0]['type']);
        $this->assertSame('фк 8/1', $signals[0]['normalized_number']);
        $this->assertSame('high', $signals[0]['severity']);
        $this->assertSame(2, $signals[0]['count']);
        $this->assertCount(2, $signals[0]['spaces']);
    }

    public function test_it_ignores_inactive_duplicates(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'display_name' => 'First',
            'status' => 'vacant',
            'is_active' => true,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
        ]);

        MarketSpace::query()->create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'display_name' => 'Retired',
            'status' => 'vacant',
            'is_active' => false,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
        ]);

        $signals = app(MarketSpaceDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertSame([], $signals);
    }
}
