<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Базовые роли системы
        $superAdminRole = Role::firstOrCreate(['name' => 'super-admin']);
        $marketAdminRole = Role::firstOrCreate(['name' => 'market-admin']);
        $merchantRole = Role::firstOrCreate(['name' => 'merchant']);

        // Первый супер-админ для входа в панель
        $admin = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin12345'),
            ],
        );

        if (! $admin->hasRole($superAdminRole)) {
            $admin->assignRole($superAdminRole);
        }

        // При необходимости потом добавим фабрики тестовых пользователей
        // User::factory(10)->create();
    }
}
