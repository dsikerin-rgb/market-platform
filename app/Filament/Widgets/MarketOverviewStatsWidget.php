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
                Stat::make('Арендаторов сейчас', 0),
                Stat::make('Договоров сейчас', 0),
                Stat::make('Торговых мест всего', 0),
                Stat::make('Торговых мест занято', 0),
                Stat::make('Торговых мест свободно', 0),
                Stat::make('Арендаторов в отчёте', '—'),
                Stat::make('Договоров в отчёте', '—'),
            ];
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $marketId = $this->resolveMarketIdForWidget($user);

        if ($marketId <= 0) {
            return [
                ...($isSuperAdmin ? [Stat::make('Всего рынков', Market::query()->count())] : []),
                Stat::make('Арендаторов сейчас', 0)->description($isSuperAdmin ? 'Выбери рынок' : 'Нет привязки к рынку'),
                Stat::make('Договоров сейчас', 0),
                Stat::make('Торговых мест всего', 0),
                Stat::make('Торговых мест занято', 0),
                Stat::make('Торговых мест свободно', 0),
                Stat::make('Арендаторов в отчёте', '—'),
                Stat::make('Договоров в отчёте', '—'),
            ];
        }

        $market = Market::query()
            ->select(['id', 'name', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);

        // Оперативная часть (состояние "сейчас") НЕ зависит от отчётного месяца.
        $now = CarbonImmutable::now($tz);

        $spacesQuery = MarketSpace::query()->where('market_id', $marketId);
        $totalSpaces = $spacesQuery->count();

        // Текущее занято/свободно берём из статуса мест (операционная истина внутри платформы).
        $occupiedSpaces = (clone $spacesQuery)->where('status', 'occupied')->count();
        $freeSpaces = max($totalSpaces - $occupiedSpaces, 0);

        // Договоры/арендаторы "сейчас" — по интервалам договоров, если они ведутся в БД.
        $contractsNow = $this->countContractsActiveOnDate($marketId, $now);
        if ($contractsNow === null) {
            $contractsNow = TenantContract::query()
                ->where('market_id', $marketId)
                ->where('status', 'active')
                ->count();
        }

        $tenantsNow = $this->countTenantsActiveOnDate($marketId, $now);
        if ($tenantsNow === null) {
            $tenantsNow = Tenant::query()->where('market_id', $marketId)->count();
        }

        // Финансовая/отчётная часть зависит от выбранного месяца.
        [$monthYm, $monthStart, $monthEnd] = $this->resolveMonthRange($tz);
        $monthLabel = $this->formatMonthLabel($monthYm, $tz);

        $reportRows = $this->countAccrualRowsForMonth($marketId, $monthYm, $monthStart, $monthEnd);
        $hasReportData = is_int($reportRows) && $reportRows > 0;

        $tenantsInReport = $this->countTenantsForMonth($marketId, $monthYm, $monthStart, $monthEnd);
        $contractsInReport = $this->countContractsInPeriod($marketId, $monthStart, $monthEnd);

        $accrued = $this->sumAccruedForMonth($marketId, $monthYm, $monthStart, $monthEnd);
        $paid = $this->sumPaidForMonth($marketId, $monthYm, $monthStart, $monthEnd);

        $stats = [];

        if ($isSuperAdmin) {
            $stats[] = Stat::make('Всего рынков', Market::query()->count());
        }

        // === ОПЕРАТИВНОЕ СОСТОЯНИЕ (СЕГОДНЯ) ===
        $stats[] = Stat::make('Арендаторов сейчас', $tenantsNow);
        $stats[] = Stat::make('Договоров сейчас', $contractsNow);
        $stats[] = Stat::make('Торговых мест всего', $totalSpaces);
        $stats[] = Stat::make('Торговых мест занято', $occupiedSpaces);
        $stats[] = Stat::make('Торговых мест свободно', $freeSpaces);

        // === ОТЧЁТ (МЕСЯЦ) — ФИНАНСЫ/ВЫГРУЗКИ ===
        $reportDesc = $hasReportData ? $monthLabel : ($monthLabel . ' · нет данных');

        if ($accrued !== null) {
            $stats[] = Stat::make('Начислено (отчёт)', $this->formatMoney($accrued))->description($reportDesc);
        }

        if ($paid !== null) {
            $stats[] = Stat::make('Оплачено (отчёт)', $this->formatMoney($paid))->description($reportDesc);
        }

        if ($accrued !== null && $paid !== null) {
            $stats[] = Stat::make('Долг (отчёт)', $this->formatMoney($accrued - $paid))->description($reportDesc);
        }

        $stats[] = Stat::make('Арендаторов в отчёте', $tenantsInReport ?? '—')->description($reportDesc);
        $stats[] = Stat::make('Договоров в отчёте', $contractsInReport ?? '—')->description($reportDesc);

        return $stats;
    }

    private function resolveMarketIdForWidget($user): int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return (int) ($user->market_id ?: 0);
        }

        $value = session('dashboard_market_id');

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id")
                ?? session("filament_{$panelId}_market_id")
                ?? session('filament.admin.selected_market_id');
        }

        return (int) ($value ?: 0);
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

    /**
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable}
     */
    private function resolveMonthRange(string $tz): array
    {
        $raw = null;

        // 1) Filament page filters (главное)
        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['month'] ?? $this->pageFilters['period'] ?? null;
        }

        // 2) fallback: старые filters
        if (! $raw && is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? $this->filters['period'] ?? $this->filters['dashboard_month'] ?? null;
        }

        // 3) fallback: session
        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

        // если прилетел Y-m-d (dashboard_period)
        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                $raw = CarbonImmutable::createFromFormat('Y-m-d', $raw, $tz)->format('Y-m');
            } catch (\Throwable) {
                $raw = null;
            }
        }

        $monthYm = (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw))
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        // синхронизируем session для совместимости с виджетами, которые ещё читают оттуда
        session(['dashboard_month' => $monthYm]);
        session(['dashboard_period' => $monthYm . '-01']);

        $start = CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->startOfMonth();
        $end = $start->addMonth();

        return [$monthYm, $start, $end];
    }

    private function formatMonthLabel(string $monthYm, string $tz): string
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->format('m.Y');
        } catch (\Throwable) {
            return $monthYm;
        }
    }

    /**
     * Договоры "активны на дату" (оперативная метрика).
     */
    private function countContractsActiveOnDate(int $marketId, CarbonImmutable $at): ?int
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
        $endCol = $this->pickFirstExisting($cols, ['end_date', 'ends_at', 'date_end', 'expires_at']);

        if (! $startCol && ! $endCol) {
            return null;
        }

        $q = DB::table('tenant_contracts')->where($marketCol, $marketId);

        $statusCol = $this->pickFirstExisting($cols, ['status']);
        if ($statusCol) {
            $q->where($statusCol, '!=', 'cancelled');
        }

        $date = $at->toDateString();

        if ($startCol) {
            $q->where($startCol, '<=', $date);
        }

        if ($endCol) {
            $q->where(function (Builder $qq) use ($endCol, $date): void {
                $qq->whereNull($endCol)->orWhere($endCol, '>=', $date);
            });
        }

        try {
            return (int) $q->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Арендаторы "активны на дату" (оперативная метрика) — DISTINCT tenant_id по активным договорам.
     */
    private function countTenantsActiveOnDate(int $marketId, CarbonImmutable $at): ?int
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_contracts');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        $tenantCol = $this->pickFirstExisting($cols, ['tenant_id']);

        if (! $marketCol || ! $tenantCol) {
            return null;
        }

        $startCol = $this->pickFirstExisting($cols, ['start_date', 'starts_at', 'date_start', 'begins_at']);
        $endCol = $this->pickFirstExisting($cols, ['end_date', 'ends_at', 'date_end', 'expires_at']);

        if (! $startCol && ! $endCol) {
            return null;
        }

        $q = DB::table('tenant_contracts')->where($marketCol, $marketId);

        $statusCol = $this->pickFirstExisting($cols, ['status']);
        if ($statusCol) {
            $q->where($statusCol, '!=', 'cancelled');
        }

        $date = $at->toDateString();

        if ($startCol) {
            $q->where($startCol, '<=', $date);
        }

        if ($endCol) {
            $q->where(function (Builder $qq) use ($endCol, $date): void {
                $qq->whereNull($endCol)->orWhere($endCol, '>=', $date);
            });
        }

        try {
            return (int) $q->distinct()->count($tenantCol);
        } catch (\Throwable) {
            return null;
        }
    }

    private function countAccrualRowsForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
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

        $q = DB::table('tenant_accruals')->where($marketCol, $marketId);
        $this->applyMonthFilter($q, $meta, $periodCol, $monthYm, $start, $end);

        try {
            return (int) $q->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function countTenantsForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if (! $this->tenantAccrualsReady()) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        $tenantCol = $this->pickFirstExisting($cols, ['tenant_id']);

        if (! $marketCol || ! $tenantCol) {
            return null;
        }

        $periodCol = $this->pickPeriodColumn($cols);
        if (! $periodCol) {
            return null;
        }

        $q = DB::table('tenant_accruals')->where($marketCol, $marketId);
        $this->applyMonthFilter($q, $meta, $periodCol, $monthYm, $start, $end);

        try {
            return (int) $q->distinct()->count($tenantCol);
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
        $endCol = $this->pickFirstExisting($cols, ['end_date', 'ends_at', 'date_end', 'expires_at']);

        if (! $startCol && ! $endCol) {
            return null;
        }

        $q = DB::table('tenant_contracts')->where($marketCol, $marketId);

        $statusCol = $this->pickFirstExisting($cols, ['status']);
        if ($statusCol) {
            $q->where($statusCol, '!=', 'cancelled');
        }

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

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

        $expr = $mode === 'paid'
            ? $this->buildPaidSumExpression($cols)
            : $this->buildPayableSumExpression($cols);

        if ($expr === null) {
            return null;
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

        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            $columns = [];
        }

        return [
            'columns' => $columns,
            'types' => [], // сейчас не используем
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
        // ВАЖНО: у вас period часто хранится как "YYYY-MM-01" (date),
        // поэтому сравнение с startDate — рабочий путь и быстрый.
        $startDate = $start->toDateString();
        $q->where(function (Builder $qq) use ($periodCol, $monthYm, $startDate): void {
            $qq->where($periodCol, $monthYm)->orWhere($periodCol, $startDate);
        });
    }

    private function buildPayableSumExpression(array $columns): ?string
    {
        $totalCol = $this->pickFirstExisting($columns, [
            'total_amount',
            'payable_total',
            'amount_total',
            'total',
            'total_with_vat', // <-- важно для вашей БД
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
            'management_fee', // <-- в вашей модели встречается
        ] as $col) {
            if (in_array($col, $columns, true)) {
                $parts[] = 'COALESCE(SUM("' . $col . '"), 0)';
            }
        }

        return $parts === [] ? null : implode(' + ', $parts);
    }

    private function buildPaidSumExpression(array $columns): ?string
    {
        $paidCol = $this->pickFirstExisting($columns, [
            'paid_amount',
            'payment_amount',
            'payments_amount',
            'amount_paid',
        ]);

        return $paidCol ? 'COALESCE(SUM("' . $paidCol . '"), 0)' : null;
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}