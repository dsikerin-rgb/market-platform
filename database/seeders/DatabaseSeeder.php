<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Сбрасываем кеш Spatie, чтобы роли/права корректно подхватывались
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // 2) Guard берём из конфига (обычно "web")
        $guard = config('auth.defaults.guard', 'web');

        // 3) Базовые роли системы (создаём всегда, в любых окружениях)
        $roles = [
            'super-admin',
            'market-admin',
            'market-manager',
            'market-operator',
            'merchant',
        ];

        foreach ($roles as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => $guard,
            ]);
        }

        /**
         * 4) Опционально создаём супер-админа через .env,
         * чтобы НЕ хранить пароль в репозитории.
         *
         * В .env добавь (локально / на сервере перед сидом):
         * SEED_SUPER_ADMIN_EMAIL=super-admin@example.com
         * SEED_SUPER_ADMIN_PASSWORD=change_me
         * SEED_SUPER_ADMIN_NAME="Super Admin"
         */
        $adminEmail = env('SEED_SUPER_ADMIN_EMAIL');
        $adminPassword = env('SEED_SUPER_ADMIN_PASSWORD');

        if (filled($adminEmail) && filled($adminPassword)) {
            $adminName = env('SEED_SUPER_ADMIN_NAME', 'Super Admin');

            $admin = User::updateOrCreate(
                ['email' => $adminEmail],
                [
                    'name' => $adminName,
                    'password' => Hash::make($adminPassword),
                    'email_verified_at' => now(),
                    'market_id' => null,
                ]
            );

            $superAdminRole = Role::where('name', 'super-admin')
                ->where('guard_name', $guard)
                ->first();

            if ($superAdminRole) {
                $admin->syncRoles([$superAdminRole]);
            }
        }
    }
}
