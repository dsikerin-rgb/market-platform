<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantContractResource\Pages\EditTenantContract;
use App\Filament\Resources\TenantContractResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantContractResourceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_contracts_index(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-contracts@example.test',
        ]);
        $user->assignRole('super-admin');

        $this->actingAs($user);

        self::assertTrue(TenantContractResource::canViewAny());
        self::assertTrue(TenantContractResource::shouldRegisterNavigation());

        $this->get(TenantContractResource::getUrl('index'))
            ->assertOk();
    }

    public function test_market_admin_can_open_contracts_index(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-contracts@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        self::assertTrue(TenantContractResource::canViewAny());
        self::assertTrue(TenantContractResource::shouldRegisterNavigation());

        $this->get(TenantContractResource::getUrl('index'))
            ->assertOk();
    }

    public function test_market_admin_can_open_contract_card_for_local_mapping(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'П/59У от 01.05.2024',
            'status' => 'active',
            'starts_at' => '2024-05-01',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-contract-card@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        self::assertTrue(TenantContractResource::canEdit($contract));

        $this->get(TenantContractResource::getUrl('edit', ['record' => $contract]))
            ->assertOk();
    }

    public function test_market_admin_space_change_switches_contract_to_manual_mapping_mode(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'P-101',
            'code' => 'p-101',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'П/59У от 01.05.2024',
            'status' => 'active',
            'starts_at' => '2024-05-01',
            'is_active' => true,
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_AUTO,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-contract-lock@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        Livewire::test(EditTenantContract::class, ['record' => (string) $contract->getRouteKey()])
            ->fillForm([
                'market_space_id' => (int) $space->id,
                'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_AUTO,
                'notes' => 'Manual link from contracts workbench',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $contract->refresh();

        $this->assertSame((int) $space->id, (int) $contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_MANUAL, $contract->space_mapping_mode);
        $this->assertNotNull($contract->space_mapping_updated_at);
        $this->assertSame((int) $user->id, (int) $contract->space_mapping_updated_by_user_id);
    }

    public function test_market_admin_can_exclude_contract_from_space_mapping(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'P-102',
            'code' => 'p-102',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'number' => 'Договор на возмещение коммунальных услуг от 01.07.',
            'status' => 'active',
            'starts_at' => '2024-07-01',
            'is_active' => true,
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_MANUAL,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-contract-excluded@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        Livewire::test(EditTenantContract::class, ['record' => (string) $contract->getRouteKey()])
            ->fillForm([
                'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_EXCLUDED,
                'market_space_id' => (int) $space->id,
                'notes' => 'Do not map utility agreements to places',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $contract->refresh();

        $this->assertNull($contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_EXCLUDED, $contract->space_mapping_mode);
        $this->assertNotNull($contract->space_mapping_updated_at);
        $this->assertSame((int) $user->id, (int) $contract->space_mapping_updated_by_user_id);
    }

    public function test_market_manager_can_open_contract_card_in_read_only_mode(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-manager', 'web');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'П/60У от 02.05.2024',
            'status' => 'active',
            'starts_at' => '2024-05-02',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-manager-contract-card@example.test',
        ]);
        $user->assignRole('market-manager');

        $this->actingAs($user);

        self::assertTrue(TenantContractResource::canEdit($contract));

        $this->get(TenantContractResource::getUrl('edit', ['record' => $contract]))
            ->assertOk();
    }

    public function test_market_operator_cannot_open_contracts_index(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-operator', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-operator-contracts@example.test',
        ]);
        $user->assignRole('market-operator');

        $this->actingAs($user);

        self::assertFalse(TenantContractResource::canViewAny());
        self::assertFalse(TenantContractResource::shouldRegisterNavigation());

        $this->get(TenantContractResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_market_operator_cannot_open_contract_card(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-operator', 'web');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'П/61У от 03.05.2024',
            'status' => 'active',
            'starts_at' => '2024-05-03',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-operator-contract-card@example.test',
        ]);
        $user->assignRole('market-operator');

        $this->actingAs($user);

        self::assertFalse(TenantContractResource::canEdit($contract));

        $this->get(TenantContractResource::getUrl('edit', ['record' => $contract]))
            ->assertNotFound();
    }
}
