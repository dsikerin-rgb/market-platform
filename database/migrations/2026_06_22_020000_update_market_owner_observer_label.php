<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'label_ru')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard', 'web');
        $role = Role::findOrCreate('market-owner', $guard);
        $role->forceFill(['label_ru' => 'Обозреватель'])->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('roles', 'label_ru')) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = (string) config('auth.defaults.guard', 'web');
        $role = Role::findOrCreate('market-owner', $guard);
        $role->forceFill(['label_ru' => 'Обозреватель рынка'])->save();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
