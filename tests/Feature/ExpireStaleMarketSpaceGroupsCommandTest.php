<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpireStaleMarketSpaceGroupsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_expire_vacant_parent_group(): void
    {
        [$market, $parent, $child] = $this->createVacantParentGroup();

        $this->artisan('market-spaces:expire-stale-groups', [
            '--market' => (int) $market->id,
            '--effective-date' => '2026-06-20',
        ])->assertSuccessful();

        $parent->refresh();
        $child->refresh();

        $this->assertTrue((bool) $parent->is_active);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_PARENT, (string) $parent->space_group_role);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, (string) $child->space_group_role);
    }

    public function test_apply_expires_vacant_parent_group_even_when_recent_financial_activity_exists(): void
    {
        [$market, $parent, $child] = $this->createVacantParentGroup();
        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Recent Finance Tenant',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $parent->id,
            'period' => '2026-06-01',
            'source_place_code' => 'G1',
            'source_place_name' => 'G1 1, 2',
            'currency' => 'RUB',
            'total_with_vat' => 1000,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('market-spaces:expire-stale-groups', [
            '--market' => (int) $market->id,
            '--effective-date' => '2026-06-20',
            '--apply' => true,
        ])->assertSuccessful();

        $parent->refresh();
        $child->refresh();

        $this->assertFalse((bool) $parent->is_active);
        $this->assertNull($parent->tenant_id);
        $this->assertSame('vacant', (string) $parent->status);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_PARENT, (string) $parent->space_group_role);

        $this->assertTrue((bool) $child->is_active);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, (string) $child->space_group_role);
        $this->assertNull($child->space_group_parent_id);
        $this->assertNull($child->space_group_slot);
        $this->assertNull($child->space_group_token);
        $this->assertSame('vacant', (string) $child->status);

        $episode = MarketSpaceGroupEpisode::query()->firstOrFail();
        $this->assertSame((int) $parent->id, (int) $episode->parent_market_space_id);
        $this->assertSame('2026-06-19', $episode->valid_to?->format('Y-m-d'));
        $this->assertSame('stale_group_expire', (string) $episode->source);
        $this->assertDatabaseHas('market_space_group_episode_children', [
            'market_space_group_episode_id' => (int) $episode->id,
            'child_market_space_id' => (int) $child->id,
            'slot' => '1',
        ]);
    }

    public function test_apply_does_not_expire_parent_group_with_tenant(): void
    {
        [$market, $parent, $child] = $this->createOccupiedParentGroup();

        $this->artisan('market-spaces:expire-stale-groups', [
            '--market' => (int) $market->id,
            '--effective-date' => '2026-06-20',
            '--apply' => true,
        ])->assertSuccessful();

        $parent->refresh();
        $child->refresh();

        $this->assertTrue((bool) $parent->is_active);
        $this->assertNotNull($parent->tenant_id);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_PARENT, (string) $parent->space_group_role);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, (string) $child->space_group_role);
        $this->assertDatabaseCount('market_space_group_episodes', 0);
    }

    /**
     * @return array{Market,MarketSpace,MarketSpace}
     */
    private function createOccupiedParentGroup(): array
    {
        $market = Market::query()->create([
            'name' => 'Occupied Groups Market',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G2 1, 2',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => (int) $tenant->id,
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $child = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G2 1',
            'area_sqm' => 10,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '1',
            'space_group_token' => 'G2',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        return [$market, $parent, $child];
    }

    /**
     * @return array{Market,MarketSpace,MarketSpace}
     */
    private function createVacantParentGroup(): array
    {
        $market = Market::query()->create([
            'name' => 'Expire Groups Market',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G1 1, 2',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => null,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $child = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G1 1',
            'area_sqm' => 10,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '1',
            'space_group_token' => 'G1',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        return [$market, $parent, $child];
    }
}
