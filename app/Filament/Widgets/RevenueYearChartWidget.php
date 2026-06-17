<?php
# app/Filament/Widgets/RevenueYearChartWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\Market;
use App\Support\AdminCapabilities;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use App\Support\MarketSpaces\MarketSpacePeriodEffectivenessService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RevenueYearChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardFilterMonth;

    protected ?string $heading = 'Начислено по 1С и заполняемость площади за 13 месяцев';

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 2];

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'line';
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) ($user->market_id ?? null)
        );
    }

    public function getDescription(): ?string
    {
        $user = Filament::auth()->user();
        if (! $user) {
            return null;
        }

        $marketId = $this->resolveMarketIdForWidget($user);
        if (! $marketId) {
            return null;
        }

        $market = Market::query()->select(['id', 'name', 'timezone'])->find($marketId);
        if (! $market) {
            return null;
        }

        $tz = $this->resolveTimezone($market->timezone);
        [$selectedYm] = $this->resolveEndMonth($tz, $marketId);
        $currentYm = CarbonImmutable::now($tz)->format('Y-m');
        $latestDebtYm = $this->resolveLatestDebtMonth($marketId);

        $parts = [
            'Период графика: до ' . $this->formatMonthLabel($selectedYm, $tz),
            'Заполняемость площади: по договорной истории',
        ];

        if ($selectedYm !== $currentYm) {
            $parts[] = 'Текущий месяц: ' . $this->formatMonthLabel($currentYm, $tz);
        }

        if ($latestDebtYm && $latestDebtYm !== $selectedYm) {
            $parts[] = 'Последние данные: ' . $this->formatMonthLabel($latestDebtYm, $tz);
        }

        if ($latestDebtYm && $latestDebtYm === $selectedYm && $selectedYm !== $currentYm) {
            $parts[] = 'Новых данных за ' . $this->formatMonthLabel($currentYm, $tz) . ' пока нет';
        }

        return implode(' • ', $parts);
    }

    protected function getData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->emptyChart('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);
        if (! $marketId) {
            return $this->emptyChart('Выбери рынок');
        }

        $market = Market::query()->select(['id', 'timezone'])->find($marketId);
        $tz = $this->resolveTimezone($market?->timezone);
        [, $endMonthStart] = $this->resolveEndMonth($tz, $marketId);

        $months = [];
        $cursor = $endMonthStart->subMonths(12);
        for ($i = 0; $i < 13; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        $labels = array_map(fn (string $ym): string => $this->formatMonthLabel($ym, $tz), $months);
        $areaData = app(MarketSpacePeriodEffectivenessService::class)
            ->areaOccupancyPercentSeries($marketId, $months, $tz);
        $payableData = Schema::hasTable('contract_debts')
            ? $this->buildPayableSeries($marketId, $months)
            : array_fill(0, count($months), null);

        $canViewFinance = AdminCapabilities::canViewFinance($user);
        $hasAreaData = $this->hasAnyNumericData($areaData);
        $hasPayableData = $this->hasAnyNumericData($payableData);

        if (! $hasAreaData && (! $canViewFinance || ! $hasPayableData)) {
            return $this->emptyChart('Нет данных для графика');
        }

        $datasets = [$this->lineDataset('Заполняемость площади', $areaData, 'y1', '#60a5fa')];

        if ($canViewFinance) {
            array_unshift($datasets, $this->lineDataset('К оплате', $payableData, 'y', '#fbbf24'));
        }

        return ['labels' => $labels, 'datasets' => $datasets];
    }

    protected function getOptions(): array
    {
        $canViewFinance = AdminCapabilities::canViewFinance(Filament::auth()->user());

        return [
            'responsive' => true,
            'maintainAspectRatio' => true,
            'aspectRatio' => 2.0,
            'layout' => ['padding' => 6],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'labels' => ['boxWidth' => 10, 'boxHeight' => 10, 'padding' => 10, 'font' => ['size' => 11]],
                ],
                'tooltip' => ['mode' => 'index', 'intersect' => false],
            ],
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'scales' => [
                'x' => [
                    'ticks' => ['font' => ['size' => 10], 'autoSkip' => false, 'maxRotation' => 0, 'minRotation' => 0],
                    'grid' => ['display' => false],
                ],
                'y' => [
                    'display' => $canViewFinance,
                    'beginAtZero' => true,
                    'position' => 'left',
                    'ticks' => ['font' => ['size' => 10]],
                ],
                'y1' => [
                    'beginAtZero' => true,
                    'position' => 'right',
                    'min' => 0,
                    'max' => 100,
                    'grid' => ['drawOnChartArea' => false],
                    'ticks' => ['font' => ['size' => 10]],
                ],
            ],
        ];
    }

    /**
     * @param  list<float|int|null>  $data
     * @return array<string, mixed>
     */
    private function lineDataset(string $label, array $data, string $axis, string $color): array
    {
        return [
            'label' => $label,
            'data' => $data,
            'yAxisID' => $axis,
            'tension' => 0.25,
            'fill' => false,
            'borderColor' => $color,
            'backgroundColor' => $color,
            'pointRadius' => 2,
            'borderWidth' => 2,
            'spanGaps' => false,
        ];
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $value = session('dashboard_market_id');

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id")
                ?? session("filament_{$panelId}_market_id");
        }

        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : $this->resolveDefaultMarketId();
    }

    private function resolveDefaultMarketId(): ?int
    {
        $marketId = Market::query()->orderBy('id')->value('id');

        return $marketId ? (int) $marketId : null;
    }

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone) ?: (string) config('app.timezone', 'UTC');

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    /**
     * @return array{0:string,1:CarbonImmutable}
     */
    private function resolveEndMonth(string $tz, ?int $marketId = null): array
    {
        $raw = $this->resolveDashboardFilterMonthRaw();

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                $raw = CarbonImmutable::createFromFormat('Y-m-d', $raw, $tz)->format('Y-m');
            } catch (\Throwable) {
                $raw = null;
            }
        }

        $ym = (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw))
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        if (! session('dashboard_month_explicit') && $marketId) {
            $latestDebtYm = $this->resolveLatestDebtMonth($marketId);

            if ($latestDebtYm && $latestDebtYm > $ym) {
                $ym = $latestDebtYm;
            }
        }

        return [$ym, $this->parseMonthStart($ym, $tz)];
    }

    private function formatMonthLabel(string $ym, string $tz): string
    {
        try {
            return $this->parseMonthStart($ym, $tz)->format('m.Y');
        } catch (\Throwable) {
            return $ym;
        }
    }

    private function parseMonthStart(string $ym, string $tz): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', $ym, $tz)->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->startOfMonth();
        }
    }

    protected function resolveLatestDebtMonth(int $marketId): ?string
    {
        if ($marketId <= 0 || ! Schema::hasTable('contract_debts')) {
            return null;
        }

        try {
            $value = DB::table('contract_debts')
                ->where('market_id', $marketId)
                ->orderByDesc('period')
                ->value('period');
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) ? $value : null;
    }

    private function emptyChart(string $label = 'Нет данных'): array
    {
        return ['labels' => [$label], 'datasets' => [['data' => [1]]]];
    }

    /**
     * @param  list<float|int|null>  $values
     */
    private function hasAnyNumericData(array $values): bool
    {
        foreach ($values as $value) {
            if ($value !== null && is_numeric($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $months
     * @return list<int|null>
     */
    private function buildPayableSeries(int $marketId, array $months): array
    {
        $accountingSpaceIds = MarketSpaceDashboardMetrics::accountingSpacesQuery($marketId)
            ->pluck('market_spaces.id')
            ->unique()
            ->values()
            ->all();

        $accountingSpaceIdSet = array_fill_keys(array_map('intval', $accountingSpaceIds), true);

        try {
            $rows = DB::table('contract_debts as d')
                ->leftJoin('tenant_contracts as tc', function ($join): void {
                    $join->on('tc.market_id', '=', 'd.market_id')
                        ->on('tc.external_id', '=', 'd.contract_external_id');
                })
                ->where('d.market_id', $marketId)
                ->whereIn('d.period', $months)
                ->orderBy('d.period')
                ->orderBy('d.contract_external_id')
                ->orderByDesc('d.calculated_at')
                ->select(['d.period', 'd.contract_external_id', 'd.accrued_amount', 'tc.market_space_id'])
                ->get();
        } catch (\Throwable) {
            return array_fill(0, count($months), null);
        }

        $latestByContractPeriod = [];

        foreach ($rows as $row) {
            $period = trim((string) ($row->period ?? ''));
            $contractExternalId = trim((string) ($row->contract_external_id ?? ''));

            if ($period === '' || $contractExternalId === '') {
                continue;
            }

            $key = $period . '|' . $contractExternalId;
            $latestByContractPeriod[$key] ??= $row;
        }

        $periodStats = [];

        foreach ($latestByContractPeriod as $row) {
            $period = trim((string) ($row->period ?? ''));
            $periodStats[$period] ??= ['rows' => 0, 'payable' => 0.0, 'spaces' => []];

            if ($row->market_space_id !== null) {
                $marketSpaceId = (int) $row->market_space_id;

                if (! isset($accountingSpaceIdSet[$marketSpaceId])) {
                    continue;
                }

                $periodStats[$period]['spaces'][$marketSpaceId] = true;
            }

            $periodStats[$period]['rows']++;
            $periodStats[$period]['payable'] += (float) ($row->accrued_amount ?? 0);
        }

        $totalSpaces = MarketSpaceDashboardMetrics::accountingSpacesQuery($marketId)->count();
        $periodStats = $this->filterLeadingIncompleteDebtPeriods($periodStats, $totalSpaces);

        $payableData = [];
        foreach ($months as $month) {
            $payableData[] = isset($periodStats[$month])
                ? (int) round($periodStats[$month]['payable'])
                : null;
        }

        return $payableData;
    }

    /**
     * @param  array<string, array{rows:int,payable:float,spaces:array<int,true>}>  $periodStats
     * @return array<string, array{rows:int,payable:float,spaces:array<int,true>}>
     */
    private function filterLeadingIncompleteDebtPeriods(array $periodStats, int $totalSpaces): array
    {
        if (count($periodStats) < 2 || $totalSpaces <= 0) {
            return $periodStats;
        }

        $maxRows = 0;
        $maxPayable = 0.0;
        $maxCoverage = 0.0;

        foreach ($periodStats as $stat) {
            $rows = (int) ($stat['rows'] ?? 0);
            $payable = (float) ($stat['payable'] ?? 0);
            $coverage = $this->calculateDebtCoveragePct($stat, $totalSpaces);

            $maxRows = max($maxRows, $rows);
            $maxPayable = max($maxPayable, $payable);
            $maxCoverage = max($maxCoverage, $coverage);
        }

        $rowsThreshold = $maxRows > 0 ? max(2, (int) ceil($maxRows * 0.2)) : 0;
        $payableThreshold = $maxPayable > 0 ? max(1.0, $maxPayable * 0.2) : 0.0;
        $coverageThreshold = $maxCoverage > 0 ? max(1.0, $maxCoverage * 0.2) : 0.0;
        $filtered = [];
        $stillLeading = true;

        foreach ($periodStats as $period => $stat) {
            $rows = (int) ($stat['rows'] ?? 0);
            $payable = (float) ($stat['payable'] ?? 0);
            $coverage = $this->calculateDebtCoveragePct($stat, $totalSpaces);

            if (
                $stillLeading
                && $rows > 0
                && $rows <= $rowsThreshold
                && $payable <= $payableThreshold
                && $coverage <= $coverageThreshold
            ) {
                continue;
            }

            $stillLeading = false;
            $filtered[$period] = $stat;
        }

        return $filtered;
    }

    /**
     * @param  array{rows:int,payable:float,spaces:array<int,true>}  $stat
     */
    private function calculateDebtCoveragePct(array $stat, int $totalSpaces): float
    {
        $spaces = isset($stat['spaces']) && is_array($stat['spaces'])
            ? count($stat['spaces'])
            : 0;

        return $totalSpaces > 0 ? round(($spaces / $totalSpaces) * 100, 1) : 0.0;
    }
}
