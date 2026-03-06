<?php

namespace App\Support;

class PermissionDisplayCatalog
{
    /**
     * @var array<string, array{label: string, group: string}>
     */
    private const MAP = [
        'market-settings.view' => [
            'label' => 'Просмотр настроек рынка',
            'group' => 'Рынок',
        ],
        'market-settings.update' => [
            'label' => 'Изменение настроек рынка',
            'group' => 'Рынок',
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
        ],
        'markets.delete' => [
            'label' => 'Удаление рынка',
            'group' => 'Рынок',
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

    public static function label(string $permission): string
    {
        return self::MAP[$permission]['label'] ?? $permission;
    }

    public static function group(string $permission): string
    {
        if (isset(self::MAP[$permission])) {
            return self::MAP[$permission]['group'];
        }

        $prefix = explode('.', $permission)[0] ?? $permission;

        return match ($prefix) {
            'marketplace' => 'Маркетплейс',
            'market-settings', 'markets' => 'Рынок',
            'staff' => 'Сотрудники',
            default => 'Прочее',
        };
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
}
