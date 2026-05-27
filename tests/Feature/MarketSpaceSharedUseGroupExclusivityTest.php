<?php
# tests/Feature/MarketSpaceSharedUseGroupExclusivityTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketSpaceSharedUseGroupExclusivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_use_space_cannot_become_parent_group(): void
    {
        [$market, $space, $tenant] = $this->makeOrdinarySpaceWithTenant();

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $space->id,
            'tenant_id' => (int) $tenant->id,
            'binding_type' => 'shared_use',
            'confidence' => 'medium',
            'source' => 'test',
            'started_at' => now(),
        ]);

        try {
            $space->forceFill([
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            ])->save();

            $this->fail('Shared-use space must not become a parent group.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('space_group_role', $e->errors());
        }
    }

    public function test_shared_use_space_cannot_become_child_group_member(): void
    {
        [$market, $space, $tenant] = $this->makeOrdinarySpaceWithTenant();

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'Parent group',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $space->id,
            'tenant_id' => (int) $tenant->id,
            'binding_type' => 'shared_use',
            'confidence' => 'medium',
            'source' => 'test',
            'started_at' => now(),
        ]);

        try {
            $space->forceFill([
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
                'space_group_parent_id' => (int) $parent->id,
                'space_group_slot' => '1',
            ])->save();

            $this->fail('Shared-use space must not become a child group member.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('space_group_role', $e->errors());
        }
    }

    public function test_group_parent_cannot_receive_active_shared_use_binding(): void
    {
        [$market, $space, $tenant] = $this->makeOrdinarySpaceWithTenant();

        $space->forceFill([
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ])->save();

        try {
            MarketSpaceTenantBinding::query()->create([
                'market_id' => (int) $market->id,
                'market_space_id' => (int) $space->id,
                'tenant_id' => (int) $tenant->id,
                'binding_type' => 'shared_use',
                'confidence' => 'medium',
                'source' => 'test',
                'started_at' => now(),
            ]);

            $this->fail('Parent group must not receive shared-use binding.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('market_space_id', $e->errors());
        }
    }

    public function test_group_child_cannot_receive_active_shared_use_binding(): void
    {
        [$market, $space, $tenant] = $this->makeOrdinarySpaceWithTenant();

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'Parent group',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space->forceFill([
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '1',
        ])->save();

        try {
            MarketSpaceTenantBinding::query()->create([
                'market_id' => (int) $market->id,
                'market_space_id' => (int) $space->id,
                'tenant_id' => (int) $tenant->id,
                'binding_type' => 'shared_use',
                'confidence' => 'medium',
                'source' => 'test',
                'started_at' => now(),
            ]);

            $this->fail('Child group member must not receive shared-use binding.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('market_space_id', $e->errors());
        }
    }

    public function test_ordinary_space_can_receive_active_shared_use_binding(): void
    {
        [$market, $space, $tenant] = $this->makeOrdinarySpaceWithTenant();

        $binding = MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $space->id,
            'tenant_id' => (int) $tenant->id,
            'binding_type' => 'shared_use',
            'confidence' => 'medium',
            'source' => 'test',
            'started_at' => now(),
        ]);

        $this->assertNotNull($binding->id);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, (string) $space->fresh()->space_group_role);
    }

    /**
     * @return array{0: Market, 1: MarketSpace, 2: Tenant}
     */
    private function makeOrdinarySpaceWithTenant(): array
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Test tenant',
            'short_name' => 'Test tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'Shared candidate',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        return [$market, $space, $tenant];
    }
}
