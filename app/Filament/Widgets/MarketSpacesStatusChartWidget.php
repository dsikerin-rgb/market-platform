<?php
# app/Filament/Widgets/MarketSpacesStatusChartWidget.php

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

class MarketSpacesStatusChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Заполняемость торговых мест за месяц';

    protected function getType(): string
    {
        return 'pie';
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) $user->market_id
        );
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

        [$monthYm, $monthStart, $monthEnd, $periodLabel] = $this->resolveMonthRange($tz);

        $totalSpaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->count();

        if ($totalSpaces <= 0) {
            return $this->emptyChart($periodLabel);
        }

        $occupiedSpaces = $this->countOccupiedSpacesForMonth($marketId, $monthYm, $monthStart, $monthEnd);

        // fallback на "текущее" состояние, если tenant_accruals недоступен или колонок не хватает
        if ($occupiedSpaces === null) {
            $occupiedSpaces = MarketSpace::query()
                ->where('market_id', $marketId)
                ->where('status', 'occupied')
                ->count();
        }

        $freeSpaces = max($totalSpaces - $occupiedSpaces, 0);

        if (($freeSpaces + $occupiedSpaces) === 0) {
            return $this->emptyChart($periodLabel);
        }

        return [
            'labels' => ['Свободно', 'Занято'],
            'datasets' => [
                [
                    'data' => [(int) $freeSpaces, (int) $occupiedSpaces],
                ],
            ],
        ];
    }

    protected function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $value = session('dashboard_market_id');

        if (blank($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament_{$panelId}_market_id");
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

        $label = $start->format('m.Y') . ' (TZ: ' . $tz . ')';

        return [$monthYm, $start, $end, $label];
    }

    private function countOccupiedSpacesForMonth(int $marketId, string $monthYm, CarbonImmutable $start, CarbonImmutable $end): ?int
    {
        if (! Schema::hasTable('tenant_accruals')) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        $spaceCol  = $this->pickFirstExisting($cols, ['market_space_id', 'space_id']);
        $periodCol = $this->pickPeriodColumn($cols);

        if (! $marketCol || ! $spaceCol || ! $periodCol) {
            return null;
        }

        $rentCol = $this->pickFirstExisting($cols, ['rent_amount']);

        // ВАЖНО: для WHERE нужно построчное выражение (без SUM)
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
     * Построчное выражение суммы начислений для WHERE (без SUM).
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
}
