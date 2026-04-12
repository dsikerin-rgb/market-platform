<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantContractResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_contracts_index_renders_with_contract_date_sort(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Зоомир ООО',
            'is_active' => true,
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'external_id' => 'contract-test-1',
            'number' => 'Договор аренды П/3 от 02.04.2026',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'signed_at' => '2026-01-02',
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'period' => '2026-04-01',
            'source_row_hash' => sha1('contract-test-1-2026-04'),
        ]);

        $this->actingAsSuperAdmin();
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $this
            ->get(route('filament.admin.resources.contracts.index', [
                'tableSortColumn' => 'document_date',
                'tableSortDirection' => 'desc',
            ]))
            ->assertOk()
            ->assertSee('Дата договора')
            ->assertSee('02.04.2026');
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
}
