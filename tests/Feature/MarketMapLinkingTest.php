<?php
# tests/Feature/MarketMapLinkingTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\MarketSpaceResource\Pages\CreateMarketSpace;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketMapLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        // Явно задаём guard, чтобы роль точно совпала с guard user-а (web)
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        // Filament middleware может быть на web или на отдельном guard.
        $this->loginForPossibleGuards($user);

        return $user;
    }

    private function loginForPossibleGuards(User $user): void
    {
        // Всегда логинимся в web
        $this->actingAs($user, 'web');

        // На всякий случай логинимся и в filament-guard, если он используется/не определён.
        if (! config('auth.guards.filament')) {
            config()->set('auth.guards.filament', [
                'driver' => 'session',
                'provider' => 'users',
            ]);
        }

        $this->actingAs($user, 'filament');
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
        $this->selectMarketInSession($market);

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

    public function test_market_map_opens_parent_group_through_child_shapes(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12',
            'display_name' => 'Ф1-12',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $childA = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $childB = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12-2',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $childA->id,
            'page' => 2,
            'version' => 1,
            'polygon' => [
                ['x' => 10, 'y' => 20],
                ['x' => 30, 'y' => 20],
                ['x' => 30, 'y' => 40],
            ],
            'bbox_x1' => 10,
            'bbox_y1' => 20,
            'bbox_x2' => 30,
            'bbox_y2' => 40,
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $childB->id,
            'page' => 2,
            'version' => 1,
            'polygon' => [
                ['x' => 100, 'y' => 120],
                ['x' => 140, 'y' => 120],
                ['x' => 140, 'y' => 160],
            ],
            'bbox_x1' => 100,
            'bbox_y1' => 120,
            'bbox_x2' => 140,
            'bbox_y2' => 160,
            'is_active' => true,
        ]);

        $response = $this->get(route('filament.admin.market-map', [
            'market_space_id' => $parent->id,
        ]));

        $response->assertOk();
        $response->assertViewIs('admin.market-map');
        $response->assertViewHas('marketSpaceNotLinked', false);
        $response->assertViewHas('marketSpaceId', (int) $parent->id);
        $response->assertViewHas('mapPage', 2);
        $response->assertViewHas('mapVersion', 1);

        $response->assertViewHas('focusShape', function (?array $focusShape) use ($parent): bool {
            if (! $focusShape) {
                return false;
            }

            $bbox = $focusShape['bbox'] ?? [];

            return ($focusShape['market_space_id'] ?? null) === (int) $parent->id
                && ($focusShape['is_group'] ?? false) === true
                && ($focusShape['group_parent_id'] ?? null) === (int) $parent->id
                && ($bbox['x1'] ?? null) === 10.0
                && ($bbox['y1'] ?? null) === 20.0
                && ($bbox['x2'] ?? null) === 140.0
                && ($bbox['y2'] ?? null) === 160.0;
        });
    }

    public function test_market_map_uses_bbox_from_request_when_linked(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

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
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
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

    public function test_market_map_exposes_return_url_from_request(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $returnUrl = url('/admin/market-settings');

        $response = $this->get(route('filament.admin.market-map', [
            'return_url' => $returnUrl,
        ]));

        $response->assertOk();
        $response->assertViewHas('returnUrl', $returnUrl);
    }

    public function test_market_map_uses_honest_review_labels_for_tenant_fallback_cases(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $response = $this->get(route('filament.admin.market-map', [
            'page' => 1,
            'version' => 1,
        ]));

        $response->assertOk();
        $response->assertSee('Ревизионный конфликт', false);
        $response->assertSee('Связь с местом не подтверждена', false);
        $response->assertSee('Связь с местом не подтверждена', false);
        $response->assertDontSee('Спорное место', false);
        $response->assertSee('Точная связь с местом не подтверждена', false);
        $response->assertDontSee('Нет точной связи с местом', false);
        $response->assertDontSee('>Применить уточнение</button>', false);
    }

    public function test_parent_space_edit_page_opens_map_through_child_shapes(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12',
            'display_name' => 'Ф1-12',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'F1-12',
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12-1',
            'display_name' => 'Ф1-12 место 1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_token' => 'F1-12',
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $child->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 1, 'y' => 1],
                ['x' => 2, 'y' => 1],
                ['x' => 2, 'y' => 2],
            ],
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
            'is_active' => true,
        ]);

        $response = $this->get(MarketSpaceResource::getUrl('edit', ['record' => $parent]));

        $response->assertOk();
        $response->assertSee('Показать на карте', false);
        $response->assertDontSee('Нет карты', false);
        $response->assertSee('market_space_id=' . (int) $parent->id, false);
    }

    public function test_market_space_edit_status_view_shows_linked_state(): void
    {
        $linkedView = view('admin.market-space-edit', [
            'isMapLinked' => true,
            'statusText' => 'Торговое место привязано к карте.',
        ]);

        $this->assertStringContainsString(
            'Торговое место привязано к карте.',
            $linkedView->render()
        );

        $unlinkedView = view('admin.market-space-edit', [
            'isMapLinked' => false,
            'statusText' => 'Торговое место не привязано к объектам карты.',
        ]);

        $this->assertStringContainsString(
            'Торговое место не привязано к объектам карты.',
            $unlinkedView->render()
        );
    }
    public function test_market_map_hit_endpoint_exposes_child_group_fields(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $child->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 10, 'y' => 10],
                ['x' => 40, 'y' => 10],
                ['x' => 40, 'y' => 40],
                ['x' => 10, 'y' => 40],
            ],
            'bbox_x1' => 10,
            'bbox_y1' => 10,
            'bbox_x2' => 40,
            'bbox_y2' => 40,
            'is_active' => true,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.hit', [
            'x' => 20,
            'y' => 20,
            'page' => 1,
            'version' => 1,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('hit.market_space_id', (int) $child->id);
        $response->assertJsonPath('hit.space.space_group_role', MarketSpace::SPACE_GROUP_ROLE_CHILD);
        $response->assertJsonPath('hit.space.space_group_parent_id', (int) $parent->id);
    }

    public function test_market_map_space_endpoint_exposes_effective_occupancy_from_parent_group(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'short_name' => 'Parent Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => $parentTenant->id,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
        ]);

        $response = $this->get(route('filament.admin.market-map.space', [
            'id' => $child->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('item.space_effective_is_occupied', true);
        $response->assertJsonPath('item.space_occupancy_source', 'parent');
        $response->assertJsonPath('item.space_effective_tenant_id', (int) $parentTenant->id);
        $response->assertJsonPath('item.space_effective_tenant_name', $parentTenant->display_name);
        $response->assertJsonPath('item.space_occupancy_source_space_number', (string) $parent->number);
    }

    public function test_market_map_shapes_endpoint_exposes_space_group_fields(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12',
            'display_name' => 'Ф1-12 группа',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'F1-12',
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12-1',
            'display_name' => 'Ф1-12 место 1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_token' => 'F1-12',
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $child->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 1, 'y' => 1],
                ['x' => 2, 'y' => 1],
                ['x' => 2, 'y' => 2],
            ],
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
            'is_active' => true,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'page' => 1,
            'version' => 1,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('items.0.market_space_id', (int) $child->id);
        $response->assertJsonPath('items.0.space_group_role', 'child');
        $response->assertJsonPath('items.0.space_group_parent_id', (int) $parent->id);
        $response->assertJsonPath('items.0.space_group_token', 'F1-12');
    }

    public function test_market_map_space_endpoint_marks_parent_group_result(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ф1-12',
            'display_name' => 'Ф1-12 группа',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.space', [
            'id' => $parent->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('found', true);
        $response->assertJsonPath('item.space_group_role', 'parent');
        $response->assertJsonPath('item.result_type', 'group');
        $response->assertJsonPath('item.is_space_group_parent', true);
    }

    public function test_market_map_spaces_search_includes_parent_groups_and_excludes_inactive_places(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $ordinary = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 20',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $archived = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 99',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => false,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.spaces', [
            'q' => 'OS7',
            'limit' => 15,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $items = collect($response->json('items'));
        $ids = $items->pluck('id')->all();

        $this->assertContains((int) $parent->id, $ids);
        $this->assertContains((int) $child->id, $ids);
        $this->assertContains((int) $ordinary->id, $ids);
        $this->assertNotContains((int) $archived->id, $ids);

        $parentItem = $items->firstWhere('id', (int) $parent->id);

        $this->assertSame('parent', $parentItem['space_group_role']);
        $this->assertSame('group', $parentItem['result_type']);
        $this->assertTrue((bool) $parentItem['is_space_group_parent']);
    }

    public function test_market_map_without_shapes_excludes_parent_groups(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $ordinary = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 20',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.spaces', [
            'q' => 'OS7',
            'limit' => 15,
            'without_shapes' => true,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $items = collect($response->json('items'));
        $ids = $items->pluck('id')->all();

        $this->assertNotContains((int) $parent->id, $ids);
        $this->assertContains((int) $child->id, $ids);
        $this->assertContains((int) $ordinary->id, $ids);
    }

    public function test_market_map_spaces_search_is_case_insensitive(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Os8 6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->getJson(route('filament.admin.market-map.spaces', [
            'q' => 'os8',
            'limit' => 15,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $items = collect($response->json('items'));
        $ids = $items->pluck('id')->all();

        $this->assertContains((int) $space->id, $ids);
        $this->assertSame((int) $space->id, (int) ($items->first()['id'] ?? 0));
    }

    public function test_create_market_space_page_creates_space_and_binds_requested_shape(): void
    {
        $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);
        $shape = MarketSpaceMapShape::create([
            'market_id' => $market->id,
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
        $returnUrl = route('filament.admin.market-map', [
            'market_id' => $market->id,
            'page' => 1,
            'version' => 1,
        ]);

        Livewire::withQueryParams([
            'shape_id' => $shape->id,
            'market_id' => $market->id,
            'return_url' => $returnUrl,
            'number' => 'OS7 6',
        ])
            ->test(CreateMarketSpace::class)
            ->fillForm([
                'market_id' => $market->id,
                'number' => 'OS7 6',
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
                'space_group_parent_id' => $parent->id,
                'space_group_slot' => '6',
            ])
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect($returnUrl);

        $space = MarketSpace::query()
            ->where('market_id', $market->id)
            ->where('number', 'OS7 6')
            ->firstOrFail();

        $shape->refresh();

        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, $space->space_group_role);
        $this->assertSame($parent->id, $space->space_group_parent_id);
        $this->assertSame('6', $space->space_group_slot);
        $this->assertNull($space->tenant_id);
        $this->assertSame($space->id, $shape->market_space_id);
    }

    public function test_create_market_space_page_rejects_duplicate_number_for_same_market(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $shape = MarketSpaceMapShape::create([
            'market_id' => $market->id,
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
        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6',
        ]);

        Livewire::withQueryParams([
            'shape_id' => $shape->id,
            'market_id' => $market->id,
        ])
            ->test(CreateMarketSpace::class)
            ->fillForm([
                'market_id' => $market->id,
                'number' => 'OS7 6',
            ])
            ->call('create')
            ->assertHasErrors(['data.number']);

        $shape->refresh();

        $this->assertNull($shape->market_space_id);
        $this->assertSame(1, MarketSpace::query()->where('market_id', $market->id)->where('number', 'OS7 6')->count());
    }

    public function test_create_market_space_page_allows_normal_creation_without_shape_id(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $component = Livewire::test(CreateMarketSpace::class)
            ->fillForm([
                'market_id' => $market->id,
                'number' => 'A-901',
            ])
            ->call('create')
            ->assertHasNoErrors();

        $space = MarketSpace::query()
            ->where('market_id', $market->id)
            ->where('number', 'A-901')
            ->firstOrFail();

        $component->assertRedirect(MarketSpaceResource::getUrl('edit', ['record' => $space]));
        $this->assertNull($space->tenant_id);
    }

    public function test_create_market_space_page_rejects_protocol_relative_return_url(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $component = Livewire::withQueryParams([
            'return_url' => '//attacker.example/path',
        ])
            ->test(CreateMarketSpace::class)
            ->fillForm([
                'market_id' => $market->id,
                'number' => 'A-902',
            ])
            ->call('create')
            ->assertHasNoErrors();

        $space = MarketSpace::query()
            ->where('market_id', $market->id)
            ->where('number', 'A-902')
            ->firstOrFail();

        $component->assertRedirect(MarketSpaceResource::getUrl('edit', ['record' => $space]));
    }

    public function test_market_map_endpoints_expose_inherited_group_financial_status_for_child(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Debt Tenant LLC',
            'short_name' => 'Parent Debt Tenant',
            'external_id' => 'parent-debt-tenant-001',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => $parentTenant->id,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $child->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
            'bbox_x1' => 0,
            'bbox_y1' => 0,
            'bbox_x2' => 10,
            'bbox_y2' => 10,
            'is_active' => true,
        ]);

        $contractExternalId = 'parent-contract-001';

        DB::table('tenant_contracts')->insert([
            'market_id' => $market->id,
            'tenant_id' => $parentTenant->id,
            'market_space_id' => $parent->id,
            'external_id' => $contractExternalId,
            'number' => 'OS7-parent',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contract_debts')->insert([
            'market_id' => $market->id,
            'tenant_id' => $parentTenant->id,
            'tenant_external_id' => $parentTenant->external_id,
            'contract_external_id' => $contractExternalId,
            'period' => '2026-04',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => now()->subDays(35),
            'created_at' => now()->subDays(35),
            'hash' => sha1($parentTenant->external_id . '|' . $contractExternalId . '|2026-04|10000|0|10000'),
        ]);

        $spaceResponse = $this->getJson(route('filament.admin.market-map.space', [
            'id' => $child->id,
        ]));

        $spaceResponse->assertOk();
        $spaceResponse->assertJsonPath('item.space_effective_debt_status', 'red');
        $spaceResponse->assertJsonPath('item.space_effective_debt_status_scope', 'space');
        $spaceResponse->assertJsonPath('item.space_financial_source', 'parent');
        $spaceResponse->assertJsonPath('item.space_financial_source_space_id', (int) $parent->id);
        $spaceResponse->assertJsonPath('item.space_financial_source_space_number', (string) $parent->number);

        $spacesResponse = $this->getJson(route('filament.admin.market-map.spaces', [
            'q' => 'OS7 8',
        ]));

        $spacesResponse->assertOk();
        $spacesResponse->assertJsonCount(1, 'items');
        $spacesResponse->assertJsonPath('items.0.space_effective_debt_status', 'red');
        $spacesResponse->assertJsonPath('items.0.space_financial_source', 'parent');
        $spacesResponse->assertJsonPath('items.0.space_financial_source_space_number', (string) $parent->number);

        $shapesResponse = $this->getJson(route('filament.admin.market-map.shapes', [
            'page' => 1,
            'version' => 1,
        ]));

        $shapesResponse->assertOk();
        $shapesResponse->assertJsonCount(1, 'items');
        $shapesResponse->assertJsonPath('items.0.space_effective_debt_status', 'red');
        $shapesResponse->assertJsonPath('items.0.space_effective_debt_status_scope', 'space');
        $shapesResponse->assertJsonPath('items.0.space_financial_source', 'parent');
        $shapesResponse->assertJsonPath('items.0.space_financial_source_space_id', (int) $parent->id);

        $hitResponse = $this->getJson(route('filament.admin.market-map.hit', [
            'page' => 1,
            'version' => 1,
            'x' => 5,
            'y' => 5,
        ]));

        $hitResponse->assertOk();
        $hitResponse->assertJsonPath('hit.space_effective_debt_status', 'red');
        $hitResponse->assertJsonPath('hit.space_effective_debt_status_scope', 'space');
        $hitResponse->assertJsonPath('hit.space_financial_source', 'parent');
        $hitResponse->assertJsonPath('hit.space_financial_source_space_number', (string) $parent->number);
    }

    public function test_market_map_spaces_search_with_group_parents_only_returns_only_active_parent_groups(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $activeParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Parent-Active',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $inactiveParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Parent-Inactive',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => false,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Child-Space',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $activeParent->id,
            'is_active' => true,
        ]);

        $ordinary = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Ordinary-Space',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        // Test with group_parents_only=1
        $response = $this->getJson(route('filament.admin.market-map.spaces', [
            'group_parents_only' => 1,
            'limit' => 50,
        ]));

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $items = collect($response->json('items'));
        $ids = $items->pluck('id')->all();

        $this->assertCount(1, $ids);
        $this->assertContains($activeParent->id, $ids);
        $this->assertNotContains($inactiveParent->id, $ids);
        $this->assertNotContains($child->id, $ids);
        $this->assertNotContains($ordinary->id, $ids);

        $activeParentItem = $items->firstWhere('id', $activeParent->id);
        $this->assertNotNull($activeParentItem);
        $this->assertSame('group', $activeParentItem['result_type']);
        $this->assertSame('parent', $activeParentItem['space_group_role']);
        $this->assertTrue($activeParentItem['is_space_group_parent']);

        // Additional assertions for active parent structure
        $this->assertArrayHasKey('review_status', $activeParentItem);
        $this->assertArrayHasKey('tenant', $activeParentItem);
        $this->assertArrayHasKey('binding_risk', $activeParentItem);
        $this->assertArrayHasKey('space_effective_debt_status', $activeParentItem);

        // Test that regular search is not broken
        $response = $this->getJson(route('filament.admin.market-map.spaces', [
            'q' => 'Parent',
        ]));
        $response->assertOk();
        $items = collect($response->json('items'));
        $ids = $items->pluck('id')->all();

        $this->assertContains($activeParent->id, $ids);
        $this->assertNotContains($inactiveParent->id, $ids);
    }

    public function test_parent_group_edit_page_shows_group_composition_children(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Группа-А',
            'display_name' => 'Группа мест А',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $childA = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Группа-А-1',
            'display_name' => 'Место А-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '1',
            'status' => 'occupied',
            'tenant_id' => Tenant::create([
                'market_id' => $market->id,
                'name' => 'Tenant A',
                'short_name' => 'Tenant A',
                'is_active' => true,
            ])->id,
            'is_active' => true,
        ]);

        $childB = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Группа-А-2',
            'display_name' => 'Место А-2',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '2',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $childC = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Группа-А-10',
            'display_name' => 'Место А-10',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '10',
            'status' => 'reserved',
            'is_active' => true,
        ]);

        $childD = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Группа-А-Z',
            'display_name' => 'Место А-Z (без слота)',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $response = $this->get(MarketSpaceResource::getUrl('edit', ['record' => $parent]));

        $response->assertOk();
        $response->assertSee('Состав группы', false);
        $response->assertSee('Группа мест А', false);
        $response->assertSee('Группа-А-1', false);
        $response->assertSee('Место А-1', false);
        $response->assertSee('Группа-А-2', false);
        $response->assertSee('Место А-2', false);
        $response->assertSee('Группа-А-10', false);
        $response->assertSee('Место А-10', false);
        $response->assertSee('Группа-А-Z', false);
        $response->assertSee('Место А-Z (без слота)', false);
        $response->assertSee('Открыть', false);

        // Проверяем порядок: 1 -> 2 -> 10 -> Z (пустой слот в конце)
        $content = $response->getOriginalContent();

        if ($content instanceof \Illuminate\View\View) {
            $content = $content->render();
        }

        // Ищем номера мест в порядке появления
        $posChildA = strpos($content, 'Группа-А-1');
        $posChildB = strpos($content, 'Группа-А-2');
        $posChildC = strpos($content, 'Группа-А-10');
        $posChildD = strpos($content, 'Группа-А-Z');

        // Убедимся, что все найдены и в правильном порядке
        $this->assertNotFalse($posChildA, 'Дочернее место А-1 должно быть найдено');
        $this->assertNotFalse($posChildB, 'Дочернее место А-2 должно быть найдено');
        $this->assertNotFalse($posChildC, 'Дочернее место А-10 должно быть найдено');
        $this->assertNotFalse($posChildD, 'Дочернее место А-Z должно быть найдено');
        $this->assertGreaterThan($posChildA, $posChildB, 'Место А-1 должно быть перед А-2');
        $this->assertGreaterThan($posChildB, $posChildC, 'Место А-2 должно быть перед А-10');
        $this->assertGreaterThan($posChildC, $posChildD, 'Место А-10 должно быть перед А-Z (пустой слот в конце)');
    }

    public function test_ordinary_space_edit_page_does_not_show_group_composition_block(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ORD-100',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->get(MarketSpaceResource::getUrl('edit', ['record' => $space]));

        $response->assertOk();
        $response->assertDontSee('Состав группы', false);
    }

    public function test_child_space_edit_page_does_not_show_group_composition_block(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Parent-XYZ',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Child-XYZ-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $response = $this->get(MarketSpaceResource::getUrl('edit', ['record' => $child]));

        $response->assertOk();
        $response->assertDontSee('Состав группы', false);
    }

    public function test_parent_group_with_no_children_shows_empty_message(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'Empty-Group',
            'display_name' => 'Пустая группа',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $response = $this->get(MarketSpaceResource::getUrl('edit', ['record' => $parent]));

        $response->assertOk();
        $response->assertSee('Состав группы', false);
        $response->assertSee('В группе пока нет дочерних мест', false);
    }

    public function test_parent_group_with_tenant_shows_group_composition_children(): void
    {
        $this->actingAsSuperAdmin();

        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        // Создаем арендатора
        $parentTenant = \App\Models\Tenant::create([
            'market_id' => $market->id,
            'name' => 'Тестовый арендатор группы',
            'email' => 'parent-tenant@example.com',
            'inn' => '1234567890',
        ]);

        // Создаем parent-группу с арендатором
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'display_name' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => $parentTenant->id,
            'is_active' => true,
        ]);

        // Создаем child-место без прямого арендатора
        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'display_name' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '8',
            'tenant_id' => null,
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $response = $this->get(MarketSpaceResource::getUrl('edit', ['record' => $parent]));

        $response->assertOk();
        $response->assertSee('Состав группы', false);
        $response->assertSee('OS7 8', false);
        $response->assertSee('Открыть', false);
    }
}
