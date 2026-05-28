<?php
# tests/Feature/MarketSpaceListTabsFilterTest.php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketSpaceListTabsFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! \Illuminate\Support\Facades\Schema::hasTable('market_spaces')) {
            $this->markTestSkipped('Table market_spaces does not exist.');
        }
    }

    protected function actingAsSuperAdmin(?int $marketId = null): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => $marketId,
        ]);
        $user->assignRole('super-admin');

        $this->actingAs($user, 'web');

        return $user;
    }

    public function test_vacant_tab_shows_only_effectively_free_spaces(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        // Regular vacant space (no tenant_id, not child)
        $vacantSpace = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'VACANT-1',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'none',
        ]);

        // Regular occupied space (has tenant_id, not child)
        $tenant = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 1']);
        $occupiedSpace = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OCCUPIED-1',
            'status' => 'vacant',
            'tenant_id' => $tenant->id,
            'space_group_role' => 'none',
        ]);

        // status=occupied + tenant_id=null = effectively vacant
        $occupiedButFree = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OCCUPIED-BUT-FREE',
            'status' => 'occupied',
            'tenant_id' => null,
            'space_group_role' => 'none',
        ]);

        // Child with parent that has no tenant (effectively vacant via parent)
        $parentWithoutTenant = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'PARENT-1',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'parent',
        ]);
        $childFree = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'CHILD-FREE',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'child',
            'space_group_parent_id' => $parentWithoutTenant->id,
        ]);

        // Child with parent that has tenant (occupied via parent)
        $tenant2 = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 2']);
        $parentWithTenant = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'PARENT-2',
            'status' => 'vacant',
            'tenant_id' => $tenant2->id,
            'space_group_role' => 'parent',
        ]);
        $childOccupied = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OCCUPIED-VIA-PARENT',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'child',
            'space_group_parent_id' => $parentWithTenant->id,
        ]);

        // Child with own tenant_id + parent_id, but parent has no tenant = effectively vacant
        $childWithOwnTenantButFreeParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'CHILD-OWN-TENANT-BUT-FREE',
            'status' => 'vacant',
            'tenant_id' => Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 3'])->id,
            'space_group_role' => 'child',
            'space_group_parent_id' => $parentWithoutTenant->id,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        $this->get(route('filament.admin.resources.market-spaces.index', ['tab' => 'vacant']))
            ->assertOk()
            ->assertSeeText($vacantSpace->number)
            ->assertSeeText($childFree->number)
            ->assertSeeText($occupiedButFree->number)
            ->assertSeeText($childWithOwnTenantButFreeParent->number)
            ->assertDontSeeText($occupiedSpace->number)
            ->assertDontSeeText($childOccupied->number);
    }

    public function test_occupied_tab_shows_only_effectively_occupied_spaces(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        // Regular vacant space (no tenant_id, not child)
        $vacantSpace = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'VACANT-1',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'none',
        ]);

        // status=occupied + tenant_id=null = effectively vacant
        $occupiedButFree = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OCCUPIED-BUT-FREE',
            'status' => 'occupied',
            'tenant_id' => null,
            'space_group_role' => 'none',
        ]);

        // Regular occupied space (has tenant_id, not child)
        $tenant = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 1']);
        $occupiedSpace = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OCCUPIED-1',
            'status' => 'vacant',
            'tenant_id' => $tenant->id,
            'space_group_role' => 'none',
        ]);

        // Child with parent that has no tenant (effectively vacant via parent)
        $parentWithoutTenant = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'PARENT-1',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'parent',
        ]);
        $childFree = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'CHILD-FREE',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'child',
            'space_group_parent_id' => $parentWithoutTenant->id,
        ]);

        // Child with parent that has tenant (occupied via parent)
        $tenant2 = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 2']);
        $parentWithTenant = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'PARENT-2',
            'status' => 'vacant',
            'tenant_id' => $tenant2->id,
            'space_group_role' => 'parent',
        ]);
        $childOccupied = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OCCUPIED-VIA-PARENT',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'child',
            'space_group_parent_id' => $parentWithTenant->id,
        ]);

        // Child with own tenant_id + parent_id, but parent has no tenant = effectively vacant
        $childWithOwnTenantButFreeParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'CHILD-OWN-TENANT-BUT-FREE',
            'status' => 'vacant',
            'tenant_id' => Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 3'])->id,
            'space_group_role' => 'child',
            'space_group_parent_id' => $parentWithoutTenant->id,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        $this->get(route('filament.admin.resources.market-spaces.index', ['tab' => 'occupied']))
            ->assertOk()
            ->assertSeeText($occupiedSpace->number)
            ->assertSeeText($childOccupied->number)
            ->assertDontSeeText($vacantSpace->number)
            ->assertDontSeeText($occupiedButFree->number)
            ->assertDontSeeText($childFree->number)
            ->assertDontSeeText($childWithOwnTenantButFreeParent->number);
    }

    public function test_vacant_tab_includes_child_without_parent_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        // Child without space_group_parent_id (treated as non-child)
        $childOrphan = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'CHILD-ORPHAN',
            'status' => 'vacant',
            'tenant_id' => null,
            'space_group_role' => 'child',
            'space_group_parent_id' => null,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        $this->get(route('filament.admin.resources.market-spaces.index', ['tab' => 'vacant']))
            ->assertOk()
            ->assertSeeText($childOrphan->number);
    }

    public function test_occupied_tab_includes_parent_with_tenant(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        // Parent group with tenant_id
        $tenant = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant 1']);
        $parentWithTenant = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'PARENT-WITH-TENANT',
            'status' => 'vacant',
            'tenant_id' => $tenant->id,
            'space_group_role' => 'parent',
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        $this->get(route('filament.admin.resources.market-spaces.index', ['tab' => 'occupied']))
            ->assertOk()
            ->assertSeeText($parentWithTenant->number);
    }
}
