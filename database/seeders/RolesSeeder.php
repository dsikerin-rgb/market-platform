<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = config('auth.defaults.guard', 'web');

        // Базовые роли системы
        Role::findOrCreate('super-admin', $guard);
        Role::findOrCreate('market-admin', $guard);

        // Если есть другие роли — добавляй тут же
        // Role::findOrCreate('market-staff', $guard);
    }
}
