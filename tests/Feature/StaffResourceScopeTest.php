<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
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

        $this->actingAs($actor);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $visibleStaffIds = StaffResource::getEloquentQuery()
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $this->assertContains((int) $internalStaff->id, $visibleStaffIds);
        $this->assertContains((int) $actor->id, $visibleStaffIds);
        $this->assertNotContains((int) $tenantEmployee->id, $visibleStaffIds);
    }
}
