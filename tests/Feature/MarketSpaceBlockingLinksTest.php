<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantContractResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketSpaceBlockingLinksTest extends TestCase
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

    public function test_tenant_accruals_index_filters_by_market_space_id(): void
    {
        $this->actingAsSuperAdmin();

        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $spaceA = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS8 6, 7',
            'is_active' => true,
        ]);

        $spaceB = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS8 8',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_contract_id' => null,
                'market_space_id' => (int) $spaceA->id,
                'period' => '2026-04-01',
                'rent_amount' => 1500,
                'total_with_vat' => 1500,
                'source' => '1c',
                'status' => 'imported',
                'source_file' => 'a-row',
                'source_row_number' => 1,
                'source_row_hash' => hash('sha256', 'a-row'),
                'payload' => json_encode(['calculated_at' => '2026-04-10 12:00:00'], JSON_UNESCAPED_UNICODE),
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_contract_id' => null,
                'market_space_id' => (int) $spaceB->id,
                'period' => '2026-04-01',
                'rent_amount' => 2500,
                'total_with_vat' => 2500,
                'source' => '1c',
                'status' => 'imported',
                'source_file' => 'b-row',
                'source_row_number' => 2,
                'source_row_hash' => hash('sha256', 'b-row'),
                'payload' => json_encode(['calculated_at' => '2026-04-11 12:00:00'], JSON_UNESCAPED_UNICODE),
                'imported_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this
            ->withSession(['filament.admin.selected_market_id' => (int) $market->id])
            ->get(TenantAccrualResource::getUrl('index', [
                'marketSpaceId' => (int) $spaceA->id,
                'tab' => 'all',
            ]));

        $response
            ->assertOk()
            ->assertSeeText('Показаны начисления места: OS8 6, 7')
            ->assertSeeText('Показать все начисления')
            ->assertSeeText('OS8 6, 7')
            ->assertDontSeeText('OS8 8');
    }

    public function test_tenant_contracts_index_filters_by_market_space_id(): void
    {
        $this->actingAsSuperAdmin();

        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $spaceA = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS8 6, 7',
            'is_active' => true,
        ]);

        $spaceB = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS8 8',
            'is_active' => true,
        ]);

        DB::table('tenant_contracts')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'market_space_id' => (int) $spaceA->id,
                'number' => 'CONTRACT-A',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'market_space_id' => (int) $spaceB->id,
                'number' => 'CONTRACT-B',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this
            ->withSession(['filament.admin.selected_market_id' => (int) $market->id])
            ->get(TenantContractResource::getUrl('index', [
                'marketSpaceId' => (int) $spaceA->id,
                'tab' => 'all',
            ]));

        $response
            ->assertOk()
            ->assertSeeText('Показаны договоры места: OS8 6, 7')
            ->assertSeeText('Показать все договоры')
            ->assertSeeText('CONTRACT-A')
            ->assertDontSeeText('CONTRACT-B');
    }
}
