<?php

declare(strict_types=1);

namespace App\Domain\Operations;

final class OperationType
{
    public const TENANT_SWITCH = 'tenant_switch';
    public const RENT_RATE_CHANGE = 'rent_rate_change';
    public const SPACE_ATTRS_CHANGE = 'space_attrs_change';
    public const ELECTRICITY_INPUT = 'electricity_input';
    public const ACCRUAL_ADJUSTMENT = 'accrual_adjustment';
    public const PERIOD_CLOSE = 'period_close';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::TENANT_SWITCH => 'Смена арендатора',
            self::RENT_RATE_CHANGE => 'Изменение ставки аренды',
            self::SPACE_ATTRS_CHANGE => 'Изменение характеристик места',
            self::ELECTRICITY_INPUT => 'Ввод электроэнергии',
            self::ACCRUAL_ADJUSTMENT => 'Корректировка начислений',
            self::PERIOD_CLOSE => 'Закрытие месяца',
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_keys(self::labels());
    }

    /**
     * @return array<string, string>
     */
    public static function managementLabels(): array
    {
        return array_intersect_key(self::labels(), array_flip(self::managementValues()));
    }

    /**
     * @return list<string>
     */
    public static function managementValues(): array
    {
        return [
            self::SPACE_ATTRS_CHANGE,
            self::ELECTRICITY_INPUT,
            self::ACCRUAL_ADJUSTMENT,
            self::PERIOD_CLOSE,
        ];
    }
}
