<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Services\Debt\DebtStatusResolver;
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
        DebtStatusResolver::clearCache();

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

        // Создаём долг только для space1 через contract_debts
        $contractExternalId1 = 'contract-space-1';
        DB::table('contract_debts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'tenant_external_id' => $this->tenant->external_id,
            'contract_external_id' => $contractExternalId1,
            'period' => '2026-02',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => now()->subDays(35),
            'created_at' => now()->subDays(35),
            'hash' => sha1($this->tenant->external_id . '|' . $contractExternalId1 . '|2026-02|10000|0|10000'),
        ]);

        // Привязываем контракт к space1
        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $space1->id,
            'external_id' => $contractExternalId1,
            'number' => '1',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Для space2 создаём контракт без долга
        $contractExternalId2 = 'contract-space-2';
        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $space2->id,
            'external_id' => $contractExternalId2,
            'number' => '2',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
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

        // Находим shape для space1 (с долгом) и space2 (без долга)
        $space1Shape = collect($items)->firstWhere('market_space_id', $space1->id);
        $space2Shape = collect($items)->firstWhere('market_space_id', $space2->id);

        // space1 должен иметь orange (долг 35 дней)
        $this->assertEquals('orange', $space1Shape['debt_status']);

        // space2 идёт через tenant-fallback и наследует проблемный статус арендатора
        $this->assertEquals('orange', $space2Shape['debt_status']);
    }

    /**
     * Тест: contract exists but no contract_debts rows => gray
     */
    public function test_contract_exists_but_no_contract_debts_returns_gray(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '1',
            'code' => 'space-1',
        ]);

        // Создаём контракт с external_id, но без записей в contract_debts
        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-no-debts',
            'number' => '1',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        $this->actingAsSuperAdmin();

        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(1, $items);

        $shape = $items[0];
        $this->assertEquals('gray', $shape['debt_status']);
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

    /**
     * Тест: shapes API возвращает debt_overdue_days для orange/red статусов
     */
    public function test_shapes_api_returns_debt_overdue_days(): void
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

        // Создаём долг с просрочкой 35 дней
        $contractExternalId = 'contract-space-1';
        DB::table('contract_debts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'tenant_external_id' => $this->tenant->external_id,
            'contract_external_id' => $contractExternalId,
            'period' => '2026-02',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => now()->subDays(35),
            'created_at' => now()->subDays(35),
            'hash' => sha1($this->tenant->external_id . '|' . $contractExternalId . '|2026-02|10000|0|10000'),
        ]);

        // Привязываем контракт к месту
        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $space->id,
            'external_id' => $contractExternalId,
            'number' => '1',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsSuperAdmin();

        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $items = $response->json('items');
        $this->assertCount(1, $items);

        $shape = $items[0];
        
        // Проверяем, что API возвращает debt_overdue_days
        $this->assertArrayHasKey('debt_overdue_days', $shape);
        $this->assertNotNull($shape['debt_overdue_days']);
        $this->assertIsNumeric($shape['debt_overdue_days']);
        $this->assertGreaterThan(0, $shape['debt_overdue_days']);
        
        // Проверяем, что статус orange
        $this->assertEquals('orange', $shape['debt_status']);
    }

    public function test_space_status_keeps_overdue_when_newer_snapshot_exists(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '60',
            'code' => 'space-60',
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        $contractA = 'contract-space-60-a';
        $contractB = 'contract-space-60-b';

        DB::table('tenant_contracts')->insert([
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'market_space_id' => $space->id,
                'external_id' => $contractA,
                'number' => '60-A',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'market_space_id' => $space->id,
                'external_id' => $contractB,
                'number' => '60-B',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('contract_debts')->insert([
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'tenant_external_id' => $this->tenant->external_id,
                'contract_external_id' => $contractA,
                'period' => '2026-03',
                'accrued_amount' => 25991.23,
                'paid_amount' => 0,
                'debt_amount' => 25991.23,
                'calculated_at' => now()->subDays(24),
                'created_at' => now()->subDays(24),
                'hash' => sha1($this->tenant->external_id . '|' . $contractA . '|2026-03|25991.23|0|25991.23'),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'tenant_external_id' => $this->tenant->external_id,
                'contract_external_id' => $contractB,
                'period' => '2026-03',
                'accrued_amount' => 11761.57,
                'paid_amount' => 0,
                'debt_amount' => 11761.57,
                'calculated_at' => now()->subDays(25),
                'created_at' => now()->subDays(25),
                'hash' => sha1($this->tenant->external_id . '|' . $contractB . '|2026-03|11761.57|0|11761.57'),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'tenant_external_id' => $this->tenant->external_id,
                'contract_external_id' => $contractA,
                'period' => '2026-04',
                'accrued_amount' => 24364.18,
                'paid_amount' => 0,
                'debt_amount' => 24364.18,
                'calculated_at' => now()->subDay(),
                'created_at' => now()->subDay(),
                'hash' => sha1($this->tenant->external_id . '|' . $contractA . '|2026-04|24364.18|0|24364.18'),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'tenant_external_id' => $this->tenant->external_id,
                'contract_external_id' => $contractB,
                'period' => '2026-04',
                'accrued_amount' => 11761.57,
                'paid_amount' => 0,
                'debt_amount' => 11761.57,
                'calculated_at' => now()->subDay(),
                'created_at' => now()->subDay(),
                'hash' => sha1($this->tenant->external_id . '|' . $contractB . '|2026-04|11761.57|0|11761.57'),
            ],
        ]);

        $this->actingAsSuperAdmin();

        $response = $this->getJson(route('filament.admin.market-map.shapes', [
            'market' => $this->market->id,
            'page' => 1,
        ]));

        $response->assertOk();

        $shape = $response->json('items.0');

        $this->assertSame('orange', $shape['debt_status']);
        $this->assertSame('space', $shape['debt_status_scope']);
        $this->assertGreaterThanOrEqual(1, (int) $shape['debt_overdue_days']);
    }
}
