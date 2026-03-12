<?php
# app/Filament/Widgets/MarketOverviewStatsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketResource;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
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

    protected ?string $heading = 'Показатели рынка';

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->buildEmptyStats();
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $marketId = $this->resolveMarketIdForWidget($user);

        if ($marketId <= 0) {
            return $this->buildEmptyStats(
                isSuperAdmin: $isSuperAdmin,
                note: $isSuperAdmin ? 'Сначала выберите рынок' : 'Нет привязки к рынку',
            );
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

        $tenantsNow = $this->countTenantsActiveOnDate($marketId, $now);
        if ($tenantsNow === null) {
            $tenantsNow = Tenant::query()->where('market_id', $marketId)->active()->count();
        }

        // Финансовая/отчётная часть зависит от выбранного месяца.
        [$monthYm, $monthStart, $monthEnd] = $this->resolveMonthRange($tz);
        $monthLabel = $this->formatMonthLabel($monthYm, $tz);

        $financialSummary = $this->resolveFinancialSummaryForMonth($marketId, $monthYm, $monthStart, $monthEnd);
        $reportRows = $financialSummary['rows'];
        $hasReportData = is_int($reportRows) && $reportRows > 0;

        $accrued = $financialSummary['accrued'];
        $paid = $financialSummary['paid'];
        $debt = $financialSummary['debt'];

        $tenantsUrl = TenantResource::getUrl('index');
        $spacesUrl = MarketSpaceResource::getUrl('index');
        $occupiedSpacesUrl = $this->appendQueryString($spacesUrl, [
            'tableFilters' => [
                'status' => ['value' => 'occupied'],
            ],
        ]);
        $vacantSpacesUrl = $this->appendQueryString($spacesUrl, [
            'tableFilters' => [
                'status' => ['value' => 'vacant'],
            ],
        ]);
        $accrualsUrl = $this->appendQueryString(TenantAccrualResource::getUrl('index'), [
            'tableFilters' => [
                'period' => ['value' => $monthStart->toDateString()],
            ],
        ]);

        $stats = [];

        if ($isSuperAdmin) {
            $stats[] = $this->makeStat(
                label: 'Рынков в системе',
                value: Market::query()->count(),
                description: 'Открыть список рынков',
                url: MarketResource::getUrl('index'),
                color: 'primary',
                icon: 'heroicon-o-building-storefront',
            );
        }

        $reportDesc = $hasReportData
            ? ($monthLabel . ' · ' . $financialSummary['source'])
            : ($monthLabel . ' · нет финансовых данных');
        $accruedValue = $accrued ?? 0.0;
        $paidValue = $paid ?? 0.0;
        $debtValue = $debt ?? ($accruedValue - $paidValue);
        $marketScopeDesc = $isSuperAdmin ? 'На выбранном рынке' : 'На вашем рынке';
        $occupancyRate = $totalSpaces > 0
            ? round(($occupiedSpaces / $totalSpaces) * 100)
            : 0;
        $occupancyDesc = $totalSpaces > 0
            ? "Занято {$occupiedSpaces} из {$totalSpaces}"
            : 'На рынке пока нет мест';

        $stats[] = $this->makeStat(
            label: 'Арендаторы сейчас',
            value: $tenantsNow,
            description: $marketScopeDesc,
            url: $tenantsUrl,
            color: 'primary',
            icon: 'heroicon-o-users',
        );
        $stats[] = $this->makeStat(
            label: 'Торговых мест',
            value: $totalSpaces,
            description: $marketScopeDesc,
            url: $spacesUrl,
            color: 'gray',
            icon: 'heroicon-o-home-modern',
        );
        $stats[] = $this->makeStat(
            label: 'Занято мест',
            value: $occupiedSpaces,
            description: 'Фильтр: занятые места',
            url: $occupiedSpacesUrl,
            color: 'success',
            icon: 'heroicon-o-check-circle',
        );
        $stats[] = $this->makeStat(
            label: 'Свободно мест',
            value: $freeSpaces,
            description: 'Фильтр: свободные места',
            url: $vacantSpacesUrl,
            color: 'warning',
            icon: 'heroicon-o-sparkles',
        );
        $stats[] = $this->makeStat(
            label: 'Заполняемость',
            value: $occupancyRate . ' %',
            description: $occupancyDesc,
            url: $occupiedSpacesUrl,
            color: $occupiedSpaces > 0 ? 'success' : 'gray',
            icon: 'heroicon-o-chart-bar',
        );
        $stats[] = $this->makeStat(
            label: 'Начислено за месяц',
            value: $this->formatMoney($accruedValue) . ' ₽',
            description: $reportDesc,
            url: $accrualsUrl,
            color: 'primary',
            icon: 'heroicon-o-banknotes',
        );
        $stats[] = $this->makeStat(
            label: 'Оплачено за месяц',
            value: $this->formatMoney($paidValue) . ' ₽',
            description: $reportDesc,
            url: $accrualsUrl,
            color: 'success',
            icon: 'heroicon-o-arrow-down-circle',
        );
        $stats[] = $this->makeStat(
            label: 'К оплате за месяц',
            value: $this->formatMoney($debtValue) . ' ₽',
            description: $reportDesc,
            url: $accrualsUrl,
            color: $debtValue > 0 ? 'danger' : 'success',
            icon: 'heroicon-o-scale',
        );

        return $stats;
    }

    /**
     * @return array<int, Stat>
     */
    private function buildEmptyStats(bool $isSuperAdmin = false, ?string $note = null): array
    {
        $stats = [];

        if ($isSuperAdmin) {
            $stats[] = $this->makeStat(
                label: 'Рынков в системе',
                value: Market::query()->count(),
                description: 'Открыть список рынков',
                url: MarketResource::getUrl('index'),
                color: 'primary',
                icon: 'heroicon-o-building-storefront',
            );
        }

        $stats[] = $this->makeStat('Арендаторы сейчас', 0, $note, null, 'primary', 'heroicon-o-users');
        $stats[] = $this->makeStat('Торговых мест', 0, $note, null, 'gray', 'heroicon-o-home-modern');
        $stats[] = $this->makeStat('Занято мест', 0, $note, null, 'success', 'heroicon-o-check-circle');
        $stats[] = $this->makeStat('Свободно мест', 0, $note, null, 'warning', 'heroicon-o-sparkles');
        $stats[] = $this->makeStat('Заполняемость', '0 %', $note, null, 'gray', 'heroicon-o-chart-bar');
        $stats[] = $this->makeStat('Начислено за месяц', '0 ₽', $note, null, 'primary', 'heroicon-o-banknotes');
        $stats[] = $this->makeStat('Оплачено за месяц', '0 ₽', $note, null, 'success', 'heroicon-o-arrow-down-circle');
        $stats[] = $this->makeStat('К оплате за месяц', '0 ₽', $note, null, 'gray', 'heroicon-o-scale');

        return $stats;
    }

    private function makeStat(
        string $label,
        string|int $value,
        ?string $description = null,
        ?string $url = null,
        string|array|null $color = null,
        ?string $icon = null,
    ): Stat {
        $stat = Stat::make($label, is_int($value) ? number_format($value, 0, ',', ' ') : $value);

        if ($color !== null) {
            $stat->color($color);
        }

        if ($icon !== null) {
            $stat->icon($icon);
        }

        if (filled($description)) {
            $stat->description($description);
        }

        if ($url !== null) {
            $stat
                ->url($url)
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'title' => 'Открыть раздел',
                ]);

            if (filled($description)) {
                $stat->descriptionIcon('heroicon-m-arrow-top-right-on-square');
            }
        }

        return $stat;
    }

    private function appendQueryString(string $url, array $query): string
    {
        $queryString = http_build_query($query);

        if ($queryString === '') {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . $queryString;
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

    private function sumAccruedForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        return $this->sumMoneyFromAccruals($marketId, $monthYm, $start, $end, 'accrued');
    }

    private function sumPaidForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        return $this->sumMoneyFromAccruals($marketId, $monthYm, $start, $end, 'paid');
    }

    /**
     * @return array{rows:?int,accrued:?float,paid:?float,debt:?float,source:string}
     */
    private function resolveFinancialSummaryForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $debtSummary = $this->sumMoneyFromDebtSnapshots($marketId, $monthYm);

        if ($debtSummary['rows'] !== null && $debtSummary['rows'] > 0) {
            return $debtSummary + ['source' => '1С'];
        }

        return [
            'rows' => $this->countAccrualRowsForMonth($marketId, $monthYm, $start, $end),
            'accrued' => $this->sumAccruedForMonth($marketId, $monthYm, $start, $end),
            'paid' => $this->sumPaidForMonth($marketId, $monthYm, $start, $end),
            'debt' => null,
            'source' => 'витрина начислений',
        ];
    }

    /**
     * @return array{rows:?int,accrued:?float,paid:?float,debt:?float}
     */
    private function sumMoneyFromDebtSnapshots(int $marketId, string $monthYm): array
    {
        if (! Schema::hasTable('contract_debts')) {
            return ['rows' => null, 'accrued' => null, 'paid' => null, 'debt' => null];
        }

        try {
            $rows = DB::table('contract_debts')
                ->where('market_id', $marketId)
                ->where('period', $monthYm)
                ->orderBy('contract_external_id')
                ->orderByDesc('calculated_at')
                ->get([
                    'contract_external_id',
                    'accrued_amount',
                    'paid_amount',
                    'debt_amount',
                ]);
        } catch (\Throwable) {
            return ['rows' => null, 'accrued' => null, 'paid' => null, 'debt' => null];
        }

        $latestByContract = [];

        foreach ($rows as $row) {
            $contractExternalId = trim((string) ($row->contract_external_id ?? ''));

            if ($contractExternalId === '' || array_key_exists($contractExternalId, $latestByContract)) {
                continue;
            }

            $latestByContract[$contractExternalId] = $row;
        }

        if ($latestByContract === []) {
            return ['rows' => 0, 'accrued' => 0.0, 'paid' => 0.0, 'debt' => 0.0];
        }

        $accrued = 0.0;
        $paid = 0.0;
        $debt = 0.0;

        foreach ($latestByContract as $row) {
            $accrued += (float) ($row->accrued_amount ?? 0);
            $paid += (float) ($row->paid_amount ?? 0);
            $debt += (float) ($row->debt_amount ?? 0);
        }

        return [
            'rows' => count($latestByContract),
            'accrued' => $accrued,
            'paid' => $paid,
            'debt' => $debt,
        ];
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
