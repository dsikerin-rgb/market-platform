<?php

declare(strict_types=1);

namespace App\Support;

class PermissionDisplayCatalog
{
    /**
     * @var array<string, array{label: string, group: string, description?: string}>
     */
    private const MAP = [
        'market-settings.view' => [
            'label' => 'Просмотр настроек рынка',
            'group' => 'Настройки рынка',
        ],
        'market-settings.update' => [
            'label' => 'Изменение настроек рынка',
            'group' => 'Настройки рынка',
        ],
        'marketplace.settings.view' => [
            'label' => 'Просмотр настроек маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'marketplace.settings.update' => [
            'label' => 'Изменение настроек маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'marketplace.slides.viewAny' => [
            'label' => 'Просмотр списка слайдов маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'marketplace.slides.view' => [
            'label' => 'Просмотр слайдов маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'marketplace.slides.create' => [
            'label' => 'Создание слайдов маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'marketplace.slides.update' => [
            'label' => 'Изменение слайдов маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'marketplace.slides.delete' => [
            'label' => 'Удаление слайдов маркетплейса',
            'group' => 'Маркетплейс',
        ],
        'markets.viewAny' => [
            'label' => 'Просмотр списка рынков',
            'group' => 'Рынок',
        ],
        'markets.view' => [
            'label' => 'Просмотр рынка',
            'group' => 'Рынок',
        ],
        'markets.create' => [
            'label' => 'Создание рынка',
            'group' => 'Рынок',
        ],
        'markets.update' => [
            'label' => 'Изменение рынка',
            'group' => 'Рынок',
            'description' => 'Это право может открывать расширенное управление рынком. Используйте осторожно.',
        ],
        'markets.delete' => [
            'label' => 'Удаление рынка',
            'group' => 'Рынок',
        ],
        'contracts.update' => [
            'label' => 'Изменение привязок договоров',
            'group' => 'Договоры',
        ],
        'finance.1c.view' => [
            'label' => 'Просмотр финансов 1С',
            'group' => 'Финансы 1С',
        ],
        'finance.accruals.view' => [
            'label' => 'Просмотр начислений',
            'group' => 'Финансы 1С',
        ],
        'staff.viewAny' => [
            'label' => 'Просмотр списка сотрудников',
            'group' => 'Сотрудники',
        ],
        'staff.view' => [
            'label' => 'Просмотр сотрудника',
            'group' => 'Сотрудники',
        ],
        'staff.create' => [
            'label' => 'Создание сотрудника',
            'group' => 'Сотрудники',
        ],
        'staff.update' => [
            'label' => 'Изменение сотрудника',
            'group' => 'Сотрудники',
        ],
        'staff.delete' => [
            'label' => 'Удаление сотрудника',
            'group' => 'Сотрудники',
        ],
    ];

    /**
     * @var list<string>
     */
    private const GROUP_ORDER = [
        'Рынок',
        'Настройки рынка',
        'Места',
        'Арендаторы',
        'Финансы 1С',
        'Договоры',
        'Маркетплейс',
        'Сотрудники',
        'Отчеты',
        'Система',
        'Прочее',
    ];

    /**
     * @var array<string, string>
     */
    private const RESOURCE_LABELS = [
        'market-settings' => 'настройки рынка',
        'marketplace' => 'маркетплейс',
        'markets' => 'рынки',
        'market-spaces' => 'торговые места',
        'market_space_types' => 'типы мест',
        'market-space-types' => 'типы мест',
        'tenants' => 'арендаторы',
        'tenant-accruals' => 'начисления',
        'tenant-payments' => 'оплаты',
        'contracts' => 'договоры',
        'finance' => 'финансы 1С',
        'reports' => 'отчеты',
        'staff' => 'сотрудники',
        'roles' => 'роли',
        'permissions' => 'права доступа',
        'tasks' => 'задачи',
        'tickets' => 'обращения',
    ];

    /**
     * @var array<string, string>
     */
    private const ACTION_LABELS = [
        'viewAny' => 'Просмотр списка',
        'view' => 'Просмотр',
        'create' => 'Создание',
        'update' => 'Изменение',
        'delete' => 'Удаление',
        'restore' => 'Восстановление',
        'forceDelete' => 'Полное удаление',
        'manage' => 'Управление',
        'export' => 'Экспорт',
        'import' => 'Импорт',
    ];

    public static function label(string $permission): string
    {
        return self::MAP[$permission]['label'] ?? self::humanizePermission($permission);
    }

    public static function group(string $permission): string
    {
        if (isset(self::MAP[$permission])) {
            return self::MAP[$permission]['group'];
        }

        $prefix = explode('.', $permission)[0] ?? $permission;

        return match ($prefix) {
            'marketplace' => 'Маркетплейс',
            'contracts' => 'Договоры',
            'finance', 'tenant-accruals', 'tenant-payments' => 'Финансы 1С',
            'market-settings', 'markets' => 'Рынок',
            'market-spaces', 'market_space_types', 'market-space-types' => 'Места',
            'tenants' => 'Арендаторы',
            'reports' => 'Отчеты',
            'staff' => 'Сотрудники',
            'roles', 'permissions' => 'Система',
            default => 'Прочее',
        };
    }

    public static function description(string $permission): ?string
    {
        return self::MAP[$permission]['description'] ?? null;
    }

    /**
     * @param array<int, string> $permissionsById
     * @return array<int, string>
     */
    public static function options(array $permissionsById): array
    {
        $rows = [];

        foreach ($permissionsById as $id => $permission) {
            $rows[] = [
                'id' => (int) $id,
                'permission' => $permission,
                'group' => self::group($permission),
                'label' => self::label($permission),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $groupCompare = self::groupPosition($left['group']) <=> self::groupPosition($right['group']);

            return $groupCompare !== 0
                ? $groupCompare
                : strnatcasecmp($left['label'], $right['label']);
        });

        $options = [];
        foreach ($rows as $row) {
            $options[$row['id']] = sprintf(
                '<span style="display:flex; align-items:center; gap:.5rem; flex-wrap:wrap;">'
                . '<span style="display:inline-flex; border-radius:999px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:.1rem .45rem; font-size:.6875rem; font-weight:600;">%s</span>'
                . '<span>%s</span>'
                . '</span>',
                self::escape($row['group']),
                self::escape($row['label']),
            );
        }

        return $options;
    }

    /**
     * @param array<int, string> $permissionsById
     * @return array<int, string>
     */
    public static function descriptions(array $permissionsById): array
    {
        $descriptions = [];

        foreach ($permissionsById as $id => $permission) {
            $description = self::description($permission);
            if ($description !== null) {
                $descriptions[(int) $id] = $description;
            }
        }

        return $descriptions;
    }

    /**
     * @return list<string>
     */
    public static function marketplacePermissions(): array
    {
        return array_values(array_filter(
            array_keys(self::MAP),
            fn (string $permission): bool => self::group($permission) === 'Маркетплейс',
        ));
    }

    private static function groupPosition(string $group): int
    {
        $index = array_search($group, self::GROUP_ORDER, true);

        return $index === false ? 999 : (int) $index;
    }

    private static function humanizePermission(string $permission): string
    {
        [$resource, $action] = array_pad(explode('.', $permission, 2), 2, '');

        $resourceLabel = self::RESOURCE_LABELS[$resource] ?? str_replace(['-', '_'], ' ', $resource);
        $actionLabel = self::ACTION_LABELS[$action] ?? 'Доступ к разделу';

        return trim($actionLabel . ': ' . $resourceLabel);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
