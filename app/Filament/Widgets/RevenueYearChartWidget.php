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
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RevenueYearChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Выручка (начислено) и заполняемость за 13 месяцев';

    /**
     * Хотим 2/3 ширины на десктопе: при сетке Dashboard=3 колонки это будет ровно "2 колонки".
     * На мобилке/планшете — пусть будет на всю ширину.
     */
    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    /**
     * ChartWidget::$maxHeight — НЕ static.
     * Делаем виджет ниже.
     */
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

    /**
     * “Локация” под заголовком (как контекст/легенда).
     */
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

        return 'Локация: ' . (string) $market->name . ' • TZ: ' . $tz;
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

        /**
         * 13 месяцев (включая выбранный): стабильная генерация без дублей.
         * Стартуем с (end - 12) и идём вперёд 13 шагов.
         */
        $months = [];
        $cursor = $endMonthStart->subMonths(12);
        for ($i = 0; $i < 13; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        $labels = array_map(fn (string $ym) => $this->formatMonthLabel($ym, $tz), $months);

        $totalSpaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->count();

        if (! Schema::hasTable('tenant_accruals')) {
            return $this->emptyTwoSeriesChart($labels, count($months));
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        $periodCol = $this->pickPeriodColumn($cols);

        if (! $marketCol || ! $periodCol) {
            return $this->emptyTwoSeriesChart($labels, count($months));
        }

        // Выручка: SUM(...)
        $sumExpr = $this->buildPayableSumExpression($cols);
        if ($sumExpr === null) {
            return $this->emptyTwoSeriesChart($labels, count($months));
        }

        // Заполняемость: DISTINCT count(space_id) where payable > 0
        $spaceCol = $this->pickFirstExisting($cols, ['market_space_id', 'space_id']);
        $rentCol  = $this->pickFirstExisting($cols, ['rent_amount']);
        $payableRowExpr = $this->buildPayableRowExpression($cols);

        $canComputeOccupancy = (bool) $spaceCol && (bool) ($rentCol || $payableRowExpr);

        $revenueData = [];
        $occupancyPctData = [];

        foreach ($months as $ym) {
            $start = CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->startOfMonth();
            $end = $start->addMonth();

            // --- Revenue ---
            $qRevenue = DB::table('tenant_accruals')->where($marketCol, $marketId);
            $this->applyMonthFilter($qRevenue, $meta, $periodCol, $ym, $start, $end);

            try {
                $v = $qRevenue->selectRaw($sumExpr . ' as v')->value('v');
                $revenueData[] = (int) round(is_numeric($v) ? (float) $v : 0.0);
            } catch (\Throwable) {
                $revenueData[] = 0;
            }

            // --- Occupancy % ---
            if (! $canComputeOccupancy || $totalSpaces <= 0) {
                $occupancyPctData[] = 0.0;
                continue;
            }

            $qOcc = DB::table('tenant_accruals')->where($marketCol, $marketId);
            $this->applyMonthFilter($qOcc, $meta, $periodCol, $ym, $start, $end);

            if ($rentCol) {
                $qOcc->where($rentCol, '>', 0);
            } else {
                $qOcc->whereRaw('(' . $payableRowExpr . ') > 0');
            }

            try {
                $occupied = (int) $qOcc->distinct()->count($spaceCol);
            } catch (\Throwable) {
                $occupied = 0;
            }

            $pct = ($totalSpaces > 0) ? ($occupied / $totalSpaces) * 100 : 0;
            $occupancyPctData[] = (float) round($pct, 1);
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Начислено',
                    'data' => $revenueData,
                    'yAxisID' => 'y',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#fbbf24',
                    'backgroundColor' => '#fbbf24',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Заполняемость, %',
                    'data' => $occupancyPctData,
                    'yAxisID' => 'y1',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#60a5fa',
                    'backgroundColor' => '#60a5fa',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                ],
            ],
        ];
    }

    protected function getOptions(): array
{
    return [
        'responsive' => true,
        'maintainAspectRatio' => true,

        // было очень плоско — делаем выше (подстройка):
        // 2.2–2.4 = чуть выше, 1.8–2.0 = заметно выше (~+40%)
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
                    'autoSkip' => true,
                    'maxTicksLimit' => 13,
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

        return filled($value) ? (int) $value : null;
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

    private function emptyChart(string $label = 'Нет данных'): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'data' => [1],
                ],
            ],
        ];
    }

    private function emptyTwoSeriesChart(array $labels, int $count): array
    {
        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Начислено',
                    'data' => array_fill(0, $count, 0),
                    'yAxisID' => 'y',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#fbbf24',
                    'backgroundColor' => '#fbbf24',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Заполняемость, %',
                    'data' => array_fill(0, $count, 0),
                    'yAxisID' => 'y1',
                    'tension' => 0.25,
                    'fill' => false,
                    'borderColor' => '#60a5fa',
                    'backgroundColor' => '#60a5fa',
                    'pointRadius' => 2,
                    'borderWidth' => 2,
                ],
            ],
        ];
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
        if (
            str_contains($type, 'DATE')
            || str_contains($type, 'TIME')
            || str_contains($lower, 'start')
            || str_contains($lower, 'date')
            || str_contains($lower, '_at')
        ) {
            $q->where($periodCol, '>=', $startDate)->where($periodCol, '<', $endDate);
            return;
        }

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

        return $parts === [] ? null : implode(' + ', $parts);
    }

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

        return $parts === [] ? null : implode(' + ', $parts);
    }
}
