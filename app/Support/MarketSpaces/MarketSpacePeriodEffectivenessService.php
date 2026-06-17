<?php
# app/Support/MarketSpaces/MarketSpacePeriodEffectivenessService.php

declare(strict_types=1);

namespace App\Support\MarketSpaces;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketSpacePeriodEffectivenessService
{
    /**
     * @param  list<string>  $months
     * @return list<float|null>
     */
    public function areaOccupancyPercentSeries(int $marketId, array $months, string $tz): array
    {
        if ($months === []) {
            return [];
        }

        $spaces = $this->loadRentableAccountingSpaces($marketId);
        $rentableArea = array_sum(array_map(
            static fn (array $space): float => (float) $space['area_sqm'],
            $spaces,
        ));

        if ($rentableArea <= 0.0) {
            return array_fill(0, count($months), null);
        }

        $leasedAreasByMonth = $this->loadLeasedAreasByMonth($marketId, $months, $tz, $spaces);
        $series = [];

        foreach ($months as $month) {
            if (! array_key_exists($month, $leasedAreasByMonth)) {
                $series[] = null;
                continue;
            }

            $leasedArea = min((float) $leasedAreasByMonth[$month], $rentableArea);
            $series[] = round(($leasedArea / $rentableArea) * 100, 1);
        }

        return $series;
    }

    /**
     * @return array<int, array{area_sqm:float,status:string}>
     */
    private function loadRentableAccountingSpaces(int $marketId): array
    {
        $spaces = MarketSpaceDashboardMetrics::accountingSpacesQuery($marketId)
            ->get([
                'id',
                'area_sqm',
                'status',
                'space_group_role',
                'space_group_parent_id',
            ]);

        $areaCap = $this->resolveAreaOutlierCap(
            $spaces
                ->pluck('area_sqm')
                ->map(static fn ($value): float => max((float) ($value ?? 0), 0.0))
                ->filter(static fn (float $value): bool => $value > 0.0)
                ->all(),
        );

        $rentable = [];

        foreach ($spaces as $space) {
            $status = $this->normalizeStatus((string) ($space->status ?? 'vacant'));
            $area = $this->sanitizeArea(max((float) ($space->area_sqm ?? 0), 0.0), $areaCap);

            if ($area <= 0.0) {
                continue;
            }

            if (in_array($status, ['maintenance', 'reserved'], true)) {
                continue;
            }

            $rentable[(int) $space->id] = [
                'area_sqm' => $area,
                'status' => $status,
            ];
        }

        return $rentable;
    }

    /**
     * @param  list<string>  $months
     * @param  array<int, array{area_sqm:float,status:string}>  $spaces
     * @return array<string, float>
     */
    private function loadLeasedAreasByMonth(int $marketId, array $months, string $tz, array $spaces): array
    {
        if ($months === [] || $spaces === [] || ! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        $spaceIds = array_keys($spaces);
        $globalStart = $this->parseMonthStart($months[0], $tz)->startOfMonth();
        $globalEnd = $this->parseMonthStart($months[count($months) - 1], $tz)->endOfMonth();

        $query = DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->whereIn('market_space_id', $spaceIds)
            ->whereNotNull('tenant_id')
            ->where(function ($query) use ($globalEnd): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $globalEnd->toDateString());
            })
            ->where(function ($query) use ($globalStart): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $globalStart->toDateString());
            });

        if (Schema::hasColumn('tenant_contracts', 'space_mapping_mode')) {
            $query->where(function ($query): void {
                $query->whereNull('space_mapping_mode')
                    ->orWhere('space_mapping_mode', '!=', 'excluded');
            });
        }

        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $query->where(function ($query): void {
                $query->where('is_active', true)
                    ->orWhereNotNull('starts_at')
                    ->orWhereNotNull('ends_at');
            });
        }

        $rows = $query
            ->select([
                'market_space_id',
                'status',
                'starts_at',
                'ends_at',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $occupiedByMonthSpace = [];

        foreach ($months as $month) {
            $monthStart = $this->parseMonthStart($month, $tz)->startOfMonth();
            $monthEnd = $monthStart->endOfMonth();

            foreach ($rows as $row) {
                if (! $this->contractCanOccupyArea($row)) {
                    continue;
                }

                if (! $this->contractOverlapsMonth($row, $monthStart, $monthEnd, $tz)) {
                    continue;
                }

                $spaceId = (int) ($row->market_space_id ?? 0);
                if (! isset($spaces[$spaceId])) {
                    continue;
                }

                $occupiedByMonthSpace[$month][$spaceId] = (float) $spaces[$spaceId]['area_sqm'];
            }
        }

        $result = [];

        foreach ($occupiedByMonthSpace as $month => $spacesForMonth) {
            $result[$month] = array_sum($spacesForMonth);
        }

        return $result;
    }

    private function contractCanOccupyArea(object $row): bool
    {
        $status = trim((string) ($row->status ?? ''));

        if (in_array($status, ['archived', 'cancelled'], true)) {
            return false;
        }

        if ($status === 'terminated' && blank($row->ends_at ?? null)) {
            return false;
        }

        return true;
    }

    private function contractOverlapsMonth(object $row, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, string $tz): bool
    {
        $startsAt = filled($row->starts_at ?? null)
            ? CarbonImmutable::parse((string) $row->starts_at, $tz)->startOfDay()
            : null;

        $endsAt = filled($row->ends_at ?? null)
            ? CarbonImmutable::parse((string) $row->ends_at, $tz)->endOfDay()
            : null;

        if ($startsAt && $startsAt->greaterThan($monthEnd)) {
            return false;
        }

        if ($endsAt && $endsAt->lessThan($monthStart)) {
            return false;
        }

        return true;
    }

    private function parseMonthStart(string $ym, string $tz): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', $ym, $tz)->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->startOfMonth();
        }
    }

    private function normalizeStatus(string $status): string
    {
        $status = trim($status);

        if ($status === 'free') {
            return 'vacant';
        }

        return $status !== '' ? $status : 'vacant';
    }

    /**
     * @param  list<float>  $areas
     */
    private function resolveAreaOutlierCap(array $areas): float
    {
        if ($areas === []) {
            return 10000.0;
        }

        sort($areas, SORT_NUMERIC);

        $index = (int) floor((count($areas) - 1) * 0.95);
        $p95 = (float) ($areas[$index] ?? 0.0);

        return max(10000.0, $p95 * 10.0);
    }

    private function sanitizeArea(float $area, float $cap): float
    {
        if ($area <= 0.0) {
            return 0.0;
        }

        return $area > $cap ? 0.0 : $area;
    }
}
