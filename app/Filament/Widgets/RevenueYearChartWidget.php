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

    protected ?string $heading = 'К оплате (начислено) и заполняемость за 13 месяцев';

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

        // Конец окна = выбранный "отчётный месяц"
        [, $endMonthStart] = $this->resolveEndMonth($tz);

        // 13 месяцев (включая выбранный)
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

        $periodMode = $this->detectPeriodMode('tenant_accruals', $marketCol, $periodCol, $marketId);

        // Payable (строчно): COALESCE(total_with_vat, sum(parts))
        $payableRowExpr = $this->buildPayableRowExpression($cols);

        if ($payableRowExpr === null) {
            return $this->emptyTwoSeriesChart($labels, count($months));
        }

        $payableSumExpr = 'COALESCE(SUM(' . $payableRowExpr . '), 0)';

        // Заполняемость: DISTINCT count(market_space_id) where payable > 0
        $spaceCol = $this->pickFirstExisting($cols, ['market_space_id', 'space_id']);
        $canComputeOccupancy = (bool) $spaceCol && $totalSpaces > 0;

        $payableData = [];
        $occupancyPctData = [];

        foreach ($months as $ym) {
            $startTz = CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->startOfMonth();
            $endTz = $startTz->addMonth();

            // --- Payable ---
            $qPayable = DB::table('tenant_accruals')->where($marketCol, $marketId);
            $this->applyMonthFilter($qPayable, $periodCol, $ym, $startTz, $endTz, $periodMode);

            try {
                $v = $qPayable->selectRaw($payableSumExpr . ' as v')->value('v');
                $payableData[] = (int) round(is_numeric($v) ? (float) $v : 0.0);
            } catch (\Throwable) {
                $payableData[] = 0;
            }

            // --- Occupancy % ---
            if (! $canComputeOccupancy) {
                $occupancyPctData[] = 0.0;
                continue;
            }

            $qOcc = DB::table('tenant_accruals')->where($marketCol, $marketId);
            $this->applyMonthFilter($qOcc, $periodCol, $ym, $startTz, $endTz, $periodMode);

            // payable > 0 по той же логике, что и начислено
            $qOcc->whereRaw('(' . $payableRowExpr . ') > 0');

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
                    'label' => 'К оплате (начислено)',
                    'data' => $payableData,
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

        // Filament page filters
        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['month'] ?? null;
        }

        // fallback
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
                    'label' => 'К оплате (начислено)',
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
        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            $columns = [];
        }

        return [
            'columns' => $columns,
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

    /**
     * Определяем, как хранится period:
     * - ym_int: 202601
     * - ym_string: "2026-01"
     * - date: "2026-01-01"
     * - datetime: "2025-12-31 17:00:00+00" / "...T17:00:00Z"
     */
    private function detectPeriodMode(string $table, string $marketCol, string $periodCol, int $marketId): string
    {
        try {
            $sample = DB::table($table)
                ->where($marketCol, $marketId)
                ->whereNotNull($periodCol)
                ->orderByDesc($periodCol)
                ->value($periodCol);
        } catch (\Throwable) {
            $sample = null;
        }

        if (is_int($sample) || (is_string($sample) && preg_match('/^\d{6}$/', trim($sample)))) {
            return 'ym_int';
        }

        if (is_string($sample)) {
            $s = trim($sample);

            if (preg_match('/^\d{4}-\d{2}$/', $s)) {
                return 'ym_string';
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                return 'date';
            }

            // есть время/таймзона
            if (preg_match('/^\d{4}-\d{2}-\d{2}[T\s]\d{2}:\d{2}:\d{2}/', $s)) {
                return 'datetime';
            }
        }

        // безопасный дефолт для Postgres/UTC-периодов
        return 'datetime';
    }

    private function applyMonthFilter(
        Builder $q,
        string $periodCol,
        string $monthYm,
        CarbonImmutable $startTz,
        CarbonImmutable $endTz,
        string $mode
    ): void {
        if ($mode === 'ym_int') {
            $q->where($periodCol, (int) str_replace('-', '', $monthYm));
            return;
        }

        if ($mode === 'ym_string') {
            $q->where($periodCol, $monthYm);
            return;
        }

        if ($mode === 'date') {
            $q->where($periodCol, '>=', $startTz->toDateString())
              ->where($periodCol, '<', $endTz->toDateString());
            return;
        }

        // datetime: критично для period типа "...17:00:00Z" (TZ Asia/Barnaul)
        $startUtc = $startTz->utc()->toDateTimeString();
        $endUtc = $endTz->utc()->toDateTimeString();

        $q->where($periodCol, '>=', $startUtc)
          ->where($periodCol, '<', $endUtc);
    }

    /**
     * Строчное payable выражение:
     * COALESCE(total_with_vat, rent + utilities + management_fee + ...)
     */
    private function buildPayableRowExpression(array $columns): ?string
    {
        $totalCol = $this->pickFirstExisting($columns, [
            'total_with_vat',
            'total_with_tax',
            'total_with_nds',
            'total_amount',
            'payable_total',
            'amount_total',
            'total',
        ]);

        $parts = [];

        foreach ([
            'rent_amount',
            'utility_amount',
            'utilities_amount',
            'management_fee',
            'management_fee_amount',
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

        $partsExpr = $parts !== [] ? implode(' + ', $parts) : null;

        if ($totalCol && $partsExpr) {
            return 'COALESCE("' . $totalCol . '", (' . $partsExpr . '))';
        }

        if ($totalCol) {
            return 'COALESCE("' . $totalCol . '", 0)';
        }

        return $partsExpr ? '(' . $partsExpr . ')' : null;
    }
}