<?php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketMapLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super-admin');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        return $user;
    }

    private function createMarketWithMap(): Market
    {
        Storage::fake('local');
        Storage::disk('local')->put('market-maps/map.pdf', 'fake');

        return Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [
                'map_pdf_path' => 'market-maps/map.pdf',
            ],
        ]);
    }

    public function test_market_map_returns_unbound_view_when_space_not_linked(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-101',
        ]);

        $response = $this->get(route('filament.admin.market-map', [
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
        ]));

        $response->assertOk();
        $response->assertViewIs('admin.market-map-unbound');
        $response->assertSee('Торговое место не привязано к объектам карты.');
    }

    public function test_market_map_uses_bbox_from_request_when_linked(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'B-202',
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'page' => 2,
            'version' => 1,
            'polygon' => [
                ['x' => 1, 'y' => 1],
                ['x' => 2, 'y' => 1],
                ['x' => 2, 'y' => 2],
            ],
            'is_active' => true,
        ]);

        $response = $this->get(route('filament.admin.market-map', [
            'market_space_id' => $space->id,
            'page' => 2,
            'version' => 1,
            'bbox_x1' => 10,
            'bbox_y1' => 20,
            'bbox_x2' => 30,
            'bbox_y2' => 40,
        ]));

        $response->assertOk();
        $response->assertViewIs('admin.market-map');
        $response->assertViewHas('focusShape', function (?array $focusShape): bool {
            if (! $focusShape) {
                return false;
            }

            $bbox = $focusShape['bbox'] ?? [];

            return ($bbox['x1'] ?? null) === 10.0
                && ($bbox['y1'] ?? null) === 20.0
                && ($bbox['x2'] ?? null) === 30.0
                && ($bbox['y2'] ?? null) === 40.0;
        });
    }

    public function test_market_space_edit_status_view_shows_linked_state(): void
    {
        $linkedView = view('admin.market-space-edit', [
            'isMapLinked' => true,
            'statusText' => 'Торговое место привязано к карте.',
        ]);

        $linkedView->assertSee('Торговое место привязано к карте.');

        $unlinkedView = view('admin.market-space-edit', [
            'isMapLinked' => false,
            'statusText' => 'Торговое место не привязано к объектам карты.',
        ]);

        $unlinkedView->assertSee('Торговое место не привязано к объектам карты.');
    }
}
