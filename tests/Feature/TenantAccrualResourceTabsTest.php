<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAccrualResourceTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_keeps_one_c_tabs_visible_even_when_one_c_accruals_are_absent(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-01-01',
            'source' => 'excel',
            'status' => 'imported',
            'currency' => 'RUB',
            'source_row_hash' => hash('sha256', 'excel-row-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-accruals@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        $this->get(TenantAccrualResource::getUrl('index'))
            ->assertOk()
            ->assertSee('1С')
            ->assertSee('Связаны с договором')
            ->assertSee('Без договора')
            ->assertSee('Исторический импорт')
            ->assertSee('Все начисления');
    }

    public function test_page_shows_one_c_tabs_when_one_c_accruals_exist(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-03-01',
            'source' => '1c',
            'status' => 'imported',
            'contract_link_status' => 'ambiguous',
            'currency' => 'RUB',
            'source_row_hash' => hash('sha256', '1c-row-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-onec-accruals@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        $this->get(TenantAccrualResource::getUrl('index'))
            ->assertOk()
            ->assertSee('1С')
            ->assertSee('Связаны с договором')
            ->assertSee('Без договора')
            ->assertSee('Неоднозначные')
            ->assertSee('Все начисления');
    }
}
