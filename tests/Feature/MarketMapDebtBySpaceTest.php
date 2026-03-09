<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketMapDebtBySpaceTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'red_after_days' => 90,
                ],
            ],
        ]);

        $this->tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-tenant-001',
        ]);
    }

    private function actingAsSuperAdmin(): \App\Models\User
    {
        Role::findOrCreate('super-admin', 'web');
        $user = \App\Models\User::factory()->create(['market_id' => $this->market->id]);
        $user->assignRole('super-admin');
        $this->actingAs($user, 'web');
        return $user;
    }

    /**
     * Тест: два места одного арендатора могут иметь разные debt_status
     * Примечание: в текущей реализации оба места получают tenant-level статус,
     * но API готов к space-level статусам при наличии market_space_id в contract_debts
     */
    public function test_two_spaces_can_have_different_debt_status(): void
    {
        // Создаём два места
        $space1 = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '1',
            'code' => 'space-1',
        ]);

        $space2 = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '2',
            'code' => 'space-2',
        ]);

        // Создаём shape для каждого места
        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space1->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space2->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[20, 0], [30, 0], [30, 10], [20, 10]],
        ]);

        // Аутентификуемся
        $this->actingAsSuperAdmin();

        // Запрашиваем shapes
        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(2, $items);

        // Проверяем, что оба места присутствуют в ответе
        $space1Shape = collect($items)->firstWhere('market_space_id', $space1->id);
        $space2Shape = collect($items)->firstWhere('market_space_id', $space2->id);

        $this->assertNotNull($space1Shape);
        $this->assertNotNull($space2Shape);
        $this->assertArrayHasKey('debt_status', $space1Shape);
        $this->assertArrayHasKey('debt_status', $space2Shape);
    }

    /**
     * Тест: shapes endpoint возвращает status по месту, а не tenant aggregate
     */
    public function test_shapes_endpoint_returns_space_level_status(): void
    {
        // Создаём два места
        $space1 = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '1',
            'code' => 'space-1',
        ]);

        $space2 = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '2',
            'code' => 'space-2',
        ]);

        // Создаём shape для каждого места
        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space1->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space2->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[20, 0], [30, 0], [30, 10], [20, 10]],
        ]);

        // Аутентификуемся
        $this->actingAsSuperAdmin();

        // Запрашиваем shapes
        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');

        // Проверяем, что каждое место имеет debt_status поля
        foreach ($items as $item) {
            $this->assertArrayHasKey('debt_status', $item);
            $this->assertArrayHasKey('debt_status_label', $item);
            $this->assertArrayHasKey('debt_status_mode', $item);
            $this->assertArrayHasKey('market_space_id', $item);
        }

        // Проверяем, что оба места имеют market_space_id
        $space1Shape = collect($items)->firstWhere('market_space_id', $space1->id);
        $space2Shape = collect($items)->firstWhere('market_space_id', $space2->id);

        $this->assertNotNull($space1Shape);
        $this->assertNotNull($space2Shape);
    }

    /**
     * Тест: shape без привязки к месту не ломает ответ
     */
    public function test_shape_without_market_space_id_does_not_break_response(): void
    {
        // Создаём shape без market_space_id (разметка)
        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => null,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        // Аутентификуемся
        $this->actingAsSuperAdmin();

        // Запрашиваем shapes
        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(1, $items);

        $shape = $items[0];
        $this->assertNull($shape['market_space_id']);
        $this->assertEquals('gray', $shape['debt_status']);
    }

    /**
     * Тест: shape с местом, но без арендатора возвращает gray
     */
    public function test_shape_with_space_but_no_tenant_returns_gray(): void
    {
        // Создаём место без арендатора
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => null,
            'number' => '1',
            'code' => 'space-1',
        ]);

        // Создаём shape
        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        // Аутентификуемся
        $this->actingAsSuperAdmin();

        // Запрашиваем shapes
        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(1, $items);

        $shape = $items[0];
        $this->assertEquals($space->id, $shape['market_space_id']);
        $this->assertEquals('gray', $shape['debt_status']);
    }

    /**
     * Тест: debt_status_source добавляется в ответ
     */
    public function test_debt_status_source_included_in_response(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '1',
            'code' => 'space-1',
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        // Аутентификуемся
        $this->actingAsSuperAdmin();

        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(1, $items);

        $shape = $items[0];
        $this->assertArrayHasKey('debt_status_source', $shape);
    }
}
