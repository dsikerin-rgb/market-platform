<?php

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

    protected ?string $heading = 'Места в финансовом контуре за месяц';

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

        $tz = $this->resolveTimezone($market?->timezone);

        [, , , $periodLabel] = $this->resolveMonthRange($tz);

        $marketName = trim((string) ($market?->name ?? ''));
        $parts = [];

        if ($marketName !== '') {
            $parts[] = 'Локация: ' . $marketName;
        }

        $parts[] = $periodLabel;
        $parts[] = 'Источник: 1С долги + связанные начисления';

        return implode(' • ', $parts);
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => [
                        'usePointStyle' => true,
                        'pointStyle' => 'rectRounded',
                        'boxWidth' => 12,
                        'boxHeight' => 12,
                        'padding' => 16,
                    ],
                ],
            ],
        ];
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

        $coveredSpaces = $this->countFinancialContourSpacesForMonth($marketId, $monthYm, $monthStart, $monthEnd);

        if ($coveredSpaces === null) {
            return $this->emptyChart($periodLabel . ' • нет данных финансового контура');
        }

        $coveredSpaces = max($coveredSpaces, 0);
        $outsideContour = max($totalSpaces - $coveredSpaces, 0);

        if (($outsideContour + $coveredSpaces) === 0) {
            return $this->emptyChart($periodLabel);
        }

        $labels = [
            'Вне контура (' . $outsideContour . ')',
            'В финансовом контуре (' . $coveredSpaces . ')',
        ];

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => [$outsideContour, $coveredSpaces],
                    'backgroundColor' => [
                        '#94A3B8',
                        '#22C55E',
                    ],
                    'borderColor' => [
                        '#FFFFFF',
                        '#FFFFFF',
                    ],
                    'borderWidth' => 2,
                    'hoverOffset' => 6,
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

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

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
     * @return array<string, mixed>
     */
    private function currentFilters(): array
    {
        $out = [];

        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $out = array_merge($out, $this->pageFilters);
        }

        if (is_array($this->filters ?? null)) {
            $out = array_merge($out, $this->filters);
        }

        return $out;
    }

    /**
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable,3:string}
     */
    private function resolveMonthRange(string $tz): array
    {
        $filters = $this->currentFilters();

        $raw = $filters['month'] ?? $filters['period'] ?? $filters['dashboard_month'] ?? null;
        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

        $monthYm = (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw))
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        $start = CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->startOfMonth();
        $end = $start->addMonth();

        return [$monthYm, $start, $end, $start->format('m.Y') . ' (TZ: ' . $tz . ')'];
    }

    private function countFinancialContourSpacesForMonth(
        int $marketId,
        string $monthYm,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): ?int {
        $spaceIds = [];

        foreach ($this->spaceIdsFromDebtContour($marketId, $monthYm) as $spaceId) {
            $spaceIds[$spaceId] = true;
        }

        foreach ($this->spaceIdsFromAccrualContour($marketId, $monthYm, $start, $end) as $spaceId) {
            $spaceIds[$spaceId] = true;
        }

        if ($spaceIds === []) {
            return null;
        }

        return count($spaceIds);
    }

    /**
     * @return list<int>
     */
    private function spaceIdsFromDebtContour(int $marketId, string $monthYm): array
    {
        if (! Schema::hasTable('contract_debts') || ! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        try {
            return DB::table('contract_debts as d')
                ->join('tenant_contracts as tc', function ($join): void {
                    $join->on('tc.market_id', '=', 'd.market_id')
                        ->on('tc.external_id', '=', 'd.contract_external_id');
                })
                ->where('d.market_id', $marketId)
                ->where('d.period', $monthYm)
                ->whereNotNull('tc.market_space_id')
                ->distinct()
                ->pluck('tc.market_space_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<int>
     */
    private function spaceIdsFromAccrualContour(
        int $marketId,
        string $monthYm,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): array {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        $meta = $this->getTableMeta('tenant_accruals');
        $cols = $meta['columns'];

        $marketCol = $this->pickFirstExisting($cols, ['market_id']);
        $contractCol = $this->pickFirstExisting($cols, ['tenant_contract_id']);
        $periodCol = $this->pickPeriodColumn($cols);
        $spaceCol = $this->pickFirstExisting($cols, ['market_space_id', 'space_id']);

        if (! $marketCol || ! $contractCol || ! $periodCol) {
            return [];
        }

        try {
            $query = DB::table('tenant_accruals as ta')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'ta.' . $contractCol)
                ->where('ta.' . $marketCol, $marketId)
                ->whereNotNull('ta.' . $contractCol);

            $this->applyMonthFilter($query, $meta, 'ta.' . $periodCol, $monthYm, $start, $end);

            $resolvedSpaceExpr = $spaceCol
                ? 'COALESCE(ta.' . $spaceCol . ', tc.market_space_id)'
                : 'tc.market_space_id';

            return $query
                ->whereRaw($resolvedSpaceExpr . ' IS NOT NULL')
                ->selectRaw('DISTINCT ' . $resolvedSpaceExpr . ' as resolved_market_space_id')
                ->pluck('resolved_market_space_id')
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{columns:list<string>,types:array<string,string>}
     */
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

    private function applyMonthFilter(
        Builder $query,
        array $meta,
        string $periodCol,
        string $monthYm,
        CarbonImmutable $start,
        CarbonImmutable $end
    ): void {
        $types = $meta['types'] ?? [];
        $basePeriodCol = str_contains($periodCol, '.') ? substr($periodCol, strrpos($periodCol, '.') + 1) : $periodCol;
        $type = strtoupper((string) ($types[$basePeriodCol] ?? ''));

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        if ($type !== '' && str_contains($type, 'INT')) {
            $query->where($periodCol, (int) str_replace('-', '', $monthYm));

            return;
        }

        $lower = strtolower($basePeriodCol);

        if (str_contains($lower, 'start') || str_contains($lower, 'date') || str_contains($lower, '_at')) {
            $query->where($periodCol, '>=', $startDate)
                ->where($periodCol, '<', $endDate);

            return;
        }

        $query->where(function (Builder $inner) use ($periodCol, $monthYm, $startDate): void {
            $inner->where($periodCol, $monthYm)
                ->orWhere($periodCol, $startDate);
        });
    }

    private function emptyChart(string $label = 'Нет данных'): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'data' => [1],
                    'backgroundColor' => ['#64748B'],
                    'borderColor' => ['#FFFFFF'],
                    'borderWidth' => 2,
                ],
            ],
        ];
    }
}
