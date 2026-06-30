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
            'marketplace.storefronts.view',
            'marketplace.storefronts.update_content',
            'marketplace.products.viewAny',
            'marketplace.products.view',
            'marketplace.products.update_content',
            'marketplace.products.update_media',
            'marketplace.products.publish',
            'marketplace.orders.view',
            'marketplace.orders.update_status',
            'marketplace.chats.view',
            'marketplace.chats.reply',
            'tenants.marketplace-contacts.view',

            'market-holidays.viewAny',
            'market-holidays.view',
            'market-holidays.create',
            'market-holidays.update',
            'market-holidays.delete',

            'markets.viewAny',
            'markets.view',
            'markets.create',
            'markets.update',
            'markets.delete',

            'contracts.update',

            'finance.1c.view',
            'finance.accruals.view',

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

            'staff.viewAny',
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',
        ];

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, $guard);
        }

        // market-admin: только настройки рынка + сотрудники (никаких markets.*)
        $marketAdminPermissions = [
            'market-settings.view',
            'market-settings.update',
            'marketplace.settings.view',
            'marketplace.settings.update',
            'marketplace.slides.viewAny',
            'marketplace.slides.view',
            'marketplace.slides.create',
            'marketplace.slides.update',
            'marketplace.slides.delete',
            'marketplace.storefronts.view',
            'marketplace.storefronts.update_content',
            'marketplace.products.viewAny',
            'marketplace.products.view',
            'marketplace.products.update_content',
            'marketplace.products.update_media',
            'marketplace.products.publish',
            'marketplace.orders.view',
            'marketplace.orders.update_status',
            'marketplace.chats.view',
            'marketplace.chats.reply',
            'tenants.marketplace-contacts.view',

            'market-holidays.viewAny',
            'market-holidays.view',
            'market-holidays.create',
            'market-holidays.update',
            'market-holidays.delete',

            'staff.viewAny',
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',

            'contracts.update',
            'finance.1c.view',
            'finance.accruals.view',

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

        // 2) Матрица ролей -> permissions (явно, без “магии”)
        $roles = [
            // super-admin: всё
            'super-admin' => $permissions,

            'market-admin' => $marketAdminPermissions,
            'demo-market-admin' => $marketAdminPermissions,

            // Остальные роли получают только профильные права, а широкий доступ остается у директоров.
            'market-owner-director' => [
                'market-settings.view',
                'market-settings.update',
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
                'marketplace.storefronts.view',
                'marketplace.storefronts.update_content',
                'marketplace.products.viewAny',
                'marketplace.products.view',
                'marketplace.products.update_content',
                'marketplace.products.update_media',
                'marketplace.products.publish',
                'marketplace.orders.view',
                'marketplace.orders.update_status',
                'marketplace.chats.view',
                'marketplace.chats.reply',
                'tenants.marketplace-contacts.view',

                'market-holidays.viewAny',
                'market-holidays.view',
                'market-holidays.create',
                'market-holidays.update',
                'market-holidays.delete',

                'staff.viewAny',
                'staff.view',
                'staff.create',
                'staff.update',
                'staff.delete',

                'contracts.update',
                'finance.1c.view',
                'finance.accruals.view',

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
            ],
            'market-manager' => [
                'finance.1c.view',
                'finance.accruals.view',
            ],
            'market-operator' => [],
            'market-owner' => [
                'markets.view',
                'market-settings.view',
                'marketplace.settings.view',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'staff.viewAny',
                'staff.view',
                'finance.1c.view',
                'finance.accruals.view',
                'market-holidays.viewAny',
                'market-holidays.view',
                'market-holidays.create',
                'market-locations.viewAny',
                'market-locations.view',
                'market-location-types.viewAny',
                'market-location-types.view',
                'reports.viewAny',
                'reports.view',
            ],
            'market-legal-admin' => [
                'contracts.update',
                'finance.1c.view',
                'finance.accruals.view',
                'market-locations.viewAny',
                'market-locations.view',
                'market-locations.create',
                'market-locations.update',
                'market-location-types.viewAny',
                'market-location-types.view',
                'market-location-types.create',
                'market-location-types.update',
                'reports.viewAny',
                'reports.view',
            ],
            'market-accountant' => [
                'finance.1c.view',
                'finance.accruals.view',
                'reports.viewAny',
                'reports.view',
            ],
            'market-finance' => [
                'finance.1c.view',
                'finance.accruals.view',
                'reports.viewAny',
                'reports.view',
            ],
            'market-maintenance' => [],
            'market-engineer' => [],
            'market-it' => [],
            'market-security' => [],
            'market-guard' => [],
            'market-marketing' => [
                'markets.view',
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
                'marketplace.storefronts.view',
                'marketplace.storefronts.update_content',
                'marketplace.products.viewAny',
                'marketplace.products.view',
                'marketplace.products.update_content',
                'marketplace.products.update_media',
                'marketplace.products.publish',
                'marketplace.orders.view',
                'marketplace.orders.update_status',
                'marketplace.chats.view',
                'marketplace.chats.reply',
                'tenants.marketplace-contacts.view',
                'market-holidays.viewAny',
                'market-holidays.view',
                'market-holidays.create',
                'market-holidays.update',
                'staff.viewAny',
                'staff.view',
            ],
            'market-advertising' => [
                'markets.view',
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
                'marketplace.storefronts.view',
                'marketplace.storefronts.update_content',
                'marketplace.products.viewAny',
                'marketplace.products.view',
                'marketplace.products.update_content',
                'marketplace.products.update_media',
                'marketplace.products.publish',
                'marketplace.orders.view',
                'marketplace.orders.update_status',
                'marketplace.chats.view',
                'marketplace.chats.reply',
                'tenants.marketplace-contacts.view',
                'market-holidays.viewAny',
                'market-holidays.view',
                'market-holidays.create',
                'market-holidays.update',
                'staff.viewAny',
                'staff.view',
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
