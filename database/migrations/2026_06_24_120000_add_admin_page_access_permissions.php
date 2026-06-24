<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard', 'web');

        $permissions = [
            'markets.view',
            'market-holidays.viewAny',
            'market-holidays.view',
            'market-holidays.create',
            'market-holidays.update',
            'market-holidays.delete',
            'market-locations.viewAny',
            'market-locations.view',
            'market-locations.create',
            'market-locations.update',
            'market-locations.delete',
            'market-location-types.viewAny',
            'market-location-types.view',
            'market-location-types.create',
            'market-location-types.update',
            'market-location-types.delete',
            'reports.viewAny',
            'reports.view',
            'reports.create',
            'reports.update',
            'reports.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        $eventViewCreatePermissions = [
            'market-holidays.viewAny',
            'market-holidays.view',
            'market-holidays.create',
        ];

        $eventManagePermissions = [
            ...$eventViewCreatePermissions,
            'market-holidays.update',
        ];

        $eventFullPermissions = [
            ...$eventManagePermissions,
            'market-holidays.delete',
        ];

        $locationViewPermissions = [
            'market-locations.viewAny',
            'market-locations.view',
            'market-location-types.viewAny',
            'market-location-types.view',
        ];

        $locationManagePermissions = [
            ...$locationViewPermissions,
            'market-locations.create',
            'market-locations.update',
            'market-location-types.create',
            'market-location-types.update',
        ];

        $locationFullPermissions = [
            ...$locationManagePermissions,
            'market-locations.delete',
            'market-location-types.delete',
        ];

        $reportViewPermissions = [
            'reports.viewAny',
            'reports.view',
        ];

        $reportFullPermissions = [
            ...$reportViewPermissions,
            'reports.create',
            'reports.update',
            'reports.delete',
        ];

        $rolePermissions = [
            'super-admin' => $permissions,
            'market-admin' => [
                ...$eventFullPermissions,
                ...$locationFullPermissions,
                ...$reportFullPermissions,
            ],
            'market-owner-director' => [
                ...$eventFullPermissions,
                ...$locationFullPermissions,
                ...$reportFullPermissions,
            ],
            'market-owner' => [
                ...$eventViewCreatePermissions,
                ...$locationViewPermissions,
                ...$reportViewPermissions,
            ],
            'market-legal-admin' => [
                ...$locationManagePermissions,
                ...$reportViewPermissions,
            ],
            'market-accountant' => $reportViewPermissions,
            'market-finance' => $reportViewPermissions,
            'market-marketing' => [
                'markets.view',
                ...$eventManagePermissions,
            ],
            'market-advertising' => [
                'markets.view',
                ...$eventManagePermissions,
            ],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $role = Role::findOrCreate($roleName, $guard);
            $role->givePermissionTo(array_values(array_unique($permissionNames)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Additive access migration: do not remove operational permissions on rollback.
    }
};
