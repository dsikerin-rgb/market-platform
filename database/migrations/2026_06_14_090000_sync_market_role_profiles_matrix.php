<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard', 'web');
        $hasLabelRu = Schema::hasColumn('roles', 'label_ru');

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
            'contracts.update',
            'finance.1c.view',
            'finance.accruals.view',
            'staff.viewAny',
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        $roleLabels = [
            'market-owner' => 'Собственник',
            'market-owner-director' => 'Собственник-директор',
            'market-admin' => 'Наёмный директор',
            'market-manager' => 'Операционный управляющий',
            'market-legal-admin' => 'Юридическое и административное сопровождение',
            'market-accountant' => 'Бухгалтер',
            'market-finance' => 'Финансы',
            'market-advertising' => 'Реклама и медиа',
        ];

        $previousDefaultLabels = [
            'market-owner' => 'Собственник рынка',
            'market-admin' => 'Администратор рынка',
            'market-manager' => 'Управляющий рынком',
            'market-finance' => 'Финансовый отдел',
        ];

        foreach ($roleLabels as $roleName => $label) {
            /** @var Role $role */
            $role = Role::findOrCreate($roleName, $guard);

            if (
                $hasLabelRu
                && (
                    blank((string) ($role->label_ru ?? ''))
                    || (string) ($role->label_ru ?? '') === ($previousDefaultLabels[$roleName] ?? null)
                )
            ) {
                $role->label_ru = $label;
                $role->save();
            }
        }

        $marketplaceViewPermissions = [
            'marketplace.settings.view',
            'marketplace.slides.viewAny',
            'marketplace.slides.view',
        ];

        $marketplaceManagePermissions = [
            'marketplace.settings.view',
            'marketplace.settings.update',
            'marketplace.slides.viewAny',
            'marketplace.slides.view',
            'marketplace.slides.create',
            'marketplace.slides.update',
            'marketplace.slides.delete',
        ];

        $staffPermissions = [
            'staff.viewAny',
            'staff.view',
            'staff.create',
            'staff.update',
            'staff.delete',
        ];

        $financePermissions = [
            'finance.1c.view',
            'finance.accruals.view',
        ];

        $rolePermissions = [
            'market-owner' => [
                ...$marketplaceViewPermissions,
                ...$financePermissions,
            ],
            'market-owner-director' => [
                'market-settings.view',
                'market-settings.update',
                ...$marketplaceManagePermissions,
                ...$staffPermissions,
                'contracts.update',
                ...$financePermissions,
            ],
            'market-admin' => [
                'market-settings.view',
                'market-settings.update',
                ...$marketplaceManagePermissions,
                ...$staffPermissions,
                'contracts.update',
                ...$financePermissions,
            ],
            'market-manager' => [
                ...$financePermissions,
            ],
            'market-legal-admin' => [
                'contracts.update',
                ...$financePermissions,
            ],
            'market-accountant' => [
                ...$financePermissions,
            ],
            'market-finance' => [
                ...$financePermissions,
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
        // No-op: operational roles and additive permissions should not be removed on rollback.
    }
};
