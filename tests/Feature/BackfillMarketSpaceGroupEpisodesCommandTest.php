<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillMarketSpaceGroupEpisodesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_create_group_episodes(): void
    {
        [$market] = $this->createCurrentGroup();

        $this->artisan('market-spaces:backfill-group-episodes', [
            '--market' => $market->id,
            '--valid-from' => '2026-06-08',
        ])->assertSuccessful();

        $this->assertDatabaseCount('market_space_group_episodes', 0);
        $this->assertDatabaseCount('market_space_group_episode_children', 0);
    }

    public function test_apply_creates_open_episode_from_current_parent_child_links(): void
    {
        [$market, $parent, $childB, $childA] = $this->createCurrentGroup();

        $this->artisan('market-spaces:backfill-group-episodes', [
            '--market' => $market->id,
            '--valid-from' => '2026-06-08',
            '--apply' => true,
        ])->assertSuccessful();

        $episode = MarketSpaceGroupEpisode::query()->firstOrFail();

        $this->assertSame((int) $market->id, (int) $episode->market_id);
        $this->assertSame((int) $parent->id, (int) $episode->parent_market_space_id);
        $this->assertSame('2026-06-08', $episode->valid_from?->format('Y-m-d'));
        $this->assertNull($episode->valid_to);
        $this->assertSame('backfill_current', $episode->source);

        $children = $episode->children()->orderBy('sort_order')->get();

        $this->assertSame([(int) $childA->id, (int) $childB->id], $children->pluck('child_market_space_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(['1', '2'], $children->pluck('slot')->all());
        $this->assertSame(['10.00', '12.50'], $children->pluck('area_sqm')->all());
    }

    public function test_apply_is_idempotent_for_existing_episode_at_date(): void
    {
        [$market, $parent] = $this->createCurrentGroup();

        MarketSpaceGroupEpisode::query()->create([
            'market_id' => (int) $market->id,
            'parent_market_space_id' => (int) $parent->id,
            'valid_from' => '2026-06-01',
            'valid_to' => '2026-06-30',
            'source' => 'manual',
        ]);

        $this->artisan('market-spaces:backfill-group-episodes', [
            '--market' => $market->id,
            '--valid-from' => '2026-06-08',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('market_space_group_episodes', 1);
        $this->assertDatabaseCount('market_space_group_episode_children', 0);
    }

    public function test_it_skips_parent_groups_without_current_children(): void
    {
        $market = Market::query()->create([
            'name' => 'No Children Market',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'Group Without Children',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $this->artisan('market-spaces:backfill-group-episodes', [
            '--market' => $market->id,
            '--valid-from' => '2026-06-08',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseCount('market_space_group_episodes', 0);
    }

    /**
     * @return array{Market,MarketSpace,MarketSpace,MarketSpace}
     */
    private function createCurrentGroup(): array
    {
        $market = Market::query()->create([
            'name' => 'Group Backfill Market',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS1 1, 2',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $childB = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS1 2',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '2',
            'area_sqm' => 12.5,
            'is_active' => true,
        ]);

        $childA = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS1 1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '1',
            'area_sqm' => 10,
            'is_active' => true,
        ]);

        return [$market, $parent, $childB, $childA];
    }
}
