<?php

declare(strict_types=1);

namespace App\Support;

class RolePermissionPresetCatalog
{
    /**
     * @return array<string, array{label: string, description: string, permissions: list<string>}>
     */
    public static function definitions(): array
    {
        return [
            'market_admin' => [
                'label' => 'Администратор рынка',
                'description' => 'Настройки рынка, сотрудники, маркетплейс и договорные привязки.',
                'permissions' => [
                    'market-settings.view',
                    'market-settings.update',
                    'marketplace.settings.view',
                    'marketplace.settings.update',
                    'marketplace.slides.viewAny',
                    'marketplace.slides.view',
                    'marketplace.slides.create',
                    'marketplace.slides.update',
                    'marketplace.slides.delete',
                    'staff.viewAny',
                    'staff.view',
                    'staff.create',
                    'staff.update',
                    'staff.delete',
                    'contracts.update',
                    'finance.1c.view',
                    'finance.accruals.view',
                ],
            ],
            'legal_admin' => [
                'label' => 'Юридическое и административное сопровождение',
                'description' => 'Работа с арендаторами, местами, договорами и финансовыми сводками без сотрудников и настроек рынка.',
                'permissions' => [
                    'contracts.update',
                    'finance.1c.view',
                    'finance.accruals.view',
                ],
            ],
            'marketplace_content' => [
                'label' => 'Маркетплейс и витрина',
                'description' => 'Настройка маркетплейса и управление промо-слайдами.',
                'permissions' => [
                    'marketplace.settings.view',
                    'marketplace.settings.update',
                    'marketplace.slides.viewAny',
                    'marketplace.slides.view',
                    'marketplace.slides.create',
                    'marketplace.slides.update',
                    'marketplace.slides.delete',
                ],
            ],
            'staff_management' => [
                'label' => 'Сотрудники',
                'description' => 'Просмотр, создание и изменение сотрудников рынка.',
                'permissions' => [
                    'staff.viewAny',
                    'staff.view',
                    'staff.create',
                    'staff.update',
                    'staff.delete',
                ],
            ],
            'finance_view' => [
                'label' => 'Финансы 1С',
                'description' => 'Просмотр финансовых сводок и начислений без изменения справочников.',
                'permissions' => [
                    'finance.1c.view',
                    'finance.accruals.view',
                ],
            ],
            'market_readonly' => [
                'label' => 'Просмотр рынка',
                'description' => 'Безопасный доступ только для просмотра своего рынка и его настроек.',
                'permissions' => [
                    'markets.view',
                    'market-settings.view',
                ],
            ],
            'clear' => [
                'label' => 'Очистить дополнительные права',
                'description' => 'Снять все явно выбранные права. Код роли продолжит давать встроенный доступ.',
                'permissions' => [],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::definitions() as $key => $definition) {
            $options[$key] = $definition['label'];
        }

        return $options;
    }

    public static function description(string $key): ?string
    {
        return self::definitions()[$key]['description'] ?? null;
    }

    /**
     * @param array<string, int> $permissionIdsByName
     * @return list<int>
     */
    public static function permissionIdsForPreset(string $key, array $permissionIdsByName): array
    {
        $permissions = self::definitions()[$key]['permissions'] ?? [];
        $ids = [];

        foreach ($permissions as $permission) {
            if (isset($permissionIdsByName[$permission])) {
                $ids[] = $permissionIdsByName[$permission];
            }
        }

        return array_values(array_unique($ids));
    }
}
