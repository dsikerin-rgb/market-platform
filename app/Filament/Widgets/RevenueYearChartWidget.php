<?php
# app/Filament/Widgets/RevenueYearChartWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\MarketSpace;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RevenueYearChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Начислено по 1С и охват мест за 13 месяцев';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

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

        $market = Market::query()
            ->select(['id', 'name', 'timezone'])
            ->find($marketId);

        if (! $market) {
            return null;
        }

        $tz = $this->resolveTimezone($market->timezone);
        [$selectedYm] = $this->resolveEndMonth($tz);
        $currentYm = CarbonImmutable::now($tz)->format('Y-m');
        $latestDebtYm = $this->resolveLatestDebtMonth($marketId);

        $parts = [
            'Локация: ' . (string) $market->name,
            'TZ: ' . $tz,
            'Источник: 1С',
            'Период графика: до ' . $this->formatMonthLabel($selectedYm, $tz),
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

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);

        [, $endMonthStart] = $this->resolveEndMonth($tz);

        $months = [];
        $cursor = $endMonthStart->subMonths(12);
        for ($i = 0; $i < 13; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        $labels = array_map(
            fn (string $ym): string => $this->formatMonthLabel($ym, $tz),
            $months
        );

        $totalSpaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->count();

        if (! Schema::hasTable('contract_debts')) {
            return $this->emptyTwoSeriesChart($labels, count($months));
        }

        // Строим данные для всех 13 месяцев (пустые месяцы будут с null)
        [$payableData, $coveragePctData] = $this->buildDebtSeries($marketId, $months, $totalSpaces);

        // Проверяем, есть ли хоть какие-то данные
        $hasAnyData = false;
        foreach ($payableData as $value) {
            if ($value !== null) {
                $hasAnyData = true;
                break;
            }
        }

        if (! $hasAnyData) {
            return $this->emptyChart('Нет данных 1С за выбранный период');
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'К оплате (1С)',
                    'data' => $payableData,
                    'yAxisID' => 'y',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#fbbf24',
                    'backgroundColor' => '#fbbf24',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Мест в 1С-контуре, %',
                    'data' => $coveragePctData,
                    'yAxisID' => 'y1',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#60a5fa',
                    'backgroundColor' => '#60a5fa',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                    'spanGaps' => false,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => true,
            'aspectRatio' => 2.0,
            'layout' => [
                'padding' => 6,
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'labels' => [
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'padding' => 10,
                        'font' => ['size' => 11],
                    ],
                ],
                'tooltip' => [
                    'mode' => 'index',
                    'intersect' => false,
                ],
            ],
            'interaction' => [
                'mode' => 'index',
                'intersect' => false,
            ],
            'scales' => [
                'x' => [
                    'ticks' => [
                        'font' => ['size' => 10],
                        'autoSkip' => false,
                        'maxRotation' => 0,
                        'minRotation' => 0,
                    ],
                    'grid' => ['display' => false],
                ],
                'y' => [
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

        if (filled($value)) {
            return (int) $value;
        }

        return $this->resolveDefaultMarketId();
    }

    private function resolveDefaultMarketId(): ?int
    {
        $marketId = Market::query()
            ->orderBy('id')
            ->value('id');

        return $marketId ? (int) $marketId : null;
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
     * @return array{0:string,1:CarbonImmutable}
     */
    private function resolveEndMonth(string $tz): array
    {
        $raw = null;

        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['month'] ?? null;
        }

        if (! $raw && is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? null;
        }

        $raw = $raw ?: session('dashboard_month');

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

        $start = CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->startOfMonth();

        return [$ym, $start];
    }

    private function formatMonthLabel(string $ym, string $tz): string
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->format('m.Y');
        } catch (\Throwable) {
            return $ym;
        }
    }

    private function resolveLatestDebtMonth(int $marketId): ?string
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

        return is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)
            ? $value
            : null;
    }

    private function emptyChart(string $label = 'Нет данных'): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                ['data' => [1]],
            ],
        ];
    }

    private function emptyTwoSeriesChart(array $labels, int $count): array
    {
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'К оплате (1С)',
                    'data' => array_fill(0, $count, null),
                    'yAxisID' => 'y',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#fbbf24',
                    'backgroundColor' => '#fbbf24',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Мест в 1С-контуре, %',
                    'data' => array_fill(0, $count, null),
                    'yAxisID' => 'y1',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#60a5fa',
                    'backgroundColor' => '#60a5fa',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                    'spanGaps' => false,
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $months
     * @return array{0: list<int|null>, 1: list<float|null>}
     */
    private function buildDebtSeries(int $marketId, array $months, int $totalSpaces): array
    {
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
                ->select([
                    'd.period',
                    'd.contract_external_id',
                    'd.accrued_amount',
                    'tc.market_space_id',
                ])
                ->get();
        } catch (\Throwable) {
            return [
                array_fill(0, count($months), null),
                array_fill(0, count($months), null),
            ];
        }

        $latestByContractPeriod = [];

        foreach ($rows as $row) {
            $period = trim((string) ($row->period ?? ''));
            $contractExternalId = trim((string) ($row->contract_external_id ?? ''));

            if ($period === '' || $contractExternalId === '') {
                continue;
            }

            $key = $period . '|' . $contractExternalId;

            if (! array_key_exists($key, $latestByContractPeriod)) {
                $latestByContractPeriod[$key] = $row;
            }
        }

        $periodStats = [];

        foreach ($latestByContractPeriod as $row) {
            $period = trim((string) ($row->period ?? ''));

            if (! isset($periodStats[$period])) {
                $periodStats[$period] = [
                    'rows' => 0,
                    'payable' => 0.0,
                    'spaces' => [],
                ];
            }

            $periodStats[$period]['rows']++;
            $periodStats[$period]['payable'] += (float) ($row->accrued_amount ?? 0);

            if ($row->market_space_id !== null) {
                $periodStats[$period]['spaces'][(int) $row->market_space_id] = true;
            }
        }

        $periodStats = $this->filterLeadingIncompleteDebtPeriods($periodStats, $totalSpaces);

        $payableData = [];
        $coveragePctData = [];

        foreach ($months as $month) {
            if (! isset($periodStats[$month])) {
                $payableData[] = null;
                $coveragePctData[] = null;
                continue;
            }

            $payableData[] = (int) round($periodStats[$month]['payable']);

            if ($totalSpaces <= 0) {
                $coveragePctData[] = null;
                continue;
            }

            $occupied = count($periodStats[$month]['spaces']);
            $coveragePctData[] = round(($occupied / $totalSpaces) * 100, 1);
        }

        $coveragePctData = $this->nullLeadingZeroCoveragePoints($coveragePctData);

        return [$payableData, $coveragePctData];
    }

    /**
     * The earliest debt snapshots can be partial and look like tiny but real values.
     * Keep the leading outlier months out of the chart so we do not misrepresent them as final data.
     *
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
        if ($totalSpaces <= 0) {
            return 0.0;
        }

        $spaces = isset($stat['spaces']) && is_array($stat['spaces'])
            ? count($stat['spaces'])
            : 0;

        return round(($spaces / $totalSpaces) * 100, 1);
    }

    /**
     * If the first visible coverage points are exact zeros, but later months do have
     * positive coverage, treat those zeros as incomplete data rather than a real 0%.
     *
     * @param  list<float|null>  $coveragePctData
     * @return list<float|null>
     */
    private function nullLeadingZeroCoveragePoints(array $coveragePctData): array
    {
        $firstPositiveIndex = null;

        foreach ($coveragePctData as $index => $value) {
            if (is_numeric($value) && (float) $value > 0.0) {
                $firstPositiveIndex = $index;
                break;
            }
        }

        if ($firstPositiveIndex === null) {
            return $coveragePctData;
        }

        for ($i = 0; $i < $firstPositiveIndex; $i++) {
            if (isset($coveragePctData[$i]) && is_numeric($coveragePctData[$i]) && (float) $coveragePctData[$i] <= 0.0) {
                $coveragePctData[$i] = null;
            }
        }

        return $coveragePctData;
    }
}
