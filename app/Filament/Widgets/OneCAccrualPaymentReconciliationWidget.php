<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCAccrualPaymentReconciliationWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardFilterMonth;

    protected ?string $heading = 'Начислено / оплачено по 1С';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 2,
    ];

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
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
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);
        [$endYm] = $this->resolveEndMonth($tz, $marketId);

        return '13 месяцев до ' . $this->formatMonthLabel($endYm, $tz) . ' • источник: обороты ОСВ 1С по счету 62';
    }

    protected function getData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->emptyChart('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return $this->emptyChart('Выберите рынок');
        }

        if (
            ! Schema::hasTable('tenant_settlement_balances')
            && ! Schema::hasTable('tenant_accruals')
            && ! Schema::hasTable('tenant_payments')
        ) {
            return $this->emptyChart('Нет данных 1С');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);
        [, $endMonthStart] = $this->resolveEndMonth($tz, $marketId);

        $months = [];
        $cursor = $endMonthStart->subMonths(12);

        for ($i = 0; $i < 13; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        $startDate = $months[0] . '-01';
        $endDate = $endMonthStart->addMonth()->format('Y-m-d');
        $settlementTurnoversByMonth = $this->loadSettlementTurnoversByMonth($marketId, $startDate, $endDate);
        $accrualsByMonth = $settlementTurnoversByMonth !== []
            ? array_map(static fn (array $row): float => (float) ($row['accrued'] ?? 0.0), $settlementTurnoversByMonth)
            : $this->loadAccrualsByMonth($marketId, $startDate, $endDate);
        $paymentsByMonth = $settlementTurnoversByMonth !== []
            ? array_map(static fn (array $row): float => (float) ($row['paid'] ?? 0.0), $settlementTurnoversByMonth)
            : $this->loadPaymentsByMonth($marketId, $startDate, $endDate);

        $hasAnyData = false;
        $accruedData = [];
        $paidData = [];
        $deltaData = [];

        foreach ($months as $month) {
            $accrued = round((float) ($accrualsByMonth[$month] ?? 0.0), 2);
            $paid = round((float) ($paymentsByMonth[$month] ?? 0.0), 2);
            $delta = round($accrued - $paid, 2);

            $accruedData[] = $accrued;
            $paidData[] = $paid;
            $deltaData[] = $delta;

            if (abs($accrued) > 0.009 || abs($paid) > 0.009) {
                $hasAnyData = true;
            }
        }

        if (! $hasAnyData) {
            return $this->emptyChart('Нет начислений или оплат 1С за период');
        }

        return [
            'labels' => array_map(
                fn (string $month): string => $this->formatMonthLabel($month, $tz),
                $months,
            ),
            'datasets' => [
                [
                    'label' => 'Начислено',
                    'data' => $accruedData,
                    'backgroundColor' => '#fbbf24',
                    'borderColor' => '#fbbf24',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Оплачено',
                    'data' => $paidData,
                    'backgroundColor' => '#34d399',
                    'borderColor' => '#34d399',
                    'borderWidth' => 1,
                ],
                [
                    'type' => 'line',
                    'label' => 'Разница',
                    'data' => $deltaData,
                    'borderColor' => '#ef4444',
                    'backgroundColor' => '#ef4444',
                    'borderWidth' => 2,
                    'pointRadius' => 2,
                    'tension' => 0.25,
                    'fill' => false,
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
                        'maxRotation' => 0,
                        'minRotation' => 0,
                    ],
                    'grid' => ['display' => false],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['font' => ['size' => 10]],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{accrued: float, paid: float}>
     */
    private function loadSettlementTurnoversByMonth(int $marketId, string $startDate, string $endDate): array
    {
        if (
            ! Schema::hasTable('tenant_settlement_balances')
            || ! Schema::hasColumn('tenant_settlement_balances', 'period_to')
            || ! Schema::hasColumn('tenant_settlement_balances', 'turnover_debit')
            || ! Schema::hasColumn('tenant_settlement_balances', 'turnover_credit')
        ) {
            return [];
        }

        $query = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('period_to', '>=', $startDate)
            ->where('period_to', '<', $endDate);

        if (Schema::hasColumn('tenant_settlement_balances', 'account')) {
            $query->where('account', '62');
        }

        try {
            $rows = $query->get(['period_to', 'turnover_debit', 'turnover_credit']);
        } catch (\Throwable) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $month = $this->normalizePeriodToMonth($row->period_to ?? null);

            if ($month === null) {
                continue;
            }

            $result[$month] ??= ['accrued' => 0.0, 'paid' => 0.0];
            $result[$month]['accrued'] += (float) ($row->turnover_debit ?? 0.0);
            $result[$month]['paid'] += (float) ($row->turnover_credit ?? 0.0);
        }

        return $result;
    }

    /**
     * @return array<string, float>
     */
    private function loadAccrualsByMonth(int $marketId, string $startDate, string $endDate): array
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'period')) {
            return [];
        }

        $columns = Schema::getColumnListing('tenant_accruals');
        $select = ['period'];

        foreach (['total_with_vat', 'total_no_vat', 'amount'] as $column) {
            if (in_array($column, $columns, true)) {
                $select[] = $column;
            }
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', '>=', $startDate)
            ->where('period', '<', $endDate);

        if (in_array('source', $columns, true)) {
            $query->where('source', '1c');
        }

        try {
            $rows = $query->get($select);
        } catch (\Throwable) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $month = $this->normalizePeriodToMonth($row->period ?? null);

            if ($month === null) {
                continue;
            }

            $amount = $this->firstNumericValue($row, ['total_with_vat', 'total_no_vat', 'amount']);
            $result[$month] = ($result[$month] ?? 0.0) + $amount;
        }

        return $result;
    }

    /**
     * @return array<string, float>
     */
    private function loadPaymentsByMonth(int $marketId, string $startDate, string $endDate): array
    {
        if (! Schema::hasTable('tenant_payments') || ! Schema::hasColumn('tenant_payments', 'period')) {
            return [];
        }

        $query = DB::table('tenant_payments')
            ->where('market_id', $marketId)
            ->where('period', '>=', $startDate)
            ->where('period', '<', $endDate);

        try {
            $rows = $query->get(['period', 'amount']);
        } catch (\Throwable) {
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $month = $this->normalizePeriodToMonth($row->period ?? null);

            if ($month === null) {
                continue;
            }

            $result[$month] = ($result[$month] ?? 0.0) + (float) ($row->amount ?? 0.0);
        }

        return $result;
    }

    /**
     * @param  list<string>  $columns
     */
    private function firstNumericValue(object $row, array $columns): float
    {
        foreach ($columns as $column) {
            $value = $row->{$column} ?? null;

            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    private function normalizePeriodToMonth(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}/', $raw) === 1) {
            return substr($raw, 0, 7);
        }

        try {
            return CarbonImmutable::parse($raw)->format('Y-m');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        if (filled($value)) {
            return (int) $value;
        }

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
            $latestYm = $this->resolveLatestDataMonth($marketId);

            if ($latestYm && $latestYm > $ym) {
                $ym = $latestYm;
            }
        }

        $start = $this->parseMonthStart($ym, $tz);

        return [$ym, $start];
    }

    private function resolveLatestDataMonth(int $marketId): ?string
    {
        $latest = null;

        if (Schema::hasTable('tenant_accruals') && Schema::hasColumn('tenant_accruals', 'period')) {
            try {
                $value = DB::table('tenant_accruals')
                    ->where('market_id', $marketId)
                    ->orderByDesc('period')
                    ->value('period');

                $latest = $this->maxMonth($latest, $this->normalizePeriodToMonth($value));
            } catch (\Throwable) {
                // ignore and try payments
            }
        }

        if (Schema::hasTable('tenant_payments') && Schema::hasColumn('tenant_payments', 'period')) {
            try {
                $value = DB::table('tenant_payments')
                    ->where('market_id', $marketId)
                    ->orderByDesc('period')
                    ->value('period');

                $latest = $this->maxMonth($latest, $this->normalizePeriodToMonth($value));
            } catch (\Throwable) {
                // ignore and try settlement fallback
            }
        }

        if (Schema::hasTable('tenant_settlement_balances') && Schema::hasColumn('tenant_settlement_balances', 'period_to')) {
            try {
                $query = DB::table('tenant_settlement_balances')
                    ->where('market_id', $marketId);

                if (Schema::hasColumn('tenant_settlement_balances', 'account')) {
                    $query->where('account', '62');
                }

                $value = $query
                    ->orderByDesc('period_to')
                    ->value('period_to');

                $latest = $this->maxMonth($latest, $this->normalizePeriodToMonth($value));
            } catch (\Throwable) {
                // ignore and use document data fallback
            }
        }

        return $latest;
    }

    private function maxMonth(?string $left, ?string $right): ?string
    {
        if ($left === null) {
            return $right;
        }

        if ($right === null) {
            return $left;
        }

        return $right > $left ? $right : $left;
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

    private function emptyChart(string $label = 'Нет данных'): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'label' => 'Начислено',
                    'data' => [0],
                ],
                [
                    'label' => 'Оплачено',
                    'data' => [0],
                ],
            ],
        ];
    }
}
