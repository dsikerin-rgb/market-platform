<?php
# tests/Feature/MarketMapWithoutShapesAllTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketMapWithoutShapesAllTest extends TestCase
{
    use RefreshDatabase;

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

    private function createMarketWithMap(string $name = 'Тестовый рынок'): Market
    {
        Storage::fake('local');
        Storage::disk('local')->put('market-maps/map.pdf', 'fake');

        return Market::create([
            'name' => $name,
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [
                'map_pdf_path' => 'market-maps/map.pdf',
            ],
        ]);
    }

    private function selectMarketInSession(Market $market): void
    {
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);
    }

    public function test_without_shapes_all_endpoint_includes_reviewed_spaces_without_shapes(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Арендатор без фигуры',
            'short_name' => 'Без фигуры',
            'is_active' => true,
        ]);

        $reviewedWithoutShape = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'NO-SHAPE-REVIEWED',
            'display_name' => 'Reviewed without shape',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
        ]);

        $pendingWithoutShape = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'NO-SHAPE-PENDING',
            'display_name' => 'Pending without shape',
            'status' => 'vacant',
            'is_active' => true,
            'map_review_status' => null,
        ]);

        $withShape = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'WITH-SHAPE',
            'display_name' => 'Linked with shape',
            'status' => 'vacant',
            'is_active' => true,
            'map_review_status' => null,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $withShape->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 10, 'y' => 10],
                ['x' => 20, 'y' => 10],
                ['x' => 20, 'y' => 20],
            ],
            'bbox_x1' => 10,
            'bbox_y1' => 10,
            'bbox_x2' => 20,
            'bbox_y2' => 20,
            'is_active' => true,
        ]);

        $response = $this->getJson('/admin/market-map/without-shapes-all');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.without_shapes', true)
            ->assertJsonPath('meta.total_count', 2);

        $ids = collect($response->json('items'))->pluck('id')->all();

        $this->assertContains((int) $reviewedWithoutShape->id, $ids);
        $this->assertContains((int) $pendingWithoutShape->id, $ids);
        $this->assertNotContains((int) $withShape->id, $ids);
    }

    public function test_without_shapes_all_endpoint_keeps_total_count_when_search_has_no_matches(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'NO-SHAPE-1',
            'is_active' => true,
        ]);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'NO-SHAPE-2',
            'is_active' => true,
        ]);

        $response = $this->getJson('/admin/market-map/without-shapes-all?q=definitely-no-match');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.total_count', 2)
            ->assertJsonPath('meta.filtered_count', 0)
            ->assertJsonCount(0, 'items');
    }

    public function test_without_shapes_all_endpoint_uses_filament_selected_market_over_dashboard_session(): void
    {
        $this->actingAsSuperAdmin();

        $staleDashboardMarket = $this->createMarketWithMap('Старый рынок dashboard');
        $selectedMapMarket = $this->createMarketWithMap('Выбранный рынок карты');

        $this->withSession([
            'dashboard_market_id' => (int) $staleDashboardMarket->id,
            'filament.admin.selected_market_id' => (int) $selectedMapMarket->id,
        ]);

        $wrongMarketSpace = MarketSpace::create([
            'market_id' => $staleDashboardMarket->id,
            'number' => 'WRONG-MARKET-NO-SHAPE',
            'is_active' => true,
        ]);

        $rightMarketSpace = MarketSpace::create([
            'market_id' => $selectedMapMarket->id,
            'number' => 'RIGHT-MARKET-NO-SHAPE',
            'is_active' => true,
        ]);

        $response = $this->getJson('/admin/market-map/without-shapes-all');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.total_count', 1);

        $ids = collect($response->json('items'))->pluck('id')->all();

        $this->assertContains((int) $rightMarketSpace->id, $ids);
        $this->assertNotContains((int) $wrongMarketSpace->id, $ids);
    }

    public function test_market_map_page_injects_without_shapes_review_fix_script(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $response = $this->get(route('filament.admin.market-map', [
            'mode' => 'review',
        ]));

        $response->assertOk();
        $response->assertSee('data-without-shapes-fix="1"', false);
        $response->assertSee('/admin/market-map/without-shapes-all', false);
    }
}
