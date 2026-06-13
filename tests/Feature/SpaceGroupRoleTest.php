<?php
# tests/Feature/SpaceGroupRoleTest.php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use App\Services\MarketSpaces\SpaceGroupManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
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

    public function test_child_space_inherits_parent_effective_occupancy_even_when_direct_tenant_exists(): void
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
        $this->assertSame('parent', $child->effectiveOccupancySource());
        $this->assertTrue($child->isEffectivelyOccupied());
        $this->assertSame($parentTenant->id, $child->effectiveTenantId());
        $this->assertSame($parentTenant->display_name, $child->effectiveTenantName());
        $this->assertTrue($child->effectiveOccupancySourceSpace()->is($parent));
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

    public function test_regrouping_child_does_not_rename_parent_numbers(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $oldParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС20/1 рыба',
            'display_name' => 'ОС20/1 рыба',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'ОС20',
            'is_active' => true,
        ]);

        $newParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС20/3 ск',
            'display_name' => 'ОС20/3 ск',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'ОС20',
            'is_active' => true,
        ]);

        $child17 = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС20 17',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $oldParent->id,
            'space_group_slot' => '17',
            'space_group_token' => 'ОС20',
            'is_active' => true,
        ]);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС20 18',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $oldParent->id,
            'space_group_slot' => '18',
            'space_group_token' => 'ОС20',
            'is_active' => true,
        ]);

        foreach (['14', '15', '16'] as $slot) {
            MarketSpace::create([
                'market_id' => $market->id,
                'number' => 'ОС20 ' . $slot,
                'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
                'space_group_parent_id' => $newParent->id,
                'space_group_slot' => $slot,
                'space_group_token' => 'ОС20',
                'is_active' => true,
            ]);
        }

        $result = app(SpaceGroupManager::class)->regroupChild($child17, $newParent, '17');

        $child17->refresh();
        $oldParent->refresh();
        $newParent->refresh();

        $this->assertSame($newParent->id, $child17->space_group_parent_id);
        $this->assertSame('17', $child17->space_group_slot);
        $this->assertSame('ОС20', $child17->space_group_token);
        // Parent numbers should remain unchanged
        $this->assertSame('ОС20/1 рыба', $oldParent->number);
        $this->assertSame('ОС20/3 ск', $newParent->number);
        // Display names may be updated automatically
        $this->assertSame('ОС20 18', $oldParent->display_name);
        $this->assertSame('ОС20 14, 15, 16, 17', $newParent->display_name);
        // No renamed parents since numbers are not changed
        $this->assertCount(0, $result['renamed_parents']);
    }

    public function test_regrouping_child_rejects_duplicate_slot_in_target_group(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $oldParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС7 17, 18',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'ОС7',
            'is_active' => true,
        ]);

        $newParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС7 14, 15, 16',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'ОС7',
            'is_active' => true,
        ]);

        $child17 = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС7 17',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $oldParent->id,
            'space_group_slot' => '17',
            'space_group_token' => 'ОС7',
            'is_active' => true,
        ]);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС7 14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $newParent->id,
            'space_group_slot' => '17',
            'space_group_token' => 'ОС7',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(SpaceGroupManager::class)->regroupChild($child17, $newParent, '17');
    }

    public function test_adding_child_to_group_preserves_parent_number(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС20/1 рыба',
            'display_name' => 'ОС20/1 рыба',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'ОС20',
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС20 227',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $result = app(SpaceGroupManager::class)->addToGroup($child, $parent, '227');

        $child->refresh();
        $parent->refresh();

        $this->assertSame($parent->id, $child->space_group_parent_id);
        $this->assertSame('227', $child->space_group_slot);
        $this->assertSame('ОС20', $child->space_group_token);
        // Parent number should remain unchanged
        $this->assertSame('ОС20/1 рыба', $parent->number);
        // Display name may be updated automatically
        $this->assertSame('ОС20 227', $parent->display_name);
        // No renamed parents since number is not changed
        $this->assertCount(0, $result['renamed_parents']);
    }

    public function test_regrouping_child_rejects_current_parent_as_target_group(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'ОС7',
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ОС7 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '8',
            'space_group_token' => 'ОС7',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        app(SpaceGroupManager::class)->regroupChild($child, $parent, '8');
    }

    public function test_maintenance_space_cannot_be_saved_as_grouped(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $this->expectException(ValidationException::class);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'S-1',
            'status' => 'maintenance',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);
    }

    public function test_shared_space_cannot_be_added_to_group(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Shared Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'G-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $shared = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'SH-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        MarketSpaceTenantBinding::create([
            'market_id' => $market->id,
            'market_space_id' => $shared->id,
            'tenant_id' => $tenant->id,
            'started_at' => now(),
            'binding_type' => 'shared_use',
            'confidence' => 'manual',
            'source' => 'test',
        ]);

        $this->expectException(ValidationException::class);

        app(SpaceGroupManager::class)->addToGroup($shared, $parent, '1');
    }

    public function test_shared_use_source_space_cannot_be_added_to_group(): void
    {
        $market = Market::create(['name' => 'Test Market']);
        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Shared Tenant',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'G-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $source = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'SRC-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'SH-1',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        MarketSpaceTenantBinding::create([
            'market_id' => $market->id,
            'market_space_id' => $canonical->id,
            'tenant_id' => $tenant->id,
            'started_at' => now(),
            'binding_type' => 'shared_use',
            'confidence' => 'manual',
            'source' => 'test',
            'meta' => ['source_space_ids' => [$source->id]],
        ]);

        $this->expectException(ValidationException::class);

        app(SpaceGroupManager::class)->addToGroup($source, $parent, '1');
    }
}
