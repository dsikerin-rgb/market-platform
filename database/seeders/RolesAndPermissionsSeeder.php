<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        // 1) Все permissions, которые существуют в проекте сейчас
        $permissions = [
            'market-settings.view',
            'market-settings.update',

            'markets.viewAny',
            'markets.view',
            'markets.create',
            'markets.update',
            'markets.delete',

            'staff.viewAny',
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, $guard);
        }

        // 2) Матрица ролей -> permissions (явно, без “магии”)
        $roles = [
            // super-admin: всё
            'super-admin' => $permissions,

            // market-admin: только настройки рынка + сотрудники (никаких markets.*)
            'market-admin' => [
                'market-settings.view',
                'market-settings.update',

                'staff.viewAny',
                'staff.view',
                'staff.create',
                'staff.update',
                'staff.delete',
            ],

            // Остальные пока пустые/минимальные — чтобы не выдать лишнего автоматически
            'market-manager' => [],
            'market-operator' => [],
            'market-accountant' => [],
            'merchant' => [],
        ];

        foreach ($roles as $roleName => $rolePerms) {
            $role = Role::findOrCreate($roleName, $guard);
            $role->syncPermissions($rolePerms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
