<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketRevenueYearChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Выручка за год (по месяцам)';

    protected function getType(): string
    {
        return 'bar'; // или 'line'
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

        $year = $this->resolveYear($tz);

        // 12 месяцев года
        $labels = [];
        $monthKeys = [];
        for ($m = 1; $m <= 12; $m++) {
            $d = CarbonImmutable::create($year, $m, 1, 0, 0, 0, $tz);
            $ym = $d->format('Y-m');
            $monthKeys[$ym] = $m - 1; // индекс
            $labels[] = $d->format('m.Y');
        }

        $series = array_fill(0, 12, 0.0);

        $values = $this->sumRevenueByMonth($marketId, $year);

        if ($values !== null) {
            foreach ($values as $ym => $sum) {
                if (isset($monthKeys[$ym])) {
                    $series[$monthKeys[$ym]] = (float) $sum;
                }
            }
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => (string) $year,
                    'data' => array_map(static fn (float $v) => round($v, 2), $series),
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

    private function resolveYear(string $tz): int
    {
        $raw = null;

        if (is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? $this->filters['period'] ?? $this->filters['dashboard_month'] ?? null;
        }

        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

        if (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return (int) substr($raw, 0, 4);
        }

        return (int) CarbonImmutable::now($tz)->format('Y');
    }

    /**
     * Возвращает ['YYYY-MM' => сумма], или null если таблицы/колонок недостаточно.
     * "Выручка" трактуется как PAID (если есть колонка оплат), иначе как PAYABLE.
     */
    private function sumRevenueByMonth(int $marketId, int $year): ?array
    {
        if (! Schema::hasTable('tenant_accruals')) {
            return null;
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];
        $types = $meta['types'];

        if (! in_array('market_id', $cols, true)) {
            return null;
        }

        $periodCol = $this->pickPeriodColumn($cols);
        if (! $periodCol) {
            return null;
        }

        // Оплачено (если есть) — это выручка
        $paidExpr = $this->buildPaidRowExpression($cols);
        $payableExpr = $this->buildPayableRowExpression($cols);

        $rowExpr = $paidExpr ?? $payableExpr;

        if ($rowExpr === null) {
            return null;
        }

        $type = strtoupper((string) ($types[$periodCol] ?? ''));

        $q = DB::table('tenant_accruals')->where('market_id', $marketId);

        // Фильтр года
        if ($type !== '' && str_contains($type, 'INT')) {
            $from = ($year * 100) + 1;
            $to = ($year * 100) + 12;
            $q->whereBetween($periodCol, [$from, $to]);

            $rows = $q
                ->selectRaw($periodCol . ' as p, SUM(' . $rowExpr . ') as v')
                ->groupBy('p')
                ->orderBy('p')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $p = (string) ($r->p ?? '');
                if (preg_match('/^\d{6}$/', $p)) {
                    $ym = substr($p, 0, 4) . '-' . substr($p, 4, 2);
                    $out[$ym] = (float) ($r->v ?? 0);
                }
            }

            return $out;
        }

        $lower = strtolower($periodCol);

        // DATE/DATETIME
        if (str_contains($lower, 'start') || str_contains($lower, 'date') || str_contains($lower, '_at')) {
            $startDate = $year . '-01-01';
            $endDate = ($year + 1) . '-01-01';

            $q->where($periodCol, '>=', $startDate)->where($periodCol, '<', $endDate);

            $driver = DB::getDriverName();

            // ключ месяца
            $monthExpr = $driver === 'sqlite'
                ? "strftime('%Y-%m', {$periodCol})"
                : "DATE_FORMAT({$periodCol}, '%Y-%m')";

            $rows = $q
                ->selectRaw($monthExpr . ' as ym, SUM(' . $rowExpr . ') as v')
                ->groupBy('ym')
                ->orderBy('ym')
                ->get();

            $out = [];
            foreach ($rows as $r) {
                $ym = (string) ($r->ym ?? '');
                if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
                    $out[$ym] = (float) ($r->v ?? 0);
                }
            }

            return $out;
        }

        // Строковые period: 'YYYY-MM' или 'YYYY-MM-DD'
        $q->where($periodCol, 'like', $year . '%');

        $driver = DB::getDriverName();

        // для sqlite substr работает
        $monthExpr = $driver === 'sqlite'
            ? "substr({$periodCol}, 1, 7)"
            : "LEFT({$periodCol}, 7)";

        $rows = $q
            ->selectRaw($monthExpr . ' as ym, SUM(' . $rowExpr . ') as v')
            ->groupBy('ym')
            ->orderBy('ym')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $ym = (string) ($r->ym ?? '');
            if (preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $out[$ym] = (float) ($r->v ?? 0);
            }
        }

        return $out;
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

                return ['columns' => $columns, 'types' => $types];
            }
        } catch (\Throwable) {
            // ignore
        }

        try {
            $columns = Schema::getColumnListing($table);
        } catch (\Throwable) {
            $columns = [];
        }

        return ['columns' => $columns, 'types' => $types];
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
     * Построчное выражение "оплачено" (без SUM) — для SUM(...) мы обернём снаружи.
     */
    private function buildPaidRowExpression(array $columns): ?string
    {
        $paidCol = $this->pickFirstExisting($columns, [
            'paid_amount',
            'payment_amount',
            'payments_amount',
            'amount_paid',
        ]);

        return $paidCol ? 'COALESCE("' . $paidCol . '", 0)' : null;
    }

    /**
     * Построчное выражение "начислено" (без SUM).
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

        return $parts === [] ? null : implode(' + ', $parts);
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
}
