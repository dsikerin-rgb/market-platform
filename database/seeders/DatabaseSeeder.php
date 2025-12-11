<?php

namespace Database\Seeders;

use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
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

        // Тестовый рынок и маркет-админ
        $market = Market::firstOrCreate(
            ['code' => 'test-market'],
            [
                'name' => 'Тестовый рынок',
                'slug' => 'test-market',
                'address' => 'г. Москва, ул. Пример, д. 1',
                'timezone' => 'Europe/Moscow',
                'is_active' => true,
            ],
        );

        $marketAdmin = User::firstOrCreate(
            ['email' => 'market-admin@example.com'],
            [
                'name' => 'Маркет-админ',
                'password' => Hash::make('market12345'),
                'market_id' => $market->id,
            ],
        );

        if (! $marketAdmin->hasRole($marketAdminRole)) {
            $marketAdmin->assignRole($marketAdminRole);
        }

        // Простейшие данные для проверки фильтрации внутри рынка
        $location = MarketLocation::firstOrCreate(
            [
                'market_id' => $market->id,
                'code' => 'loc-1',
            ],
            [
                'name' => 'Основная локация',
                'type' => 'zone',
                'is_active' => true,
            ],
        );

        MarketSpace::firstOrCreate(
            [
                'market_id' => $market->id,
                'number' => 'A-101',
            ],
            [
                'location_id' => $location->id,
                'code' => 'space-101',
                'area_sqm' => 10.5,
                'type' => 'retail',
                'status' => 'free',
                'is_active' => true,
            ],
        );

        // При необходимости потом добавим фабрики тестовых пользователей
        // User::factory(10)->create();
    }
}
