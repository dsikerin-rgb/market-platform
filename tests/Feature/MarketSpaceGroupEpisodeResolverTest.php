<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Models\MarketSpaceGroupEpisodeChild;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Support\MarketSpaces\MarketSpaceGroupEpisodeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSpaceGroupEpisodeResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_historical_group_children_for_contract_date(): void
    {
        [$market, $tenant, $parent, $historicalChild, $currentChild] = $this->makeGroupFixture();

        $episode = MarketSpaceGroupEpisode::query()->create([
            'market_id' => (int) $market->id,
            'parent_market_space_id' => (int) $parent->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-01-31',
            'source' => 'test',
        ]);

        MarketSpaceGroupEpisodeChild::query()->create([
            'market_space_group_episode_id' => (int) $episode->id,
            'child_market_space_id' => (int) $historicalChild->id,
            'slot' => '6',
            'sort_order' => 1,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $parent->id,
            'number' => 'ОС1-6/7 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $resolved = app(MarketSpaceGroupEpisodeResolver::class)->forContract($contract, '2026-01-15');

        $this->assertTrue($resolved['applies']);
        $this->assertSame('episode', $resolved['source']);
        $this->assertSame((int) $parent->id, (int) $resolved['parent']->id);
        $this->assertSame([(int) $historicalChild->id], $resolved['children']->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNotContains((int) $currentChild->id, $resolved['children']->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_it_falls_back_to_current_children_when_episode_is_missing(): void
    {
        [$market, $tenant, $parent, , $currentChild] = $this->makeGroupFixture();

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $parent->id,
            'number' => 'ОС1-6/7 от 01.02.2026',
            'status' => 'active',
            'starts_at' => '2026-02-01',
            'is_active' => true,
        ]);

        $resolved = app(MarketSpaceGroupEpisodeResolver::class)->forContract($contract, '2026-02-15');

        $this->assertTrue($resolved['applies']);
        $this->assertSame('current', $resolved['source']);
        $this->assertSame([(int) $currentChild->id], $resolved['children']->pluck('id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * @return array{Market,Tenant,MarketSpace,MarketSpace,MarketSpace}
     */
    private function makeGroupFixture(): array
    {
        $market = Market::query()->create([
            'name' => 'Group Episodes Market',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Group Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'ОС1 6, 7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => (int) $tenant->id,
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $historicalChild = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'ОС1 6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'area_sqm' => 10,
            'is_active' => true,
        ]);

        $currentChild = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'ОС1 7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '7',
            'area_sqm' => 12,
            'is_active' => true,
        ]);

        return [$market, $tenant, $parent, $historicalChild, $currentChild];
    }
}
