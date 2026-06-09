<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceGroupEpisode;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketMapSpaceSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_space_creates_new_spaces_shapes_episode_and_moves_contract(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant Split',
            'is_active' => true,
        ]);

        $sourceSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'ST2-4',
            'area_sqm' => 50.20,
            'rent_rate_value' => 1000,
            'rent_rate_unit' => 'per_sqm',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $sourceShape = MarketSpaceMapShape::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sourceSpace->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 10, 'y' => 20],
                ['x' => 110, 'y' => 20],
                ['x' => 110, 'y' => 80],
                ['x' => 10, 'y' => 80],
            ],
            'fill_color' => '#00A3FF',
            'stroke_color' => '#00A3FF',
            'fill_opacity' => 0.12,
            'stroke_width' => 1.5,
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $sourceSpace->id,
            'number' => 'ST-2',
            'status' => 'active',
            'starts_at' => '2026-06-01',
            'is_active' => true,
        ]);

        $response = $this->postJson('/admin/market-map/spaces/'.$sourceSpace->id.'/split', [
            'shape_id' => (int) $sourceShape->id,
            'orientation' => 'vertical',
            'split_date' => '2026-06-01',
            'episode_valid_from' => '2024-06-01',
            'first' => [
                'number' => 'ST 2',
                'tenant_id' => (int) $tenant->id,
                'area_sqm' => 25.10,
                'contract_ids' => [(int) $contract->id],
            ],
            'second' => [
                'number' => 'ST 3',
                'tenant_id' => null,
                'area_sqm' => 25.10,
                'contract_ids' => [],
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $sourceSpace->refresh();
        $this->assertFalse((bool) $sourceSpace->is_active);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_PARENT, (string) $sourceSpace->space_group_role);
        $this->assertNull($sourceSpace->tenant_id);

        $firstSpace = MarketSpace::query()->where('market_id', (int) $market->id)->where('number', 'ST 2')->firstOrFail();
        $secondSpace = MarketSpace::query()->where('market_id', (int) $market->id)->where('number', 'ST 3')->firstOrFail();

        $this->assertTrue((bool) $firstSpace->is_active);
        $this->assertSame((int) $tenant->id, (int) $firstSpace->tenant_id);
        $this->assertSame('occupied', (string) $firstSpace->status);
        $this->assertTrue((bool) $secondSpace->is_active);
        $this->assertNull($secondSpace->tenant_id);
        $this->assertSame('vacant', (string) $secondSpace->status);

        $this->assertFalse((bool) $sourceShape->fresh()->is_active);
        $this->assertDatabaseHas('market_space_map_shapes', [
            'market_space_id' => (int) $firstSpace->id,
            'bbox_x1' => 10,
            'bbox_x2' => 60,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('market_space_map_shapes', [
            'market_space_id' => (int) $secondSpace->id,
            'bbox_x1' => 60,
            'bbox_x2' => 110,
            'is_active' => true,
        ]);

        $episode = MarketSpaceGroupEpisode::query()->where('parent_market_space_id', (int) $sourceSpace->id)->firstOrFail();
        $this->assertSame('2024-06-01', $episode->valid_from?->toDateString());
        $this->assertSame('2026-05-31', $episode->valid_to?->toDateString());
        $this->assertSame('map_split', (string) $episode->source);
        $this->assertSame((int) $user->id, (int) $episode->created_by_user_id);
        $this->assertCount(2, $episode->children()->get());

        $contract->refresh();
        $this->assertSame((int) $firstSpace->id, (int) $contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_MANUAL, (string) $contract->space_mapping_mode);
    }

    public function test_split_parent_shape_can_use_existing_spaces_without_shapes(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant Existing Target',
            'is_active' => true,
        ]);

        $sourceSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'ST2-4',
            'area_sqm' => 50.20,
            'rent_rate_value' => 1000,
            'rent_rate_unit' => 'per_sqm',
            'status' => 'occupied',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => false,
        ]);

        $firstSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'ST 2',
            'area_sqm' => null,
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $secondSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'number' => 'ST 3',
            'area_sqm' => null,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $sourceShape = MarketSpaceMapShape::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sourceSpace->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 10, 'y' => 20],
                ['x' => 110, 'y' => 20],
                ['x' => 110, 'y' => 80],
                ['x' => 10, 'y' => 80],
            ],
            'fill_color' => '#00A3FF',
            'stroke_color' => '#00A3FF',
            'fill_opacity' => 0.12,
            'stroke_width' => 1.5,
            'is_active' => true,
        ]);

        $this->assertSame(3, MarketSpace::query()->where('market_id', (int) $market->id)->count());

        $response = $this->postJson('/admin/market-map/spaces/'.$sourceSpace->id.'/split', [
            'shape_id' => (int) $sourceShape->id,
            'orientation' => 'vertical',
            'split_date' => '2026-06-09',
            'episode_valid_from' => '2024-06-01',
            'first' => [
                'target_space_id' => (int) $firstSpace->id,
                'area_sqm' => 25.10,
            ],
            'second' => [
                'target_space_id' => (int) $secondSpace->id,
                'area_sqm' => 25.10,
            ],
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(3, MarketSpace::query()->where('market_id', (int) $market->id)->count());

        $sourceSpace->refresh();
        $firstSpace->refresh();
        $secondSpace->refresh();

        $this->assertFalse((bool) $sourceSpace->is_active);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_PARENT, (string) $sourceSpace->space_group_role);
        $this->assertSame((int) $tenant->id, (int) $sourceSpace->tenant_id);
        $this->assertSame('25.10', (string) $firstSpace->area_sqm);
        $this->assertSame('25.10', (string) $secondSpace->area_sqm);

        $this->assertFalse((bool) $sourceShape->fresh()->is_active);
        $this->assertDatabaseHas('market_space_map_shapes', [
            'market_space_id' => (int) $firstSpace->id,
            'bbox_x1' => 10,
            'bbox_x2' => 60,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('market_space_map_shapes', [
            'market_space_id' => (int) $secondSpace->id,
            'bbox_x1' => 60,
            'bbox_x2' => 110,
            'is_active' => true,
        ]);

        $episode = MarketSpaceGroupEpisode::query()->where('parent_market_space_id', (int) $sourceSpace->id)->firstOrFail();
        $this->assertSame('2024-06-01', $episode->valid_from?->toDateString());
        $this->assertSame('2026-06-08', $episode->valid_to?->toDateString());
        $this->assertSame('existing_target_spaces', (string) ($episode->meta['mode'] ?? ''));
        $this->assertSame((int) $user->id, (int) $episode->created_by_user_id);
        $this->assertDatabaseHas('market_space_group_episode_children', [
            'market_space_group_episode_id' => (int) $episode->id,
            'child_market_space_id' => (int) $firstSpace->id,
            'sort_order' => 1,
        ]);
        $this->assertDatabaseHas('market_space_group_episode_children', [
            'market_space_group_episode_id' => (int) $episode->id,
            'child_market_space_id' => (int) $secondSpace->id,
            'sort_order' => 2,
        ]);
    }

    public function test_hit_test_supports_inactive_parent_space_with_active_shape(): void
    {
        $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant Parent Shape',
            'is_active' => true,
        ]);

        $sourceSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'ST2-4',
            'area_sqm' => 50.20,
            'status' => 'occupied',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => false,
        ]);

        MarketSpaceMapShape::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sourceSpace->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 10, 'y' => 20],
                ['x' => 110, 'y' => 20],
                ['x' => 110, 'y' => 80],
                ['x' => 10, 'y' => 80],
            ],
            'is_active' => true,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.hit', [
            'page' => 1,
            'version' => 1,
            'x' => 60,
            'y' => 50,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('hit.market_space_id', (int) $sourceSpace->id);
        $response->assertJsonPath('hit.space.space_group_role', MarketSpace::SPACE_GROUP_ROLE_PARENT);
        $response->assertJsonPath('hit.space.is_active', false);
        $response->assertJsonPath('hit.space.number', 'ST2-4');
        $response->assertJsonPath('hit.tenant.id', (int) $tenant->id);
    }

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user, 'web');

        if (! config('auth.guards.filament')) {
            config()->set('auth.guards.filament', [
                'driver' => 'session',
                'provider' => 'users',
            ]);
        }
        $this->actingAs($user, 'filament');

        return $user;
    }

    private function selectMarketInSession(Market $market): void
    {
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);
    }

    private function createMarketWithMap(): Market
    {
        Storage::fake('local');
        Storage::disk('local')->put('market-maps/map.pdf', 'fake');

        return Market::query()->create([
            'name' => 'Split Map Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [
                'map_pdf_path' => 'market-maps/map.pdf',
            ],
        ]);
    }
}
