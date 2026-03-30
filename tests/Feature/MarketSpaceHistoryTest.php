<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketSpaceHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_change_creates_history_record(): void
    {
        Carbon::setTestNow('2025-02-01 10:00:00');

        $market = Market::create(['name' => 'Тестовый рынок']);

        $tenantA = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Первый',
        ]);

        $tenantB = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Второй',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantA->id,
            'number' => 'A-1',
            'status' => 'occupied',
        ]);

        Carbon::setTestNow('2025-02-02 12:00:00');

        $space->tenant_id = $tenantB->id;
        $space->save();

        $row = DB::table('market_space_tenant_histories')->first();

        $this->assertNotNull($row);
        $this->assertSame($space->id, (int) $row->market_space_id);
        $this->assertSame($tenantA->id, (int) $row->old_tenant_id);
        $this->assertSame($tenantB->id, (int) $row->new_tenant_id);
        $this->assertSame('2025-02-02 12:00:00', Carbon::parse($row->changed_at)->format('Y-m-d H:i:s'));
    }

    public function test_tenant_change_creates_binding_snapshot_record(): void
    {
        Carbon::setTestNow('2025-02-01 10:00:00');

        $market = Market::create(['name' => 'Тестовый рынок']);

        $tenantA = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Первый',
        ]);

        $tenantB = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Второй',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantA->id,
            'number' => 'A-1',
            'status' => 'occupied',
        ]);

        Carbon::setTestNow('2025-02-02 12:00:00');

        $space->tenant_id = $tenantB->id;
        $space->save();

        $bindings = MarketSpaceTenantBinding::query()
            ->where('market_space_id', $space->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $bindings);
        $this->assertSame('space_snapshot', $bindings[0]->binding_type);
        $this->assertSame($tenantB->id, $bindings[0]->tenant_id);
        $this->assertNull($bindings[0]->tenant_contract_id);
        $this->assertSame('2025-02-02 12:00:00', $bindings[0]->started_at?->format('Y-m-d H:i:s'));
        $this->assertNull($bindings[0]->ended_at);
    }

    public function test_rent_rate_change_creates_history_and_updates_timestamp(): void
    {
        Carbon::setTestNow('2025-03-01 09:00:00');

        $market = Market::create(['name' => 'Тестовый рынок']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'B-2',
            'status' => 'vacant',
        ]);

        $space->rent_rate_value = 1500;
        $space->rent_rate_unit = 'per_sqm_month';
        $space->save();

        $row = DB::table('market_space_rent_rate_histories')->first();

        $this->assertNotNull($row);
        $this->assertSame($space->id, (int) $row->market_space_id);
        $this->assertSame('per_sqm_month', $row->unit);
        $this->assertSame('2025-03-01 09:00:00', Carbon::parse($row->changed_at)->format('Y-m-d H:i:s'));

        $space->refresh();
        $this->assertSame('2025-03-01 09:00:00', $space->rent_rate_updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_hit_payload_contains_tenant_id(): void
    {
        $market = Market::create(['name' => 'Тестовый рынок']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C-3',
            'status' => 'occupied',
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 0, 'y' => 0],
                ['x' => 10, 'y' => 0],
                ['x' => 10, 'y' => 10],
                ['x' => 0, 'y' => 10],
            ],
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $response = $this
            ->actingAs($user)
            ->withSession(['filament.admin.selected_market_id' => $market->id])
            ->getJson(route('filament.admin.market-map.hit', [
                'x' => 5,
                'y' => 5,
                'page' => 1,
                'version' => 1,
            ]));

        $response->assertOk();
        $response->assertJsonPath('hit.tenant.id', $tenant->id);
        $response->assertJsonPath('hit.tenant_id', $tenant->id);
    }

    public function test_contract_binding_creates_and_closes_exact_binding_records(): void
    {
        Carbon::setTestNow('2025-03-01 09:00:00');

        $market = Market::create(['name' => 'Тестовый рынок']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
        ]);

        $spaceA = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'B-1',
            'status' => 'occupied',
        ]);

        $spaceB = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'B-2',
            'status' => 'occupied',
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $spaceA->id,
            'number' => 'Договор B-1',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $binding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $contract->id)
            ->whereNull('ended_at')
            ->first();

        $this->assertNotNull($binding);
        $this->assertSame('exact', $binding->binding_type);
        $this->assertSame($spaceA->id, $binding->market_space_id);
        $this->assertSame('2025-03-01 00:00:00', $binding->started_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow('2025-03-10 15:30:00');

        $contract->market_space_id = $spaceB->id;
        $contract->save();

        $closedBinding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $contract->id)
            ->where('market_space_id', $spaceA->id)
            ->first();

        $activeBinding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $contract->id)
            ->where('market_space_id', $spaceB->id)
            ->whereNull('ended_at')
            ->first();

        $this->assertNotNull($closedBinding?->ended_at);
        $this->assertSame('2025-03-10 15:30:00', $closedBinding->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('contract_rebound', $closedBinding->resolution_reason);
        $this->assertNotNull($activeBinding);

        Carbon::setTestNow('2025-03-20 08:00:00');

        $contract->space_mapping_mode = TenantContract::SPACE_MAPPING_MODE_EXCLUDED;
        $contract->save();

        $this->assertDatabaseMissing('market_space_tenant_bindings', [
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $spaceB->id,
            'ended_at' => null,
        ]);
    }

    public function test_service_contract_does_not_create_binding_when_primary_contract_exists_for_same_tenant_and_space(): void
    {
        Carbon::setTestNow('2025-03-01 09:00:00');

        $market = Market::create(['name' => 'Тестовый рынок']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'ФК1',
            'status' => 'occupied',
        ]);

        $primary = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'Ф/К-1 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $service = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'ОП Ф/К-1 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'tenant_contract_id' => $primary->id,
            'market_space_id' => $space->id,
            'ended_at' => null,
        ]);

        $this->assertDatabaseMissing('market_space_tenant_bindings', [
            'tenant_contract_id' => $service->id,
            'ended_at' => null,
        ]);
    }

    public function test_primary_contract_closes_existing_service_binding_for_same_tenant_and_space(): void
    {
        Carbon::setTestNow('2025-03-01 09:00:00');

        $market = Market::create(['name' => 'Тестовый рынок']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'ФК1',
            'status' => 'occupied',
        ]);

        $service = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'ОП Ф/К-1 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'tenant_contract_id' => $service->id,
            'market_space_id' => $space->id,
            'ended_at' => null,
        ]);

        Carbon::setTestNow('2025-03-10 15:30:00');

        $primary = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'Ф/К-1 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2025-03-10',
            'is_active' => true,
        ]);

        $serviceBinding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $service->id)
            ->first();

        $primaryBinding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $primary->id)
            ->whereNull('ended_at')
            ->first();

        $this->assertNotNull($serviceBinding?->ended_at);
        $this->assertSame('2025-03-10 15:30:00', $serviceBinding->ended_at?->format('Y-m-d H:i:s'));
        $this->assertSame('superseded_by_contract_binding', $serviceBinding->resolution_reason);
        $this->assertNotNull($primaryBinding);
    }
}
