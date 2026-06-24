<?php
# app/Filament/Widgets/MarketOverviewStatsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketResource;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantResource;
use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\ContractDebt;
use App\Models\Market;
use App\Support\AdminCapabilities;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
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
    use ResolvesDashboardFilterMonth;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = null;

    protected ?string $heading = null;

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->buildEmptyStats();
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $marketId = $this->resolveMarketIdForWidget($user);
        $canViewFinance = AdminCapabilities::canViewFinance($user, $marketId);

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

        $spaceMetrics = MarketSpaceDashboardMetrics::summarize($marketId);
        $totalSpaces = (int) $spaceMetrics['total_spaces'];
        $totalArea = (float) $spaceMetrics['total_area_sqm'];
        $occupiedSpaces = (int) $spaceMetrics['occupied_spaces'];
        $occupiedArea = (float) $spaceMetrics['occupied_area_sqm'];
        $freeSpaces = (int) $spaceMetrics['vacant_spaces'];
        $freeArea = (float) $spaceMetrics['vacant_area_sqm'];
        $maintenanceSpaces = (int) $spaceMetrics['maintenance_spaces'];
        $maintenanceArea = (float) $spaceMetrics['maintenance_area_sqm'];
        $rentableArea = (float) $spaceMetrics['rentable_area_sqm'];
        $leasedSpaces = $occupiedSpaces + $maintenanceSpaces;
        $leasedArea = $occupiedArea + $maintenanceArea;
        $rentableAreaWithService = $rentableArea + $maintenanceArea;
        $tenantsNow = MarketSpaceDashboardMetrics::countCurrentTenants($marketId);

        $tenantsUrl = TenantResource::getUrl('index');
        $spacesUrl = MarketSpaceResource::getUrl('index');
        $vacantSpacesUrl = $this->appendQueryString($spacesUrl, [
            'tab' => 'vacant',
            'tableFilters' => [
                'effective_occupancy' => ['value' => 'vacant'],
            ],
        ]);
        $maintenanceSpacesUrl = $this->appendQueryString($spacesUrl, [
            'only_maintenance' => 1,
            'tableFilters' => [
                'status' => ['value' => 'maintenance'],
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

        $marketScopeDesc = $isSuperAdmin ? 'На выбранном рынке' : 'На вашем рынке';
        $accountingScopeDesc = $marketScopeDesc . ' · ' . number_format($totalSpaces, 0, ',', ' ') . ' учётных мест';
        $occupancyRate = $rentableAreaWithService > 0
            ? round(($leasedArea / $rentableAreaWithService) * 100)
            : 0;
        $occupancyDesc = $rentableAreaWithService > 0
            ? 'Сдано ' . $this->formatArea($leasedArea) . ' из ' . $this->formatArea($rentableAreaWithService) . ' арендуемых м²'
            : 'На рынке пока нет учётных мест';
        $leasedDesc = 'Арендаторы: ' . $this->formatArea($occupiedArea) . ' · ' . number_format($occupiedSpaces, 0, ',', ' ') . ' шт.';

        if ($maintenanceSpaces > 0) {
            $occupancyDesc .= ' · в том числе УК: ' . $this->formatArea($maintenanceArea);
            $leasedDesc .= ' · УК: ' . $this->formatArea($maintenanceArea) . ' · ' . number_format($maintenanceSpaces, 0, ',', ' ') . ' шт.';
        }

        $stats[] = $this->makeStat(
            label: 'Текущие арендаторы',
            value: $tenantsNow,
            description: $marketScopeDesc,
            url: $tenantsUrl,
            color: 'primary',
            icon: 'heroicon-o-users',
        );
        $stats[] = $this->makeStat(
            label: 'Площадь фонда',
            value: $this->formatArea($totalArea),
            description: $accountingScopeDesc,
            url: $spacesUrl,
            color: 'gray',
            icon: 'heroicon-o-home-modern',
        );
        $stats[] = $this->makeStat(
            label: 'Сдано, м²',
            value: $this->formatArea($leasedArea),
            description: $leasedDesc,
            url: $spacesUrl,
            color: 'success',
            icon: 'heroicon-o-check-circle',
            linkTitle: 'Открыть фонд мест',
        );
        $stats[] = $this->makeStat(
            label: 'Свободные места, м²',
            value: $this->formatArea($freeArea),
            description: 'Фильтр: свободные места · ' . number_format($freeSpaces, 0, ',', ' ') . ' шт.',
            url: $vacantSpacesUrl,
            color: 'warning',
            icon: 'heroicon-o-sparkles',
            linkTitle: 'Открыть фактически свободные места',
        );
        $stats[] = $this->makeStat(
            label: 'Служебные места, м²',
            value: $this->formatArea($maintenanceArea),
            description: 'Фильтр: служебные места · ' . number_format($maintenanceSpaces, 0, ',', ' ') . ' шт.',
            url: $maintenanceSpacesUrl,
            color: 'gray',
            icon: 'heroicon-o-wrench-screwdriver',
            linkTitle: 'Открыть служебные места',
        );
        $stats[] = $this->makeStat(
            label: 'Заполняемость',
            value: $occupancyRate . ' %',
            description: $occupancyDesc,
            url: null,
            color: $leasedSpaces > 0 ? 'success' : 'gray',
            icon: 'heroicon-o-chart-bar',
        );
        if ($canViewFinance) {
            $averageRate = $spaceMetrics['average_rent_rate_per_sqm'];
            $pricedArea = (float) ($spaceMetrics['priced_area_sqm'] ?? 0);

            // Финансовая/отчётная часть зависит от выбранного месяца.
            [$monthYm, $monthStart, $monthEnd] = $this->resolveFinancialMonthRange($marketId, $tz);
            $monthLabel = $this->formatMonthLabel($monthYm, $tz);

            $financialSummary = $this->resolveFinancialSummaryForMonth($marketId, $monthYm, $monthStart, $monthEnd);
            $reportRows = $financialSummary['rows'];
            $hasReportData = is_int($reportRows) && $reportRows > 0;

            $accruedValue = $financialSummary['accrued'] ?? 0.0;
            $paidValue = $financialSummary['paid'] ?? 0.0;
            $debtValue = $financialSummary['debt'] ?? ($accruedValue - $paidValue);
            $reportDesc = $hasReportData
                ? ($monthLabel . ' · ' . $financialSummary['source'])
                : ($monthLabel . ' · нет данных 1С за выбранный месяц');

            $stats[] = $this->makeStat(
                label: 'Средняя ставка, ₽/м²',
                value: is_numeric($averageRate) && $averageRate > 0
                    ? $this->formatMoney((float) $averageRate) . ' ₽'
                    : '—',
                description: is_numeric($averageRate) && $averageRate > 0
                    ? 'Взвешено по ' . $this->formatArea($pricedArea) . ' с заданной ставкой'
                    : 'Нет данных по ставкам',
                url: $spacesUrl,
                color: is_numeric($averageRate) && $averageRate > 0 ? 'primary' : 'gray',
                icon: 'heroicon-o-banknotes',
            );
            $stats[] = $this->makeStat(
                label: 'Начислено за месяц',
                value: $this->formatMoney($accruedValue) . ' ₽',
                description: $reportDesc,
                url: null,
                color: 'primary',
                icon: 'heroicon-o-banknotes',
            );
            $stats[] = $this->makeStat(
                label: 'Оплачено за месяц',
                value: $this->formatMoney($paidValue) . ' ₽',
                description: $reportDesc,
                url: null,
                color: 'success',
                icon: 'heroicon-o-arrow-down-circle',
            );
            $stats[] = $this->makeStat(
                label: 'Долг на конец месяца',
                value: $this->formatMoney($debtValue) . ' ₽',
                description: $reportDesc,
                url: null,
                color: $debtValue > 0 ? 'danger' : 'success',
                icon: 'heroicon-o-scale',
            );
        }

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

        $stats[] = $this->makeStat('Текущие арендаторы', 0, $note, null, 'primary', 'heroicon-o-users');
        $stats[] = $this->makeStat('Площадь фонда', '0 м²', $note, null, 'gray', 'heroicon-o-home-modern');
        $stats[] = $this->makeStat('Сдано, м²', '0 м²', $note, null, 'success', 'heroicon-o-check-circle');
        $stats[] = $this->makeStat('Свободные места, м²', '0 м²', $note, null, 'warning', 'heroicon-o-sparkles');
        $stats[] = $this->makeStat('Служебные места, м²', '0 м²', $note, null, 'gray', 'heroicon-o-wrench-screwdriver');
        $stats[] = $this->makeStat('Заполняемость', '0 %', $note, null, 'gray', 'heroicon-o-chart-bar');
        $stats[] = $this->makeStat('Средняя ставка, ₽/м²', '—', $note, null, 'gray', 'heroicon-o-banknotes');
        $stats[] = $this->makeStat('Начислено за месяц', '0 ₽', $note, null, 'primary', 'heroicon-o-banknotes');
        $stats[] = $this->makeStat('Оплачено за месяц', '0 ₽', $note, null, 'success', 'heroicon-o-arrow-down-circle');
        $stats[] = $this->makeStat('Долг на конец месяца', '0 ₽', $note, null, 'gray', 'heroicon-o-scale');

        return $stats;
    }

    private function makeStat(
        string $label,
        string|int $value,
        ?string $description = null,
        ?string $url = null,
        string|array|null $color = null,
        ?string $icon = null,
        ?string $linkTitle = null,
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
                    'title' => $linkTitle ?? 'Открыть раздел',
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

    /**
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable}
     */
    private function resolveFinancialMonthRange(int $marketId, string $tz): array
    {
        [$monthYm, $monthStart, $monthEnd] = $this->resolveMonthRange($tz);

        if (session('dashboard_month_explicit')) {
            return [$monthYm, $monthStart, $monthEnd];
        }

        $latestDebtMonth = $this->resolveLatestDebtMonth($marketId);

        if ($latestDebtMonth && $latestDebtMonth !== $monthYm) {
            $latestMonthStart = CarbonImmutable::createFromFormat('Y-m', $latestDebtMonth, $tz)->startOfMonth();

            return [
                $latestDebtMonth,
                $latestMonthStart,
                $latestMonthStart->addMonth(),
            ];
        }

        return [$monthYm, $monthStart, $monthEnd];
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

    protected function resolveLatestDebtMonth(int $marketId): ?string
    {
        if ($marketId <= 0 || ! Schema::hasTable('contract_debts')) {
            return null;
        }

        try {
            $value = ContractDebt::query()
                ->where('market_id', $marketId)
                ->orderByDesc('period')
                ->value('period');
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)
            ? $value
            : null;
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
        $raw = $this->resolveDashboardFilterMonthRaw();

        // 1) Filament page filters (главное)
        if (! $raw && property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
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
            'rows' => 0,
            'accrued' => 0.0,
            'paid' => 0.0,
            'debt' => 0.0,
            'source' => '1С',
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
            $base = DB::query()
                ->fromSub(ContractDebt::currentStateQuery($marketId), 'cd')
                ->where('period', $monthYm)
                ->selectRaw('COUNT(*) as rows_count')
                ->selectRaw('COALESCE(SUM(accrued_amount), 0) as accrued_sum')
                ->selectRaw('COALESCE(SUM(paid_amount), 0) as paid_sum')
                ->selectRaw('COALESCE(SUM(debt_amount), 0) as debt_sum');

            $row = $base->first();
        } catch (\Throwable) {
            return ['rows' => null, 'accrued' => null, 'paid' => null, 'debt' => null];
        }

        if (! $row || (int) ($row->rows_count ?? 0) === 0) {
            return ['rows' => 0, 'accrued' => 0.0, 'paid' => 0.0, 'debt' => 0.0];
        }

        return [
            'rows' => (int) ($row->rows_count ?? 0),
            'accrued' => (float) ($row->accrued_sum ?? 0),
            'paid' => (float) ($row->paid_sum ?? 0),
            'debt' => (float) ($row->debt_sum ?? 0),
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

    private function formatArea(float $value): string
    {
        $precision = abs($value - round($value)) < 0.01 ? 0 : 1;

        return number_format($value, $precision, ',', ' ') . ' м²';
    }
}
