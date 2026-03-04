<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');
        $hasLabelRu = Schema::hasColumn('roles', 'label_ru');

        $defaults = [
            'super-admin' => 'Супер-администратор',
            'market-owner' => 'Собственник рынка',
            'market-admin' => 'Администратор рынка',
            'market-manager' => 'Управляющий рынком',
            'market-operator' => 'Оператор рынка',
            'market-maintenance' => 'Техническая служба',
            'market-engineer' => 'Инженер',
            'market-it' => 'ИТ-специалист',
            'market-accountant' => 'Бухгалтер',
            'market-finance' => 'Финансовый отдел',
            'market-marketing' => 'Маркетинг',
            'market-advertising' => 'Реклама и медиа',
            'market-support' => 'Служба поддержки',
            'market-security' => 'Служба безопасности',
            'market-guard' => 'Охранник',
            'market-hr' => 'Кадровая служба',
        ];

        foreach ($defaults as $slug => $label) {
            /** @var Role $role */
            $role = Role::query()->firstOrCreate(
                ['name' => $slug, 'guard_name' => $guard],
                $hasLabelRu ? ['label_ru' => $label] : []
            );

            if ($hasLabelRu && blank((string) ($role->label_ru ?? ''))) {
                $role->label_ru = $label;
                $role->save();
            }
        }
    }

    public function down(): void
    {
        // No-op: seeded operational roles should not be removed on rollback.
    }
};

