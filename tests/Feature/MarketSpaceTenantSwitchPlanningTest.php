<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Services\MarketSpaces\TenantSwitchPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketSpaceTenantSwitchPlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_immediate_direct_tenant_switch_updates_snapshot_and_history(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'New Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $oldTenant->id,
            'number' => 'OS8 6',
            'display_name' => 'OS8 6',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $operation = app(TenantSwitchPlanner::class)->plan(
            $space,
            $newTenant,
            now()->subMinute(),
            'Immediate switch for test',
            null,
        );

        $space->refresh();

        $this->assertSame((int) $newTenant->id, (int) $space->tenant_id);
        $this->assertSame((int) $oldTenant->id, (int) ($operation->payload['from_tenant_id'] ?? 0));
        $this->assertSame((int) $newTenant->id, (int) ($operation->payload['to_tenant_id'] ?? 0));
        $this->assertFalse((bool) ($operation->payload['detach_from_group'] ?? false));

        $this->assertDatabaseHas('market_space_tenant_histories', [
            'market_space_id' => (int) $space->id,
            'old_tenant_id' => (int) $oldTenant->id,
            'new_tenant_id' => (int) $newTenant->id,
        ]);
    }

    public function test_future_child_tenant_switch_detaches_place_from_group_on_rebuild(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $groupTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Group Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Future Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $groupTenant->id,
            'number' => 'OS7 6, 8',
            'display_name' => 'OS7 6, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'OS7',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS7 6',
            'display_name' => 'OS7 6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '6',
            'space_group_token' => 'OS7',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $child = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS7 8',
            'display_name' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '8',
            'space_group_token' => 'OS7',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $futureMoment = now()->addDay()->setHour(10)->setMinute(0)->setSecond(0);

        $operation = app(TenantSwitchPlanner::class)->plan(
            $child,
            $newTenant,
            $futureMoment,
            'Child leaves group',
            null,
        );

        $child->refresh();
        $parent->refresh();

        $this->assertNull($child->tenant_id);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, $child->space_group_role);
        $this->assertSame((int) $parent->id, (int) $child->space_group_parent_id);
        $this->assertTrue((bool) ($operation->payload['detach_from_group'] ?? false));

        $this->travelTo($futureMoment->copy()->addHour());

        $this->artisan('operations:rebuild-space-snapshots', [
            '--market-id' => (int) $market->id,
        ])->assertExitCode(0);

        $child->refresh();
        $parent->refresh();

        $this->assertSame((int) $newTenant->id, (int) $child->tenant_id);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $child->space_group_role);
        $this->assertNull($child->space_group_parent_id);
        $this->assertNull($child->space_group_slot);
        $this->assertSame('OS7 6', $parent->number);
    }

    public function test_planner_rejects_second_future_tenant_switch_for_same_place(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'New Tenant',
            'is_active' => true,
        ]);

        $otherTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Other Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $oldTenant->id,
            'number' => 'OS9 1',
            'display_name' => 'OS9 1',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        app(TenantSwitchPlanner::class)->plan(
            $space,
            $newTenant,
            now()->addDay(),
            'First planned switch',
            null,
        );

        $this->expectException(ValidationException::class);

        app(TenantSwitchPlanner::class)->plan(
            $space,
            $otherTenant,
            now()->addDays(2),
            'Second planned switch',
            null,
        );
    }
}
