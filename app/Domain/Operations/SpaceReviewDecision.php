<?php

declare(strict_types=1);

namespace App\Domain\Operations;

final class SpaceReviewDecision
{
    public const BIND_SHAPE_TO_SPACE = 'bind_shape_to_space';
    public const UNBIND_SHAPE_FROM_SPACE = 'unbind_shape_from_space';
    public const MARK_SPACE_FREE = 'mark_space_free';
    public const MARK_SPACE_SERVICE = 'mark_space_service';
    public const FIX_SPACE_IDENTITY = 'fix_space_identity';
    public const OCCUPANCY_CONFLICT = 'occupancy_conflict';
    public const TENANT_CHANGED_ON_SITE = 'tenant_changed_on_site';
    public const SHAPE_NOT_FOUND = 'shape_not_found';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::BIND_SHAPE_TO_SPACE => 'Привязать фигуру к месту',
            self::UNBIND_SHAPE_FROM_SPACE => 'Отвязать фигуру',
            self::MARK_SPACE_FREE => 'Отметить место как свободное',
            self::MARK_SPACE_SERVICE => 'Отметить место как служебное',
            self::FIX_SPACE_IDENTITY => 'Уточнить номер и название',
            self::OCCUPANCY_CONFLICT => 'Конфликт по месту',
            self::TENANT_CHANGED_ON_SITE => 'На месте другой арендатор',
            self::SHAPE_NOT_FOUND => 'Место не найдено на карте',
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
     * @return list<string>
     */
    public static function appliedValues(): array
    {
        return [
            self::BIND_SHAPE_TO_SPACE,
            self::UNBIND_SHAPE_FROM_SPACE,
            self::MARK_SPACE_FREE,
            self::MARK_SPACE_SERVICE,
            self::FIX_SPACE_IDENTITY,
        ];
    }

    /**
     * @return list<string>
     */
    public static function observedValues(): array
    {
        return [
            self::OCCUPANCY_CONFLICT,
            self::TENANT_CHANGED_ON_SITE,
            self::SHAPE_NOT_FOUND,
        ];
    }

    public static function requiresShapeId(string $decision): bool
    {
        return in_array($decision, [
            self::BIND_SHAPE_TO_SPACE,
            self::UNBIND_SHAPE_FROM_SPACE,
        ], true);
    }

    public static function requiresReason(string $decision): bool
    {
        return in_array($decision, [
            self::OCCUPANCY_CONFLICT,
            self::TENANT_CHANGED_ON_SITE,
            self::SHAPE_NOT_FOUND,
        ], true);
    }

    public static function requiresObservedTenantName(string $decision): bool
    {
        return $decision === self::TENANT_CHANGED_ON_SITE;
    }

    public static function isIdentityFix(string $decision): bool
    {
        return $decision === self::FIX_SPACE_IDENTITY;
    }

    public static function defaultOperationStatus(string $decision): string
    {
        return in_array($decision, self::observedValues(), true) ? 'observed' : 'applied';
    }

    public static function reviewStatusForDecision(string $decision): string
    {
        return match ($decision) {
            self::OCCUPANCY_CONFLICT => 'conflict',
            self::TENANT_CHANGED_ON_SITE => 'changed_tenant',
            self::SHAPE_NOT_FOUND => 'not_found',
            default => 'changed',
        };
    }
}
