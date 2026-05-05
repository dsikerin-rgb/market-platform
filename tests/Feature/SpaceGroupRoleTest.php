<?php
# tests/Feature/SpaceGroupRoleTest.php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SpaceGroupRoleTest extends TestCase
{
    use DatabaseTransactions;

    // === Legacy token/slot tests ===

    public function test_none_role_clears_token_slot_and_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'space_group_parent_id' => 999,
        ]);

        $this->assertNull($space->space_group_token);
        $this->assertNull($space->space_group_slot);
        $this->assertNull($space->space_group_parent_id);
    }

    public function test_parent_role_keeps_legacy_token_and_clears_slot_and_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_parent_id' => 999,
        ]);

        $this->assertEquals('OS8', $space->space_group_token);
        $this->assertNull($space->space_group_slot);
        $this->assertNull($space->space_group_parent_id);
    }

    public function test_child_role_keeps_legacy_token_and_slot_and_allows_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
        ]);

        $this->assertEquals('OS8', $space->space_group_token);
        $this->assertEquals('14', $space->space_group_slot);
        $this->assertEquals($parent->id, $space->space_group_parent_id);
    }

    public function test_changing_from_child_to_parent_clears_slot_and_parent_id_but_keeps_token(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
        ]);

        $space->update(['space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT]);

        $this->assertEquals('OS8', $space->space_group_token);
        $this->assertNull($space->space_group_slot);
        $this->assertNull($space->space_group_parent_id);
    }

    public function test_changing_from_parent_to_none_clears_token_slot_and_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_parent_id' => 999,
        ]);

        $space->update(['space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE]);

        $this->assertNull($space->space_group_token);
        $this->assertNull($space->space_group_slot);
        $this->assertNull($space->space_group_parent_id);
    }

    public function test_unknown_role_normalizes_to_none_and_clears_token_slot_and_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => 'invalid_role',
            'space_group_parent_id' => 999,
        ]);

        $this->assertNull($space->space_group_token);
        $this->assertNull($space->space_group_slot);
        $this->assertNull($space->space_group_parent_id);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $space->space_group_role);
    }

    // === New space_group_parent_id tests ===

    public function test_child_can_belong_to_parent_through_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_slot' => '6',
            'space_group_parent_id' => $parent->id,
        ]);

        $this->assertEquals($parent->id, $child->space_group_parent_id);
        $this->assertTrue($child->spaceGroupParent()->exists());
    }

    public function test_parent_cannot_keep_parent_id_after_save(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_parent_id' => 999,
        ]);

        $this->assertNull($parent->space_group_parent_id);
    }

    public function test_changing_child_to_none_clears_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_slot' => '6',
            'space_group_parent_id' => $parent->id,
        ]);

        $child->update(['space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE]);

        $this->assertNull($child->space_group_parent_id);
        $this->assertNull($child->space_group_token);
        $this->assertNull($child->space_group_slot);
    }

    public function test_space_group_children_relation_works(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $child1 = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_slot' => '6',
            'space_group_parent_id' => $parent->id,
        ]);

        $child2 = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_slot' => '7',
            'space_group_parent_id' => $parent->id,
        ]);

        $children = $parent->spaceGroupChildren()->get();
        $childIds = $children->pluck('id')->sort()->values()->all();
        $expectedIds = [$child1->id, $child2->id];
        sort($expectedIds);

        $this->assertCount(2, $children);
        $this->assertEquals($expectedIds, $childIds);
    }

    public function test_child_space_inherits_effective_occupancy_from_parent_when_direct_tenant_is_missing(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'short_name' => 'Parent Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => $parentTenant->id,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
        ]);

        $this->assertNull($child->tenant_id);
        $this->assertSame('parent', $child->effectiveOccupancySource());
        $this->assertTrue($child->isEffectivelyOccupied());
        $this->assertSame($parentTenant->id, $child->effectiveTenantId());
        $this->assertSame($parentTenant->display_name, $child->effectiveTenantName());
        $this->assertTrue($child->effectiveOccupancySourceSpace()->is($parent));
    }

    public function test_child_space_prefers_direct_tenant_over_parent_effective_occupancy(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'short_name' => 'Parent Tenant',
            'is_active' => true,
        ]);
        $childTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Child Tenant LLC',
            'short_name' => 'Child Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'tenant_id' => $parentTenant->id,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'tenant_id' => $childTenant->id,
        ]);

        $this->assertSame($childTenant->id, $child->tenant_id);
        $this->assertSame('direct', $child->effectiveOccupancySource());
        $this->assertTrue($child->isEffectivelyOccupied());
        $this->assertSame($childTenant->id, $child->effectiveTenantId());
        $this->assertSame($childTenant->display_name, $child->effectiveTenantName());
        $this->assertTrue($child->effectiveOccupancySourceSpace()->is($child));
    }

    public function test_child_space_without_parent_tenant_stays_free(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
        ]);

        $this->assertNull($child->tenant_id);
        $this->assertSame('none', $child->effectiveOccupancySource());
        $this->assertFalse($child->isEffectivelyOccupied());
        $this->assertNull($child->effectiveTenantId());
        $this->assertNull($child->effectiveTenantName());
        $this->assertNull($child->effectiveOccupancySourceSpace());
    }
}
