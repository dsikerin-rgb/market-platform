<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard', 'web');

        if (! Schema::hasColumn('market_holidays', 'author_user_id')) {
            Schema::table('market_holidays', function (Blueprint $table): void {
                $table->foreignId('author_user_id')
                    ->nullable()
                    ->after('market_id')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }

        foreach ([
            'markets.view',
            'market-settings.view',
            'marketplace.settings.view',
            'marketplace.slides.viewAny',
            'marketplace.slides.view',
            'staff.viewAny',
            'staff.view',
            'finance.1c.view',
            'finance.accruals.view',
        ] as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        $role = Role::findOrCreate('market-owner', $guard);
        $role->givePermissionTo([
            'markets.view',
            'market-settings.view',
            'marketplace.settings.view',
            'marketplace.slides.viewAny',
            'marketplace.slides.view',
            'staff.viewAny',
            'staff.view',
            'finance.1c.view',
            'finance.accruals.view',
        ]);

        if (Schema::hasColumn('roles', 'label_ru')) {
            $role->forceFill(['label_ru' => 'Обозреватель'])->save();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasColumn('market_holidays', 'author_user_id')) {
            Schema::table('market_holidays', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('author_user_id');
            });
        }
    }
};
