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

    public static function requiresOwnMapShape(?string $spaceGroupRole): bool
    {
        return (string) ($spaceGroupRole ?? MarketSpace::SPACE_GROUP_ROLE_NONE) !== MarketSpace::SPACE_GROUP_ROLE_PARENT;
    }

    public static function scopeRequiresOwnMapShape(Builder $query): Builder
    {
        return $query->where(function ($inner): void {
            $inner
                ->whereNull('space_group_role')
                ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_PARENT);
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
        $requiresOwnShape = self::requiresOwnMapShape((string) ($space->space_group_role ?? MarketSpace::SPACE_GROUP_ROLE_NONE));
        $activeShapeCount ??= self::activeShapeCountFor($space);

        if (! $requiresOwnShape) {
            return [
                'requires_own_map_shape' => false,
                'active_shape_count' => $activeShapeCount,
                'status' => 'not_required',
                'label' => 'Фигура не требуется',
                'color' => 'info',
                'reason_code' => self::REASON_PARENT_GROUP,
                'tooltip' => 'Это parent-группа мест. Собственная фигура не нужна: группа отображается через фигуры входящих в неё child-мест.',
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
}
