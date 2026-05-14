<?php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MarketSpaceDeactivatePrecheckTest extends TestCase
{
    use DatabaseTransactions;

    public function test_child_with_inherited_tenant_from_parent_blocks_deactivation(): void
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
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $this->assertNull($child->tenant_id);
        $this->assertTrue($child->isEffectivelyOccupied());
        $this->assertSame('parent', $child->effectiveOccupancySource());
        $this->assertSame($parentTenant->id, $child->effectiveTenantId());
    }

    public function test_child_without_parent_tenant_can_be_deactivated(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        
        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $this->assertNull($child->tenant_id);
        $this->assertFalse($child->isEffectivelyOccupied());
        $this->assertSame('none', $child->effectiveOccupancySource());
        $this->assertNull($child->effectiveTenantId());
    }

    public function test_direct_tenant_takes_precedence_over_parent_inheritance(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $parentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Parent Tenant LLC',
            'is_active' => true,
        ]);
        $childTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Child Tenant LLC',
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
        $this->assertSame($childTenant->id, $child->effectiveTenantId());
    }
}