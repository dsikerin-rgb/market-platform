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
            'marketplace.settings.view',
            'marketplace.settings.update',
            'marketplace.slides.viewAny',
            'marketplace.slides.view',
            'marketplace.slides.create',
            'marketplace.slides.update',
            'marketplace.slides.delete',

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
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',

                'staff.viewAny',
                'staff.view',
                'staff.create',
                'staff.update',
                'staff.delete',
            ],

            // Остальные пока пустые/минимальные — чтобы не выдать лишнего автоматически
            'market-manager' => [],
            'market-operator' => [],
            'market-owner' => [
                'marketplace.settings.view',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
            ],
            'market-accountant' => [],
            'market-finance' => [],
            'market-maintenance' => [],
            'market-engineer' => [],
            'market-it' => [],
            'market-security' => [],
            'market-guard' => [],
            'market-marketing' => [
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
            ],
            'market-advertising' => [
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
            ],
            'market-support' => [],
            'market-hr' => [],
            'merchant' => [],
            'merchant-user' => [],
        ];

        foreach ($roles as $roleName => $rolePerms) {
            $role = Role::findOrCreate($roleName, $guard);
            $role->syncPermissions($rolePerms);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
