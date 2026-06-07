<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketSpaceGroupEpisodeResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketSpaceGroupEpisodeResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_group_episode_pages(): void
    {
        [$market, $parent] = $this->makeMarketAndParentGroup();
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-group-episodes@example.test',
        ]);
        $user->assignRole('super-admin');
        $this->actingAs($user);

        $episode = MarketSpaceGroupEpisode::query()->create([
            'market_id' => (int) $market->id,
            'parent_market_space_id' => (int) $parent->id,
            'valid_from' => '2026-01-01',
            'valid_to' => '2026-01-31',
            'source' => 'test',
        ]);

        $this->assertTrue(MarketSpaceGroupEpisodeResource::canViewAny());
        $this->assertTrue(MarketSpaceGroupEpisodeResource::shouldRegisterNavigation());

        $this->get(MarketSpaceGroupEpisodeResource::getUrl('index'))->assertOk();
        $this->get(MarketSpaceGroupEpisodeResource::getUrl('create'))->assertOk();
        $this->get(MarketSpaceGroupEpisodeResource::getUrl('edit', ['record' => $episode]))->assertOk();
    }

    public function test_market_admin_can_open_group_episode_index(): void
    {
        [$market] = $this->makeMarketAndParentGroup();
        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-group-episodes@example.test',
        ]);
        $user->assignRole('market-admin');
        $this->actingAs($user);

        $this->assertTrue(MarketSpaceGroupEpisodeResource::canViewAny());
        $this->assertTrue(MarketSpaceGroupEpisodeResource::shouldRegisterNavigation());

        $this->get(MarketSpaceGroupEpisodeResource::getUrl('index'))->assertOk();
    }

    /**
     * @return array{Market,MarketSpace}
     */
    private function makeMarketAndParentGroup(): array
    {
        $market = Market::query()->create([
            'name' => 'Episode Resource Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'ОС1 6, 7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        return [$market, $parent];
    }
}
