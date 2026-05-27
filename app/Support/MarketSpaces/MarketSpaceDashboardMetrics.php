<?php
# app/Support/MarketSpaces/MarketSpaceDashboardMetrics.php

declare(strict_types=1);

namespace App\Support\MarketSpaces;

use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Services\MarketSpaces\MarketSpaceTenantBindingRecorder;

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
        $spaces = self::accountingSpacesQuery($marketId)->get([
            'id',
            'status',
            'area_sqm',
            'rent_rate_value',
            'rent_rate_unit',
        ]);

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
            $physicalArea = max((float) ($space->area_sqm ?? 0), 0.0);
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

            if ($status === 'occupied') {
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

            $normalizedRate = self::normalizeSpaceRentRatePerSqm(
                (float) ($space->rent_rate_value ?? 0),
                (string) ($space->rent_rate_unit ?? ''),
                $effectivePhysicalArea,
            );

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

    public static function accountingSpacesQuery(int $marketId)
    {
        return MarketSpace::query()
            ->where('market_id', $marketId)
            ->where(function ($query): void {
                $query
                    ->whereNull('space_group_role')
                    ->orWhere('space_group_role', '!=', MarketSpace::SPACE_GROUP_ROLE_CHILD)
                    ->orWhereNull('space_group_parent_id');
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
}
