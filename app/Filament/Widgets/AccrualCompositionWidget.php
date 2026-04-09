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

class AccrualCompositionWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Структура начислений';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'doughnut';
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
        [$selectedMonthYm, $selectedMonthStart] = $this->resolveMonthRange($tz);
        [$effectiveMonthYm] = $this->resolveEffectiveMonthRange($marketId, $selectedMonthYm, $selectedMonthStart, $tz);

        return 'Локация: ' . (string) $market->name
            . ' • ' . $this->formatMonthLabel($effectiveMonthYm, $tz)
            . ' • Источник: 1С (детализация начислений)';
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

        if (! Schema::hasTable('tenant_accruals')) {
            return $this->emptyChart('Нет данных начислений');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);
        [$selectedMonthYm, $selectedMonthStart] = $this->resolveMonthRange($tz);
        [, $effectiveMonthStart] = $this->resolveEffectiveMonthRange($marketId, $selectedMonthYm, $selectedMonthStart, $tz);

        $totals = $this->loadAccrualTotals($marketId, $effectiveMonthStart);

        if ($totals === null) {
            return $this->emptyChart('Не удалось прочитать начисления');
        }

        if (! $totals['has_rows']) {
            return $this->emptyChart('Нет начислений за ' . $this->formatMonthLabel($effectiveMonthStart->format('Y-m'), $tz));
        }

        $rent = $totals['rent'];
        $utilities = $totals['utilities'];
        $electricity = $totals['electricity'];
        $management = $totals['management'];
        $other = $totals['other'];

        $labels = [];
        $data = [];
        $colors = [];

        if ($rent > 0) {
            $labels[] = 'Аренда';
            $data[] = round($rent, 2);
            $colors[] = '#f59e0b';
        }

        if ($utilities > 0) {
            $labels[] = 'Коммунальные';
            $data[] = round($utilities, 2);
            $colors[] = '#60a5fa';
        }

        if ($electricity > 0) {
            $labels[] = 'Электроэнергия';
            $data[] = round($electricity, 2);
            $colors[] = '#34d399';
        }

        if ($management > 0) {
            $labels[] = 'Управление';
            $data[] = round($management, 2);
            $colors[] = '#a78bfa';
        }

        if ($other > 0) {
            $labels[] = 'Прочее';
            $data[] = $other;
            $colors[] = '#94a3b8';
        }

        if ($labels === []) {
            return $this->emptyChart('Нет начислений за ' . $this->formatMonthLabel($effectiveMonthStart->format('Y-m'), $tz));
        }

        $labels = $this->withPercentageLabels($labels, $data);

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Структура начислений',
                    'data' => $data,
                    'backgroundColor' => $colors,
                    'borderColor' => $colors,
                    'borderWidth' => 1,
                    'hoverOffset' => 6,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, float|int>  $data
     * @return array<int, string>
     */
    private function withPercentageLabels(array $labels, array $data): array
    {
        $total = array_sum($data);

        if ($total <= 0) {
            return $labels;
        }

        $result = [];

        foreach ($labels as $index => $label) {
            $value = (float) ($data[$index] ?? 0.0);
            $percent = ($value / $total) * 100;

            $result[] = sprintf('%s (%s%%)', $label, $this->formatPercent($percent));
        }

        return $result;
    }

    private function formatPercent(float $value): string
    {
        if (abs($value - round($value)) < 0.0001) {
            return (string) (int) round($value);
        }

        return number_format($value, 1, '.', '');
    }

    /**
     * @return array{
     *   has_rows: bool,
     *   rent: float,
     *   utilities: float,
     *   electricity: float,
     *   management: float,
     *   total_with_vat: float,
     *   other: float
     * }|null
     */
    private function loadAccrualTotals(int $marketId, CarbonImmutable $monthStart): ?array
    {
        try {
            $row = $this->accrualsBaseQuery($marketId)
                ->where('period', $monthStart->toDateString())
                ->selectRaw('
                    COUNT(*) as rows_count,
                    COALESCE(SUM(rent_amount), 0) as rent_total,
                    COALESCE(SUM(utilities_amount), 0) as utilities_total,
                    COALESCE(SUM(electricity_amount), 0) as electricity_total,
                    COALESCE(SUM(management_fee), 0) as management_total,
                    COALESCE(SUM(total_with_vat), 0) as total_with_vat
                ')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        $rent = (float) ($row->rent_total ?? 0);
        $utilities = (float) ($row->utilities_total ?? 0);
        $electricity = (float) ($row->electricity_total ?? 0);
        $management = (float) ($row->management_total ?? 0);
        $totalWithVat = (float) ($row->total_with_vat ?? 0);

        return [
            'has_rows' => (int) ($row->rows_count ?? 0) > 0,
            'rent' => $rent,
            'utilities' => $utilities,
            'electricity' => $electricity,
            'management' => $management,
            'total_with_vat' => $totalWithVat,
            'other' => $this->calculateOtherAmount($rent, $utilities, $electricity, $management, $totalWithVat),
        ];
    }

    private function calculateOtherAmount(
        float $rent,
        float $utilities,
        float $electricity,
        float $management,
        float $totalWithVat
    ): float {
        $knownTotal = $rent + $utilities + $electricity + $management;

        return max(0.0, round($totalWithVat - $knownTotal, 2));
    }

    protected function getOptions(): array
    {
        return [
            'responsive' => true,
            'maintainAspectRatio' => true,
            'aspectRatio' => 1.2,
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => [
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'padding' => 10,
                        'font' => ['size' => 11],
                    ],
                ],
            ],
            'cutout' => '58%',
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
     * @return array{0:string,1:CarbonImmutable,2:CarbonImmutable}
     */
    private function resolveMonthRange(string $tz): array
    {
        $raw = null;

        if (property_exists($this, 'pageFilters') && is_array($this->pageFilters ?? null)) {
            $raw = $this->pageFilters['month'] ?? $this->pageFilters['period'] ?? null;
        }

        if (! $raw && is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? $this->filters['period'] ?? $this->filters['dashboard_month'] ?? null;
        }

        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

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

        $start = CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->startOfMonth();
        $end = $start->addMonth();

        return [$monthYm, $start, $end];
    }

    /**
     * @return array{0:string,1:CarbonImmutable}
     */
    private function resolveEffectiveMonthRange(int $marketId, string $selectedMonthYm, CarbonImmutable $selectedMonthStart, string $tz): array
    {
        if ($this->hasAccrualRows($marketId, $selectedMonthStart)) {
            return [$selectedMonthYm, $selectedMonthStart];
        }

        $latestMonth = $this->findLatestAccrualMonth($marketId);

        if ($latestMonth === null) {
            return [$selectedMonthYm, $selectedMonthStart];
        }

        try {
            return [
                $latestMonth,
                CarbonImmutable::createFromFormat('Y-m', $latestMonth, $tz)->startOfMonth(),
            ];
        } catch (\Throwable) {
            return [$selectedMonthYm, $selectedMonthStart];
        }
    }

    private function hasAccrualRows(int $marketId, CarbonImmutable $monthStart): bool
    {
        return $this->accrualsBaseQuery($marketId)
            ->where('period', $monthStart->toDateString())
            ->exists();
    }

    private function findLatestAccrualMonth(int $marketId): ?string
    {
        $query = $this->accrualsBaseQuery($marketId)
            ->whereNotNull('period');

        if (Schema::hasColumn('tenant_accruals', 'imported_at')) {
            $query->orderByDesc('imported_at');
        } elseif (Schema::hasColumn('tenant_accruals', 'created_at')) {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('period');
        }

        $period = $query->value('period');

        if (! is_string($period) || $period === '') {
            return null;
        }

        return substr($period, 0, 7);
    }

    private function accrualsBaseQuery(int $marketId)
    {
        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId);

        if (Schema::hasColumn('tenant_accruals', 'source')) {
            $query->where('source', '1c');
        }

        return $query;
    }

    private function formatMonthLabel(string $ym, string $tz): string
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->format('m.Y');
        } catch (\Throwable) {
            return $ym;
        }
    }

    private function emptyChart(string $label): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'label' => 'Нет данных',
                    'data' => [1],
                    'backgroundColor' => ['#6b7280'],
                    'borderColor' => ['#6b7280'],
                ],
            ],
        ];
    }
}
