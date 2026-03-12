<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Support\SystemAgentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StaffResourceScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_resource_shows_only_internal_market_staff(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant User Owner',
            'is_active' => true,
        ]);

        $marketAdminRole = Role::findOrCreate('market-admin', 'web');
        $staffViewAny = Permission::findOrCreate('staff.viewAny', 'web');
        $marketAdminRole->givePermissionTo($staffViewAny);

        $actor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'staff-admin@example.test',
        ]);
        $actor->assignRole($marketAdminRole);

        $internalStaff = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'internal-staff@example.test',
        ]);

        $tenantEmployee = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'email' => 'tenant-employee@example.test',
        ]);

        $merchantRole = Role::findOrCreate('merchant', 'web');
        $merchantUser = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'tenant-merchant@cabinet.local',
        ]);
        $merchantUser->assignRole($merchantRole);

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $visibleStaffIds = StaffResource::getEloquentQuery()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $internalStaff->id, $visibleStaffIds);
        $this->assertContains((int) $actor->id, $visibleStaffIds);
        $this->assertNotContains((int) $tenantEmployee->id, $visibleStaffIds);
        $this->assertNotContains((int) $merchantUser->id, $visibleStaffIds);
    }

    public function test_system_agent_is_hidden_for_market_admin_but_visible_for_super_admin(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $marketAdminRole = Role::findOrCreate('market-admin', 'web');
        $superAdminRole = Role::findOrCreate('super-admin', 'web');
        $staffViewAny = Permission::findOrCreate('staff.viewAny', 'web');
        $staffUpdate = Permission::findOrCreate('staff.update', 'web');

        $marketAdminRole->givePermissionTo([$staffViewAny, $staffUpdate]);
        $superAdminRole->givePermissionTo([$staffViewAny, $staffUpdate]);

        $marketAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-staff@example.test',
        ]);
        $marketAdmin->assignRole($marketAdminRole);

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-staff@example.test',
        ]);
        $superAdmin->assignRole($superAdminRole);

        $systemAgent = User::factory()->create([
            'name' => 'System Agent',
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'system+market' . $market->id . '@' . SystemAgentService::EMAIL_DOMAIN,
        ]);

        $this->actingAs($marketAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $marketAdminVisibleStaffIds = StaffResource::getEloquentQuery()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertNotContains((int) $systemAgent->id, $marketAdminVisibleStaffIds);
        $this->assertFalse(StaffResource::canEdit($systemAgent));

        $this->actingAs($superAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $superAdminVisibleStaffIds = StaffResource::getEloquentQuery()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $systemAgent->id, $superAdminVisibleStaffIds);
        $this->assertTrue(StaffResource::canEdit($systemAgent));
    }

    public function test_market_admin_can_delete_regular_staff_in_own_market_by_role(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $marketAdminRole = Role::findOrCreate('market-admin', 'web');
        $staffViewAny = Permission::findOrCreate('staff.viewAny', 'web');
        $marketAdminRole->givePermissionTo($staffViewAny);

        $marketAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-delete@example.test',
        ]);
        $marketAdmin->assignRole($marketAdminRole);

        $staff = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'staff-delete@example.test',
        ]);

        $this->actingAs($marketAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertTrue(StaffResource::canDelete($staff));
    }

    public function test_market_admin_cannot_delete_merchant_user_from_staff_resource(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $marketAdminRole = Role::findOrCreate('market-admin', 'web');
        $merchantRole = Role::findOrCreate('merchant', 'web');
        $staffViewAny = Permission::findOrCreate('staff.viewAny', 'web');
        $marketAdminRole->givePermissionTo($staffViewAny);

        $marketAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-merchant-delete@example.test',
        ]);
        $marketAdmin->assignRole($marketAdminRole);

        $merchant = User::factory()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => null,
            'email' => 'merchant-hidden@cabinet.local',
        ]);
        $merchant->assignRole($merchantRole);

        $this->actingAs($marketAdmin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertFalse(StaffResource::canDelete($merchant));
    }
}
