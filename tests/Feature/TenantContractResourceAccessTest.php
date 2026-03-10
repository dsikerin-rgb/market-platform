<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantContractResource;
use App\Models\Market;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
