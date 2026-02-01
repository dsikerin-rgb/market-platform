<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
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

        Role::create(['name' => 'super-admin', 'guard_name' => 'web']);

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
}
