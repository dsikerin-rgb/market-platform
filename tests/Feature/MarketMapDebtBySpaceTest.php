<?php

# tests/Feature/MarketMapDebtBySpaceTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Services\Debt\DebtStatusResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
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
            'account' => '62',
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
        $this->assertSame('1', $shape['space_effective_contract_number']);
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
     * Тест: conflict-review отдаётся отдельно от debt_status для overlay.
     */
    public function test_conflict_review_status_is_exposed_separately_from_debt_status(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '77',
            'code' => 'space-77',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [[0, 0], [10, 0], [10, 10], [0, 10]],
        ]);

        $contractExternalId = 'contract-space-77';
        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $space->id,
            'external_id' => $contractExternalId,
            'number' => '77',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('contract_debts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'tenant_external_id' => $this->tenant->external_id,
            'contract_external_id' => $contractExternalId,
            'period' => '2026-04',
            'account' => '62',
            'accrued_amount' => 0,
            'paid_amount' => 0,
            'debt_amount' => 0,
            'calculated_at' => now(),
            'created_at' => now(),
            'hash' => sha1($this->tenant->external_id . '|' . $contractExternalId . '|2026-04|0|0|0'),
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
        $this->assertSame('green', $shape['debt_status']);
        $this->assertSame('conflict', $shape['space_review_status']);
        $this->assertNotEmpty($shape['space_review_status_label']);
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
            'account' => '62',
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
                'account' => '62',
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
                'account' => '62',
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
                'account' => '62',
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
                'account' => '62',
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

    public function test_hit_payload_includes_tenant_net_debt_alongside_space_debt(): void
    {
        $spaceWithDebt = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => 'П68',
            'code' => 'p68',
        ]);

        $spaceWithCredit = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => 'П65/2',
            'code' => 'p65-2',
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $this->market->id,
            'market_space_id' => $spaceWithDebt->id,
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

        DB::table('tenant_contracts')->insert([
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'market_space_id' => $spaceWithDebt->id,
                'external_id' => 'contract-p68',
                'number' => 'П/68',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => now()->subMonth(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'market_space_id' => $spaceWithCredit->id,
                'external_id' => 'contract-p65-2',
                'number' => 'П/65/2',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => now()->subMonth(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('contract_debts')->insert([
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'tenant_external_id' => $this->tenant->external_id,
                'contract_external_id' => 'contract-p68',
                'period' => now()->subMonth()->format('Y-m'),
                'account' => '62',
                'accrued_amount' => 373837.50,
                'paid_amount' => 0,
                'debt_amount' => 373837.50,
                'calculated_at' => now()->subDays(40),
                'created_at' => now()->subDays(40),
                'hash' => sha1('contract-p68'),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $this->tenant->id,
                'tenant_external_id' => $this->tenant->external_id,
                'contract_external_id' => 'contract-p65-2',
                'period' => now()->subMonth()->format('Y-m'),
                'account' => '62',
                'accrued_amount' => 0,
                'paid_amount' => 132097.43,
                'debt_amount' => -132097.43,
                'calculated_at' => now()->subDays(40),
                'created_at' => now()->subDays(40),
                'hash' => sha1('contract-p65-2'),
            ],
        ]);

        $this->actingAsSuperAdmin();

        $response = $this->getJson(route('filament.admin.market-map.hit', [
            'x' => 20,
            'y' => 20,
            'page' => 1,
            'version' => 1,
        ]));

        $response->assertOk();
        $response->assertJsonPath('hit.space_effective_debt_status_scope', 'space');
        $response->assertJsonPath('hit.space_effective_debt_amount', 373837.50);
        $response->assertJsonPath('hit.space_effective_tenant_debt_amount', 241740.07);
        $response->assertJsonPath('hit.space_effective_contract_number', 'П/68');
        $this->assertStringContainsString('/admin/contracts/', (string) $response->json('hit.space_effective_contract_url'));
    }

    public function test_contract_binding_options_returns_active_primary_contracts_for_effective_tenant_only(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '67',
            'code' => 'space-67',
        ]);

        $otherTenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Other tenant',
            'external_id' => 'other-tenant',
        ]);

        $boundElsewhere = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '68',
            'code' => 'space-68',
        ]);

        $available = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'external_id' => 'contract-a-67',
            'number' => 'A/67',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        $occupied = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $boundElsewhere->id,
            'external_id' => 'contract-a-68',
            'number' => 'A/68',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'external_id' => 'inactive-contract',
            'number' => 'A/69',
            'status' => 'inactive',
            'is_active' => false,
            'starts_at' => now()->subMonth(),
        ]);

        TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $otherTenant->id,
            'external_id' => 'other-contract',
            'number' => 'A/70',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'external_id' => 'service-contract',
            'number' => 'OP service',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        $this->actingAsSuperAdmin();

        $response = $this->getJson(route('filament.admin.market-map.spaces.contract-binding-options', [
            'marketSpace' => $space->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('tenant.id', $this->tenant->id);
        $response->assertJsonPath('target_space.id', $space->id);

        $ids = collect($response->json('items'))->pluck('id')->all();
        $this->assertSame([$available->id, $occupied->id], $ids);
        $this->assertFalse((bool) $response->json('items.0.disabled'));
        $this->assertTrue((bool) $response->json('items.1.disabled'));
        $this->assertSame('68', $response->json('items.1.bound_space_label'));
    }

    public function test_market_operator_cannot_bind_contract_from_map(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '67',
            'code' => 'space-67',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'external_id' => 'contract-a-67',
            'number' => 'A/67',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        Role::findOrCreate('market-operator', 'web');
        $user = \App\Models\User::factory()->create(['market_id' => $this->market->id]);
        $user->assignRole('market-operator');
        $this->actingAs($user, 'web');

        $response = $this->postJson(route('filament.admin.market-map.spaces.contract-binding', [
            'marketSpace' => $space->id,
        ]), [
            'tenant_contract_id' => $contract->id,
        ]);

        $response->assertForbidden();
        $this->assertNull($contract->fresh()->market_space_id);
    }

    public function test_contracts_update_permission_can_bind_contract_from_map(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '67',
            'code' => 'space-67',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'external_id' => 'contract-a-67',
            'number' => 'A/67',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        Permission::findOrCreate('contracts.update', 'web');
        $role = Role::findOrCreate('market-accountant', 'web');

        $user = \App\Models\User::factory()->create(['market_id' => $this->market->id]);
        $user->assignRole($role);
        $user->givePermissionTo('contracts.update');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user->fresh(), 'web');

        $response = $this->postJson(route('filament.admin.market-map.spaces.contract-binding', [
            'marketSpace' => $space->id,
        ]), [
            'tenant_contract_id' => $contract->id,
        ]);

        $response->assertOk();
        $this->assertSame($space->id, $contract->fresh()->market_space_id);
    }

    public function test_contract_binding_sets_manual_mapping_and_uses_parent_for_child_space(): void
    {
        $parent = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => 'P67',
            'code' => 'space-p67',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $child = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '67',
            'code' => 'space-67',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '1',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'external_id' => 'contract-a-67',
            'number' => 'A/67',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        $user = $this->actingAsSuperAdmin();

        $response = $this->postJson(route('filament.admin.market-map.spaces.contract-binding', [
            'marketSpace' => $child->id,
        ]), [
            'tenant_contract_id' => $contract->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('target_space_id', $parent->id);
        $response->assertJsonPath('target_source', 'parent');

        $contract->refresh();
        $this->assertSame($parent->id, $contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_MANUAL, $contract->space_mapping_mode);
        $this->assertNotNull($contract->space_mapping_updated_at);
        $this->assertSame($user->id, $contract->space_mapping_updated_by_user_id);
    }

    public function test_contract_binding_rejects_contract_bound_to_another_space(): void
    {
        $target = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '67',
            'code' => 'space-67',
        ]);

        $otherSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'number' => '68',
            'code' => 'space-68',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $this->tenant->id,
            'market_space_id' => $otherSpace->id,
            'external_id' => 'contract-a-68',
            'number' => 'A/68',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => now()->subMonth(),
        ]);

        $this->actingAsSuperAdmin();

        $response = $this->postJson(route('filament.admin.market-map.spaces.contract-binding', [
            'marketSpace' => $target->id,
        ]), [
            'tenant_contract_id' => $contract->id,
        ]);

        $response->assertUnprocessable();
        $this->assertSame($otherSpace->id, $contract->fresh()->market_space_id);
    }
}
