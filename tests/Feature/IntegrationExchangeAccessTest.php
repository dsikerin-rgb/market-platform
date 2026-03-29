<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OpsDiagnostics;
use App\Filament\Resources\IntegrationExchangeResource;
use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Cabinet\TenantImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationExchangeAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_operator_can_open_integration_exchange_index(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-operator', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'operator@example.test',
        ]);
        $user->assignRole('market-operator');

        $this->actingAs($user);

        self::assertTrue(IntegrationExchangeResource::canViewAny());
        self::assertFalse(IntegrationExchangeResource::shouldRegisterNavigation());

        $this->get(IntegrationExchangeResource::getUrl('index'))
            ->assertOk();

        $this->get(OpsDiagnostics::getUrl())
            ->assertOk();
    }

    public function test_market_operator_has_read_only_access_to_integration_journal(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-operator', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'operator-readonly@example.test',
        ]);
        $user->assignRole('market-operator');

        $exchange = IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'entity_type' => 'contract_debts',
            'direction' => IntegrationExchange::DIRECTION_OUT,
            'status' => IntegrationExchange::STATUS_OK,
            'payload' => ['received' => 1],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($user);

        self::assertFalse(IntegrationExchangeResource::canCreate());
        self::assertTrue(IntegrationExchangeResource::canEdit($exchange));
        self::assertFalse(IntegrationExchangeResource::canDelete($exchange));

        $this->get(IntegrationExchangeResource::getUrl('edit', ['record' => $exchange]))
            ->assertOk();
    }

    public function test_super_admin_keeps_full_access_to_integration_journal(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin@example.test',
        ]);
        $user->assignRole('super-admin');

        $exchange = IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'entity_type' => 'contract_debts',
            'direction' => IntegrationExchange::DIRECTION_OUT,
            'status' => IntegrationExchange::STATUS_OK,
            'payload' => ['received' => 1],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $this->actingAs($user);

        self::assertTrue(IntegrationExchangeResource::canViewAny());
        self::assertFalse(IntegrationExchangeResource::shouldRegisterNavigation());
        self::assertTrue(IntegrationExchangeResource::canCreate());
        self::assertTrue(IntegrationExchangeResource::canEdit($exchange));
        self::assertTrue(IntegrationExchangeResource::canDelete($exchange));

        $this->get(OpsDiagnostics::getUrl())
            ->assertOk();
    }

    public function test_market_admin_cannot_access_diagnostics_or_integration_journal(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        self::assertFalse(IntegrationExchangeResource::canViewAny());

        $this->get(IntegrationExchangeResource::getUrl('index'))
            ->assertForbidden();

        $this->get(OpsDiagnostics::getUrl())
            ->assertForbidden();
    }

    public function test_admin_routes_restore_impersonator_during_tenant_impersonation(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('merchant', 'web');

        $admin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'impersonator-admin@example.test',
        ]);
        $admin->assignRole('super-admin');

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant for impersonation',
            'short_name' => 'Tenant for impersonation',
            'is_active' => true,
        ]);

        $tenantUser = $tenant->users()->firstOrFail();

        $this->actingAs($tenantUser);
        $this->withSession([
            TenantImpersonationService::SESSION_KEY => [
                'impersonator_user_id' => (int) $admin->id,
                'tenant_id' => (int) $tenant->id,
                'audit_id' => 1,
                'admin_return_url' => url('/admin'),
            ],
        ]);

        $this->get(OpsDiagnostics::getUrl())
            ->assertOk();
    }
}
