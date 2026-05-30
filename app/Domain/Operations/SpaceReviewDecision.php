<?php
# app/Domain/Operations/SpaceReviewDecision.php

declare(strict_types=1);

namespace App\Domain\Operations;

final class SpaceReviewDecision
{
    public const BIND_SHAPE_TO_SPACE = 'bind_shape_to_space';
    public const UNBIND_SHAPE_FROM_SPACE = 'unbind_shape_from_space';
    public const MARK_SPACE_FREE = 'mark_space_free';
    public const MARK_SPACE_SERVICE = 'mark_space_service';
    public const FIX_SPACE_IDENTITY = 'fix_space_identity';
    public const MERGE_SPACE_INTO_CANONICAL = 'merge_space_into_canonical';
    public const RETIRE_SPACE = 'retire_space';
    public const SPACE_IDENTITY_NEEDS_CLARIFICATION = 'space_identity_needs_clarification';
    public const DUPLICATE_SPACE_NEEDS_RESOLUTION = 'duplicate_space_needs_resolution';
    public const HISTORICAL_COMPOSED_SPACE_REVIEWED = 'historical_composed_space_reviewed';
    public const OCCUPANCY_CONFLICT = 'occupancy_conflict';
    public const TENANT_CHANGED_ON_SITE = 'tenant_changed_on_site';
    public const SHAPE_NOT_FOUND = 'shape_not_found';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::MERGE_SPACE_INTO_CANONICAL => 'Закрыть старую карточку и связать с основным местом',
            self::RETIRE_SPACE => 'Место больше не существует',
            self::BIND_SHAPE_TO_SPACE => 'Привязать фигуру к месту',
            self::UNBIND_SHAPE_FROM_SPACE => 'Отвязать фигуру',
            self::MARK_SPACE_FREE => 'Отметить место как свободное',
            self::MARK_SPACE_SERVICE => 'Отметить место как служебное',
            self::FIX_SPACE_IDENTITY => 'Применить уточнение',
            self::SPACE_IDENTITY_NEEDS_CLARIFICATION => 'Требует уточнения',
            self::DUPLICATE_SPACE_NEEDS_RESOLUTION => 'Разбор дубля места',
            self::HISTORICAL_COMPOSED_SPACE_REVIEWED => 'Закрыть как историческое составное место',
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
            self::MERGE_SPACE_INTO_CANONICAL,
            self::RETIRE_SPACE,
            self::DUPLICATE_SPACE_NEEDS_RESOLUTION,
            self::HISTORICAL_COMPOSED_SPACE_REVIEWED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function observedValues(): array
    {
        return [
            self::SPACE_IDENTITY_NEEDS_CLARIFICATION,
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
            self::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            self::HISTORICAL_COMPOSED_SPACE_REVIEWED,
            self::RETIRE_SPACE,
            self::OCCUPANCY_CONFLICT,
            self::TENANT_CHANGED_ON_SITE,
            self::SHAPE_NOT_FOUND,
        ], true);
    }

    public static function requiresObservedTenantName(string $decision): bool
    {
        return $decision === self::TENANT_CHANGED_ON_SITE;
    }

    public static function requiresCandidateSpaceId(string $decision): bool
    {
        return in_array($decision, [
            self::DUPLICATE_SPACE_NEEDS_RESOLUTION,
            self::MERGE_SPACE_INTO_CANONICAL,
        ], true);
    }

    public static function requiresEffectiveDate(string $decision): bool
    {
        return in_array($decision, [
            self::MERGE_SPACE_INTO_CANONICAL,
            self::RETIRE_SPACE,
        ], true);
    }

    public static function isIdentityFix(string $decision): bool
    {
        return $decision === self::FIX_SPACE_IDENTITY;
    }

    public static function defaultOperationStatus(string $decision): string
    {
        return SpaceReviewStateMachine::defaultOperationStatus($decision);
    }

    public static function reviewStatusForDecision(string $decision): string
    {
        return SpaceReviewStateMachine::reviewStatusForDecision($decision);
    }
}
