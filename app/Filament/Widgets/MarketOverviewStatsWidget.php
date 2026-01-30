<?php
# app/Filament/Widgets/MarketOverviewStatsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketOverviewStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Сводка по рынку';

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [
                Stat::make('Арендаторов', 0),
                Stat::make('Торговых мест всего', 0),
                Stat::make('Торговых мест занято', 0),
                Stat::make('Торговых мест свободно', 0),
                Stat::make('Договоров в периоде', 0),
            ];
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $marketId = $isSuperAdmin
            ? (int) (session('dashboard_market_id') ?: 0)
            : (int) ($user->market_id ?: 0);

        if ($marketId <= 0) {
            return [
                ...($isSuperAdmin ? [Stat::make('Всего рынков', Market::query()->count())] : []),
                Stat::make('Арендаторов', 0)->description('Рынок не выбран'),
                Stat::make('Торговых мест всего', 0),
                Stat::make('Торговых мест занято', 0),
                Stat::make('Торговых мест свободно', 0),
                Stat::make('Договоров в периоде', 0),
            ];
        }

        $market = Market::query()
            ->select(['id', 'name', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);

        [$monthYm, $monthStart, $monthEnd] = $this->resolveMonthRange($tz);

        $periodLabel = $monthStart->format('m.Y') . ' (TZ: ' . $tz . ')';

        $tenantsQuery = Tenant::query()->where('market_id', $marketId);
        $spacesQuery  = MarketSpace::query()->where('market_id', $marketId);

        $totalTenants = $tenantsQuery->count();
        $totalSpaces  = $spacesQuery->count();

        $occupiedSpaces = $this->countOccupiedSpacesForMonth($marketId, $monthYm, $monthStart, $monthEnd);

        if ($occupiedSpaces === null) {
            $occupiedSpaces = (clone $spacesQuery)->where('status', 'occupied')->count();
        }

        $freeSpaces = max($totalSpaces - $occupiedSpaces, 0);

        $contractsInPeriod = $this->countContractsInPeriod($marketId, $monthStart, $monthEnd);
        if ($contractsInPeriod === null) {
            $contractsInPeriod = TenantContract::query()
                ->where('market_id', $marketId)
                ->where('status', 'active')
                ->count();
        }

        $accrued = $this->sumAccruedForMonth($marketId, $monthYm, $monthStart, $monthEnd);
        $paid    = $this->sumPaidForMonth($marketId, $monthYm, $monthStart, $monthEnd);

        $stats = [];

        if ($isSuperAdmin) {
            $stats[] = Stat::make('Всего рынков', Market::query()->count())
                ->description($periodLabel);
        }

        if ($accrued !== null) {
            $stats[] = Stat::make('Начислено за месяц', $this->formatMoney($accrued))
                ->description($periodLabel);
        }

        if ($paid !== null) {
            $stats[] = Stat::make('Оплачено за месяц', $this->formatMoney($paid))
                ->description($periodLabel);
        }

        if ($accrued !== null && $paid !== null) {
            $stats[] = Stat::make('Долг за месяц', $this->formatMoney($accrued - $paid))
                ->description($periodLabel);
        }

        $stats[] = Stat::make('Арендаторов', $totalTenants)->description($periodLabel);
        $stats[] = Stat::make('Торговых мест всего', $totalSpaces)->description($periodLabel);
        $stats[] = Stat::make('Торговых мест занято', $occupiedSpaces)->description($periodLabel);
        $stats[] = Stat::make('Торговых мест свободно', $freeSpaces)->description($periodLabel);
        $stats[] = Stat::make('Договоров в периоде', $contractsInPeriod)->description($periodLabel);

        return $stats;
    }

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    private function resolveMonthRange(string $tz): array
    {
        $raw = null;

        if (is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? $this->filters['period'] ?? $this->filters['dashboard_month'] ?? null;
        }

        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

        $monthYm = is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw)
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        $start = CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->startOfMonth();
        $end   = $start->addMonth();

        return [$monthYm, $start, $end];
    }

    private function countOccupiedSpacesForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if (! $this->tenantAccrualsReady()) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        $spaceCol  = $this->pickFirstExisting($cols, ['market_space_id', 'space_id']);

        if (! $marketCol || ! $spaceCol) {
            return null;
        }

        $periodCol = $this->pickPeriodColumn($cols);
        if (! $periodCol) {
            return null;
        }

        $rentCol = $this->pickFirstExisting($cols, ['rent_amount']);

        // ВАЖНО: для WHERE нужен построчный expr (без SUM)
        $payableRowExpr = $this->buildPayableRowExpression($cols);

        if (! $rentCol && $payableRowExpr === null) {
            return null;
        }

        $q = DB::table('tenant_accruals')->where($marketCol, $marketId);
        $this->applyMonthFilter($q, $meta, $periodCol, $monthYm, $start, $end);

        if ($rentCol) {
            $q->where($rentCol, '>', 0);
        } else {
            $q->whereRaw('(' . $payableRowExpr . ') > 0');
        }

        try {
            return (int) $q->distinct()->count($spaceCol);
        } catch (\Throwable) {
            return null;
        }
    }

    private function countContractsInPeriod(int $marketId, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_contracts');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        if (! $marketCol) {
            return null;
        }

        $startCol = $this->pickFirstExisting($cols, ['start_date', 'starts_at', 'date_start', 'begins_at']);
        $endCol   = $this->pickFirstExisting($cols, ['end_date', 'ends_at', 'date_end', 'expires_at']);

        if (! $startCol && ! $endCol) {
            return null;
        }

        $q = DB::table('tenant_contracts')->where($marketCol, $marketId);

        $statusCol = $this->pickFirstExisting($cols, ['status']);
        if ($statusCol) {
            $q->where($statusCol, '!=', 'cancelled');
        }

        $startDate = $start->toDateString();
        $endDate   = $end->toDateString();

        if ($startCol) {
            $q->where($startCol, '<', $endDate);
        }

        if ($endCol) {
            $q->where(function (Builder $qq) use ($endCol, $startDate): void {
                $qq->whereNull($endCol)->orWhere($endCol, '>=', $startDate);
            });
        }

        try {
            return (int) $q->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function sumAccruedForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        return $this->sumMoneyFromAccruals($marketId, $monthYm, $start, $end, 'accrued');
    }

    private function sumPaidForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        return $this->sumMoneyFromAccruals($marketId, $monthYm, $start, $end, 'paid');
    }

    private function sumMoneyFromAccruals(
        int $marketId,
        string $monthYm,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $mode
    ): ?float {
        if (! $this->tenantAccrualsReady()) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        if (! $marketCol) {
            return null;
        }

        $periodCol = $this->pickPeriodColumn($cols);
        if (! $periodCol) {
            return null;
        }

        $expr = null;

        if ($mode === 'paid') {
            $expr = $this->buildPaidSumExpression($cols);
            if ($expr === null) {
                return null;
            }
        } else {
            // Для SELECT нужны агрегаты SUM(...)
            $expr = $this->buildPayableSumExpression($cols);
            if ($expr === null) {
                return null;
            }
        }

        $q = DB::table('tenant_accruals')->where($marketCol, $marketId);
        $this->applyMonthFilter($q, $meta, $periodCol, $monthYm, $start, $end);

        try {
            $value = $q->selectRaw($expr . ' as v')->value('v');

            return is_numeric($value) ? (float) $value : 0.0;
        } catch (\Throwable) {
            return null;
        }
    }

    private function tenantAccrualsReady(): bool
    {
        return Schema::hasTable('tenant_accruals');
    }

    private function getTableMeta(string $table): array
    {
        $columns = [];
        $types = [];

        try {
            if (DB::getDriverName() === 'sqlite') {
                $rows = DB::select('PRAGMA table_info(' . $table . ')');
                foreach ($rows as $row) {
                    $name = (string) ($row->name ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $columns[] = $name;
                    $types[$name] = strtoupper((string) ($row->type ?? ''));
                }

                return [
                    'columns' => $columns,
                    'types' => $types,
                ];
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            $columns = [];
        }

        return [
            'columns' => $columns,
            'types' => $types,
        ];
    }

    private function pickFirstExisting(array $columns, array $candidates): ?string
    {
        $set = array_flip($columns);

        foreach ($candidates as $candidate) {
            if (isset($set[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private function pickPeriodColumn(array $columns): ?string
    {
        return $this->pickFirstExisting($columns, [
            'period',
            'period_ym',
            'period_start',
            'period_date',
            'accrual_period',
            'month',
        ]);
    }

    private function applyMonthFilter(
        Builder $q,
        array $meta,
        string $periodCol,
        string $monthYm,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): void {
        $types = $meta['types'] ?? [];
        $type = strtoupper((string) ($types[$periodCol] ?? ''));

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        if ($type !== '' && str_contains($type, 'INT')) {
            $ymInt = (int) str_replace('-', '', $monthYm);
            $q->where($periodCol, $ymInt);
            return;
        }

        $lower = strtolower($periodCol);
        if (str_contains($lower, 'start') || str_contains($lower, 'date') || str_contains($lower, '_at')) {
            $q->where($periodCol, '>=', $startDate)->where($periodCol, '<', $endDate);
            return;
        }

        $q->where(function (Builder $qq) use ($periodCol, $monthYm, $startDate): void {
            $qq->where($periodCol, $monthYm)->orWhere($periodCol, $startDate);
        });
    }

    /**
     * Агрегатное выражение для SELECT (SUM...).
     */
    private function buildPayableSumExpression(array $columns): ?string
    {
        $totalCol = $this->pickFirstExisting($columns, [
            'total_amount',
            'payable_total',
            'amount_total',
            'total',
        ]);

        if ($totalCol) {
            return 'COALESCE(SUM("' . $totalCol . '"), 0)';
        }

        $parts = [];

        foreach ([
            'rent_amount',
            'utility_amount',
            'utilities_amount',
            'service_amount',
            'services_amount',
            'maintenance_amount',
            'penalty_amount',
            'penalties_amount',
        ] as $col) {
            if (in_array($col, $columns, true)) {
                $parts[] = 'COALESCE(SUM("' . $col . '"), 0)';
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' + ', $parts);
    }

    /**
     * Построчное выражение для WHERE (без SUM).
     */
    private function buildPayableRowExpression(array $columns): ?string
    {
        $totalCol = $this->pickFirstExisting($columns, [
            'total_amount',
            'payable_total',
            'amount_total',
            'total',
        ]);

        if ($totalCol) {
            return 'COALESCE("' . $totalCol . '", 0)';
        }

        $parts = [];

        foreach ([
            'rent_amount',
            'utility_amount',
            'utilities_amount',
            'service_amount',
            'services_amount',
            'maintenance_amount',
            'penalty_amount',
            'penalties_amount',
        ] as $col) {
            if (in_array($col, $columns, true)) {
                $parts[] = 'COALESCE("' . $col . '", 0)';
            }
        }

        if ($parts === []) {
            return null;
        }

        return implode(' + ', $parts);
    }

    private function buildPaidSumExpression(array $columns): ?string
    {
        $paidCol = $this->pickFirstExisting($columns, [
            'paid_amount',
            'payment_amount',
            'payments_amount',
            'amount_paid',
        ]);

        if (! $paidCol) {
            return null;
        }

        return 'COALESCE(SUM("' . $paidCol . '"), 0)';
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
