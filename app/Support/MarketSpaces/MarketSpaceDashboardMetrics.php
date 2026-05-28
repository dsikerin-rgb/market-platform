<?php
# app/Support/MarketSpaces/MarketSpaceDashboardMetrics.php

declare(strict_types=1);

namespace App\Support\MarketSpaces;

use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use App\Services\MarketSpaces\MarketSpaceTenantBindingRecorder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class MarketSpaceDashboardMetrics
{
    /**
     * @return array{
     *     total_spaces:int,
     *     total_area_sqm:float,
     *     occupied_spaces:int,
     *     occupied_area_sqm:float,
     *     vacant_spaces:int,
     *     vacant_area_sqm:float,
     *     maintenance_spaces:int,
     *     maintenance_area_sqm:float,
     *     reserved_spaces:int,
     *     reserved_area_sqm:float,
     *     rentable_area_sqm:float,
     *     average_rent_rate_per_sqm:?float,
     *     priced_area_sqm:float
     * }
     */
    public static function summarize(int $marketId): array
    {
        $spaces = self::physicalSpacesQuery($marketId)
            ->with([
                'spaceGroupParent:id,tenant_id,rent_rate_value,rent_rate_unit',
            ])
            ->get([
            'id',
            'tenant_id',
            'status',
            'area_sqm',
            'rent_rate_value',
            'rent_rate_unit',
            'space_group_role',
            'space_group_parent_id',
        ]);

        $areaCap = self::resolveAreaOutlierCap(
            $spaces
                ->pluck('area_sqm')
                ->map(static fn ($value): float => max((float) ($value ?? 0), 0.0))
                ->filter(static fn (float $value): bool => $value > 0)
                ->all()
        );

        $sharedUse = MarketSpaceTenantBinding::query()
            ->where('market_id', $marketId)
            ->where('binding_type', MarketSpaceTenantBindingRecorder::BINDING_TYPE_SHARED_USE)
            ->whereNull('ended_at')
            ->selectRaw('market_space_id')
            ->selectRaw('COUNT(*) as active_count')
            ->selectRaw('COALESCE(SUM(COALESCE(area_sqm, 0)), 0) as total_area_sqm')
            ->selectRaw('COALESCE(SUM(CASE WHEN rent_rate IS NOT NULL AND COALESCE(area_sqm, 0) > 0 THEN rent_rate * area_sqm ELSE 0 END), 0) as weighted_rate_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN rent_rate IS NOT NULL AND COALESCE(area_sqm, 0) > 0 THEN area_sqm ELSE 0 END), 0) as rated_area_sqm')
            ->groupBy('market_space_id')
            ->get()
            ->keyBy('market_space_id');

        $summary = [
            'total_spaces' => 0,
            'total_area_sqm' => 0.0,
            'occupied_spaces' => 0,
            'occupied_area_sqm' => 0.0,
            'vacant_spaces' => 0,
            'vacant_area_sqm' => 0.0,
            'maintenance_spaces' => 0,
            'maintenance_area_sqm' => 0.0,
            'reserved_spaces' => 0,
            'reserved_area_sqm' => 0.0,
            'rentable_area_sqm' => 0.0,
            'average_rent_rate_per_sqm' => null,
            'priced_area_sqm' => 0.0,
        ];

        $weightedRateSum = 0.0;
        $pricedAreaSum = 0.0;

        foreach ($spaces as $space) {
            $summary['total_spaces']++;

            $status = self::normalizeStatus((string) ($space->status ?? 'vacant'));
            $spaceId = (int) $space->id;
            $physicalArea = self::sanitizePhysicalArea(
                max((float) ($space->area_sqm ?? 0), 0.0),
                $areaCap,
            );
            $shared = $sharedUse->get($spaceId);
            $sharedArea = $shared ? max((float) ($shared->total_area_sqm ?? 0), 0.0) : 0.0;
            $effectivePhysicalArea = $physicalArea > 0 ? $physicalArea : $sharedArea;

            $summary['total_area_sqm'] += $effectivePhysicalArea;

            if ($status === 'maintenance') {
                $summary['maintenance_spaces']++;
                $summary['maintenance_area_sqm'] += $effectivePhysicalArea;
                continue;
            }

            if ($shared && $sharedArea > 0) {
                $summary['occupied_spaces']++;
                $summary['occupied_area_sqm'] += $sharedArea;
                $summary['rentable_area_sqm'] += $effectivePhysicalArea;
                $weightedRateSum += max((float) ($shared->weighted_rate_sum ?? 0), 0.0);
                $pricedAreaSum += max((float) ($shared->rated_area_sqm ?? 0), 0.0);

                continue;
            }

            if ($space->isEffectivelyOccupied()) {
                $summary['occupied_spaces']++;
                $summary['occupied_area_sqm'] += $effectivePhysicalArea;
                $summary['rentable_area_sqm'] += $effectivePhysicalArea;
            } elseif ($status === 'occupied') {
                $summary['occupied_spaces']++;
                $summary['occupied_area_sqm'] += $effectivePhysicalArea;
                $summary['rentable_area_sqm'] += $effectivePhysicalArea;
            } elseif ($status === 'vacant') {
                $summary['vacant_spaces']++;
                $summary['vacant_area_sqm'] += $effectivePhysicalArea;
                $summary['rentable_area_sqm'] += $effectivePhysicalArea;
            } elseif ($status === 'reserved') {
                $summary['reserved_spaces']++;
                $summary['reserved_area_sqm'] += $effectivePhysicalArea;
            }

            $normalizedRate = self::resolveNormalizedRateForPhysicalSpace($space, $effectivePhysicalArea);

            if ($normalizedRate !== null && $effectivePhysicalArea > 0) {
                $weightedRateSum += $normalizedRate * $effectivePhysicalArea;
                $pricedAreaSum += $effectivePhysicalArea;
            }
        }

        if ($pricedAreaSum > 0) {
            $summary['average_rent_rate_per_sqm'] = $weightedRateSum / $pricedAreaSum;
            $summary['priced_area_sqm'] = $pricedAreaSum;
        }

        return $summary;
    }

    public static function countCurrentTenants(int $marketId): int
    {
        $spaces = self::physicalSpacesQuery($marketId)
            ->with([
                'spaceGroupParent:id,tenant_id',
            ])
            ->get([
            'id',
            'tenant_id',
            'status',
            'space_group_role',
            'space_group_parent_id',
        ]);

        if ($spaces->isEmpty()) {
            return 0;
        }

        $spaceIds = $spaces->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $directTenantIds = $spaces
            ->filter(static function (MarketSpace $space): bool {
                return $space->isEffectivelyOccupied() || (
                    self::normalizeStatus((string) ($space->status ?? 'vacant')) === 'occupied'
                    && filled($space->tenant_id)
                );
            })
            ->map(static fn (MarketSpace $space): int => (int) ($space->effectiveTenantId() ?? $space->tenant_id ?? 0));

        $sharedTenantIds = MarketSpaceTenantBinding::query()
            ->where('market_id', $marketId)
            ->whereIn('market_space_id', $spaceIds)
            ->where('binding_type', MarketSpaceTenantBindingRecorder::BINDING_TYPE_SHARED_USE)
            ->whereNull('ended_at')
            ->pluck('tenant_id')
            ->map(static fn ($id): int => (int) $id);

        $tenantIds = $directTenantIds
            ->concat($sharedTenantIds)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($tenantIds->isEmpty()) {
            return 0;
        }

        return Tenant::query()
            ->where('market_id', $marketId)
            ->active()
            ->whereIn('id', $tenantIds->all())
            ->count();
    }

    public static function accountingSpacesQuery(int $marketId)
    {
        return MarketSpace::query()
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('space_group_role')
                    ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                    ->orWhereNull('space_group_parent_id');
            });
    }

    public static function physicalSpacesQuery(int $marketId): Builder
    {
        $query = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->where(function ($query): void {
                $query
                    ->whereNull('space_group_role')
                    ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_PARENT);
            });

        if (! Schema::hasTable('market_space_map_shapes')) {
            return $query;
        }

        return $query->whereHas('mapShapes', static function (Builder $shapeQuery): void {
            if (Schema::hasColumn('market_space_map_shapes', 'is_active')) {
                $shapeQuery->where('is_active', true);
            }
        });
    }

    private static function normalizeStatus(string $status): string
    {
        $status = trim($status);

        if ($status === 'free') {
            return 'vacant';
        }

        return $status !== '' ? $status : 'vacant';
    }

    private static function normalizeSpaceRentRatePerSqm(float $value, string $unit, float $area): ?float
    {
        if ($value <= 0) {
            return null;
        }

        $unit = trim($unit);

        return match ($unit) {
            'per_sqm_month' => $value,
            'per_space_month' => $area > 0 ? ($value / $area) : null,
            default => null,
        };
    }

    private static function resolveNormalizedRateForPhysicalSpace(MarketSpace $space, float $effectivePhysicalArea): ?float
    {
        $localRate = self::normalizeSpaceRentRatePerSqm(
            (float) ($space->rent_rate_value ?? 0),
            (string) ($space->rent_rate_unit ?? ''),
            $effectivePhysicalArea,
        );

        if ($localRate !== null) {
            return $localRate;
        }

        $parent = $space->spaceGroupParent;

        if (! $parent instanceof MarketSpace) {
            return null;
        }

        $parentUnit = (string) ($parent->rent_rate_unit ?? '');

        if ($parentUnit !== 'per_sqm_month') {
            return null;
        }

        return self::normalizeSpaceRentRatePerSqm(
            (float) ($parent->rent_rate_value ?? 0),
            $parentUnit,
            $effectivePhysicalArea,
        );
    }

    /**
     * @param  list<float>  $areas
     */
    private static function resolveAreaOutlierCap(array $areas): float
    {
        if ($areas === []) {
            return 10000.0;
        }

        sort($areas, SORT_NUMERIC);

        $index = (int) floor((count($areas) - 1) * 0.95);
        $p95 = (float) ($areas[$index] ?? 0.0);

        return max(10000.0, $p95 * 10.0);
    }

    private static function sanitizePhysicalArea(float $area, float $cap): float
    {
        if ($area <= 0) {
            return 0.0;
        }

        return $area > $cap ? 0.0 : $area;
    }
}
