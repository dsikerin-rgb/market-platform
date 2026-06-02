<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReconcileMarketSpaceOccupancyCommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_command_repairs_orphan_child_and_status_drift(): void
    {
        $market = Market::create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant One',
            'is_active' => true,
        ]));

        $invalidParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $childWithLostParent = MarketSpace::withoutEvents(fn (): MarketSpace => MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $invalidParent->id,
            'space_group_slot' => '8',
            'space_group_token' => 'OS7',
            'status' => 'occupied',
            'tenant_id' => null,
            'is_active' => true,
        ]));

        $spaceWithTenantButVacantStatus = MarketSpace::withoutEvents(fn (): MarketSpace => MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'status' => 'vacant',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]));

        $this->artisan('market-spaces:reconcile-occupancy', ['--market-id' => $market->id])
            ->expectsOutput('Mode: dry-run')
            ->assertSuccessful();

        $childWithLostParent->refresh();
        $spaceWithTenantButVacantStatus->refresh();

        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, $childWithLostParent->space_group_role);
        $this->assertSame('vacant', $spaceWithTenantButVacantStatus->status);

        $this->artisan('market-spaces:reconcile-occupancy', ['--market-id' => $market->id, '--apply' => true])
            ->expectsOutput('Mode: apply')
            ->assertSuccessful();

        $childWithLostParent->refresh();
        $spaceWithTenantButVacantStatus->refresh();

        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $childWithLostParent->space_group_role);
        $this->assertNull($childWithLostParent->space_group_parent_id);
        $this->assertNull($childWithLostParent->space_group_slot);
        $this->assertNull($childWithLostParent->space_group_token);
        $this->assertSame('vacant', $childWithLostParent->status);

        $this->assertSame('occupied', $spaceWithTenantButVacantStatus->status);
    }
}
