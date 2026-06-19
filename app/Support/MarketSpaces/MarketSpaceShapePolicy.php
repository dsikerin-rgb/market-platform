<?php

declare(strict_types=1);

namespace App\Support\MarketSpaces;

use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class MarketSpaceShapePolicy
{
    public const REASON_PARENT_GROUP = 'parent_group';
    public const REASON_HAS_SHAPE = 'has_shape';
    public const REASON_MISSING_SHAPE = 'missing_shape';
    public const REASON_SHAPES_UNAVAILABLE = 'shapes_unavailable';
    public const REASON_SHARED_USE_PARTICIPANT = 'shared_use_participant';

    public static function requiresOwnMapShape(?string $spaceGroupRole): bool
    {
        return (string) ($spaceGroupRole ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_PARENT;
    }

    public static function scopeRequiresOwnMapShape(Builder $query, ?int $marketId = null): Builder
    {
        $query->where(function ($inner): void {
            $inner
                ->whereNull('space_group_role')
                ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_PARENT);
        });

        if ($marketId !== null) {
            $excludedIds = self::sharedUseParticipantPseudoSpaceIdsWithBaseShape($marketId);

            if ($excludedIds !== []) {
                $query->whereKeyNot($excludedIds);
            }
        }

        return $query;
    }

    public static function scopeUsableMapShape(Builder $query, ?int $marketId = null): Builder
    {
        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
            $query->where('is_active', true);
        }

        return $query->where(function ($shape): void {
            $shape->where(function ($bbox): void {
                $bbox->whereNotNull('bbox_x1')
                    ->whereNotNull('bbox_y1')
                    ->whereNotNull('bbox_x2')
                    ->whereNotNull('bbox_y2')
                    ->whereColumn('bbox_x1', '<', 'bbox_x2')
                    ->whereColumn('bbox_y1', '<', 'bbox_y2');
            })->orWhereJsonLength('polygon', '>=', 3);
        });
    }

    /**
     * @return array{
     *     requires_own_map_shape:bool,
     *     active_shape_count:int,
     *     status:string,
     *     label:string,
     *     color:string,
     *     reason_code:string,
     *     tooltip:string
     * }
     */
    public static function requirementFor(MarketSpace $space, ?int $activeShapeCount = null): array
    {
        $sharedUseParticipantHasBaseShape = self::isSharedUseParticipantPseudoSpaceWithBaseShape($space);
        $requiresOwnShape = self::requiresOwnMapShape((string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE))
            && ! $sharedUseParticipantHasBaseShape;
        $activeShapeCount ??= self::activeShapeCountFor($space);

        if (! $requiresOwnShape) {
            $isParentGroup = (string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE) === MarketSpace::SPACE_GROUP_ROLE_PARENT;

            return [
                'requires_own_map_shape' => false,
                'active_shape_count' => $activeShapeCount,
                'status' => 'not_required',
                'label' => 'Фигура не требуется',
                'color' => 'info',
                'reason_code' => $isParentGroup ? self::REASON_PARENT_GROUP : self::REASON_SHARED_USE_PARTICIPANT,
                'tooltip' => $isParentGroup
                    ? 'Это parent-группа мест. Собственная фигура не нужна: группа отображается через фигуры входящих в неё child-мест.'
                    : 'Это служебная карточка совместного использования. Основное место уже имеет фигуру на карте.',
            ];
        }

        if (! Schema::hasTable('market_space_map_shapes')) {
            return [
                'requires_own_map_shape' => true,
                'active_shape_count' => 0,
                'status' => 'unknown',
                'label' => 'Карта недоступна',
                'color' => 'gray',
                'reason_code' => self::REASON_SHAPES_UNAVAILABLE,
                'tooltip' => 'Таблица фигур карты недоступна, статус фигуры определить нельзя.',
            ];
        }

        if ($activeShapeCount > 0) {
            return [
                'requires_own_map_shape' => true,
                'active_shape_count' => $activeShapeCount,
                'status' => 'present',
                'label' => 'Фигура есть',
                'color' => 'success',
                'reason_code' => self::REASON_HAS_SHAPE,
                'tooltip' => 'У физического места есть активная фигура карты.',
            ];
        }

        return [
            'requires_own_map_shape' => true,
            'active_shape_count' => 0,
            'status' => 'missing',
            'label' => 'Фигура нужна',
            'color' => 'warning',
            'reason_code' => self::REASON_MISSING_SHAPE,
            'tooltip' => 'Это физическое место или child-место группы. Для него нужна собственная фигура карты.',
        ];
    }

    public static function activeShapeCountFor(MarketSpace $space): int
    {
        $loadedCount = $space->getAttribute('active_map_shapes_count');
        if ($loadedCount !== null) {
            return max((int) $loadedCount, 0);
        }

        if (! filled($space->id) || ! Schema::hasTable('market_space_map_shapes')) {
            return 0;
        }

        $query = MarketSpaceMapShape::query()
            ->where('market_space_id', (int) $space->id);

        if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
            $query->where('is_active', true);
        }

        return (int) $query->count();
    }

    public static function sharedUseParticipantBaseNumber(?string $number): ?string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        $baseNumber = preg_replace('/__t[0-9]+$/i', '', $number);

        if ($baseNumber === $number) {
            $baseNumber = preg_replace('/_t[0-9]+$/i', '', $number);
        }

        if (! is_string($baseNumber)) {
            return null;
        }

        $baseNumber = trim($baseNumber);

        return $baseNumber !== '' && $baseNumber !== $number ? $baseNumber : null;
    }

    private static function isSharedUseParticipantPseudoSpaceWithBaseShape(MarketSpace $space): bool
    {
        if (! filled($space->market_id) || ! Schema::hasTable('market_space_map_shapes')) {
            return false;
        }

        $baseNumber = self::sharedUseParticipantBaseNumber((string) ($space->number ?? ''));

        if ($baseNumber === null) {
            return false;
        }

        $baseSpaceId = MarketSpace::query()
            ->where('market_id', (int) $space->market_id)
            ->where('number', $baseNumber)
            ->when(filled($space->id), static fn (Builder $query): Builder => $query->whereKeyNot((int) $space->id))
            ->value('id');

        if (! $baseSpaceId) {
            return false;
        }

        return MarketSpaceMapShape::query()
            ->where('market_space_id', (int) $baseSpaceId)
            ->tap(static fn (Builder $query): Builder => self::scopeUsableMapShape($query, (int) $space->market_id))
            ->exists();
    }

    /**
     * @return list<int>
     */
    private static function sharedUseParticipantPseudoSpaceIdsWithBaseShape(int $marketId): array
    {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return [];
        }

        $pseudoSpaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where(function (Builder $query): void {
                $query->where('number', 'like', '%__t%')
                    ->orWhere('number', 'like', '%_t%');
            })
            ->get(['id', 'number']);

        if ($pseudoSpaces->isEmpty()) {
            return [];
        }

        $baseNumberByPseudoId = [];
        foreach ($pseudoSpaces as $space) {
            $baseNumber = self::sharedUseParticipantBaseNumber((string) ($space->number ?? ''));

            if ($baseNumber !== null) {
                $baseNumberByPseudoId[(int) $space->id] = $baseNumber;
            }
        }

        if ($baseNumberByPseudoId === []) {
            return [];
        }

        $baseSpaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereIn('number', array_values(array_unique($baseNumberByPseudoId)))
            ->get(['id', 'number']);

        if ($baseSpaces->isEmpty()) {
            return [];
        }

        $baseSpaceIds = $baseSpaces->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $baseIdsWithUsableShape = MarketSpaceMapShape::query()
            ->whereIn('market_space_id', $baseSpaceIds)
            ->tap(static fn (Builder $query): Builder => self::scopeUsableMapShape($query, $marketId))
            ->pluck('market_space_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();

        if ($baseIdsWithUsableShape->isEmpty()) {
            return [];
        }

        $baseNumbersWithUsableShape = [];
        foreach ($baseSpaces as $baseSpace) {
            if ($baseIdsWithUsableShape->has((int) $baseSpace->id)) {
                $baseNumbersWithUsableShape[(string) $baseSpace->number] = true;
            }
        }

        $excludedIds = [];
        foreach ($baseNumberByPseudoId as $pseudoId => $baseNumber) {
            if (isset($baseNumbersWithUsableShape[$baseNumber])) {
                $excludedIds[] = (int) $pseudoId;
            }
        }

        return array_values(array_unique($excludedIds));
    }
}
