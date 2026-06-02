<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketSpaceOccupancyConsistencyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_space_status_auto_syncs_with_direct_tenant_changes(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Direct Tenant LLC',
            'short_name' => 'Direct Tenant',
            'is_active' => true,
        ]));

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'status' => 'vacant',
            'tenant_id' => null,
        ]);

        $space->update(['tenant_id' => $tenant->id]);
        $space->refresh();
        $this->assertSame('occupied', $space->status);

        $space->update(['tenant_id' => null]);
        $space->refresh();
        $this->assertSame('vacant', $space->status);
    }

    public function test_child_status_auto_syncs_with_parent_tenant_changes(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'short_name' => 'Parent Tenant',
            'is_active' => true,
        ]));

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'status' => 'vacant',
            'tenant_id' => null,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'status' => 'vacant',
            'tenant_id' => null,
        ]);

        $parent->update(['tenant_id' => $tenant->id]);
        $child->refresh();
        $this->assertSame('occupied', $child->status);

        $parent->update(['tenant_id' => null]);
        $child->refresh();
        $this->assertSame('vacant', $child->status);
    }

    public function test_parent_status_change_detaches_child_spaces(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'short_name' => 'Parent Tenant',
            'is_active' => true,
        ]));

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '8',
            'space_group_token' => 'OS7',
            'status' => 'occupied',
            'tenant_id' => null,
        ]);

        $parent->update(['status' => 'maintenance', 'tenant_id' => null]);
        $child->refresh();

        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $child->space_group_role);
        $this->assertNull($child->space_group_parent_id);
        $this->assertNull($child->space_group_slot);
        $this->assertNull($child->space_group_token);
        $this->assertSame('vacant', $child->status);
    }

    public function test_mark_space_free_operation_detaches_child_spaces_from_parent_group(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
        ]);
        $tenant = Tenant::withoutEvents(fn (): Tenant => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'short_name' => 'Parent Tenant',
            'is_active' => true,
        ]));

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '8',
            'space_group_token' => 'OS7',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $parent->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::now('UTC'),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $parent->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
        ]);

        $child->refresh();

        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $child->space_group_role);
        $this->assertNull($child->space_group_parent_id);
        $this->assertSame('vacant', $child->status);
    }

    public function test_creating_child_without_parent_is_rejected(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $this->expectException(ValidationException::class);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_slot' => '8',
        ]);
    }
}
