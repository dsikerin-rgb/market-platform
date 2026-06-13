<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('roles', 'label_ru')) {
            return;
        }

        $guard = (string) config('auth.defaults.guard', 'web');

        /** @var Role|null $role */
        $role = Role::query()
            ->where('name', 'market-admin')
            ->where('guard_name', $guard)
            ->first();

        if (! $role) {
            return;
        }

        $currentLabel = trim((string) ($role->label_ru ?? ''));
        $standardLabels = [
            '',
            'Администратор рынка',
            'Наёмный директор',
            'Директор рынка',
        ];

        if (! in_array($currentLabel, $standardLabels, true)) {
            return;
        }

        $role->label_ru = 'Директор';
        $role->save();
    }

    public function down(): void
    {
        // No-op: user-facing role label changes should not be reverted automatically.
    }
};
