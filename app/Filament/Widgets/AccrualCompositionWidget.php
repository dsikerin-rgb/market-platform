<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccrualCompositionWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

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

    public function getDescription(): string|Htmlable|null
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

        $periodLabel = e($this->formatMonthLabel($effectiveMonthYm, $tz));
        $sourceUrl = e(TenantAccrualResource::getUrl('index'));

        return new HtmlString(
            $periodLabel
            . " \u{2022} \u{0418}\u{0441}\u{0442}\u{043e}\u{0447}\u{043d}\u{0438}\u{043a}: "
            . '<a href="' . $sourceUrl . '" class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300">'
            . "\u{0434}\u{0435}\u{0442}\u{0430}\u{043b}\u{0438}\u{0437}\u{0430}\u{0446}\u{0438}\u{044f} \u{043d}\u{0430}\u{0447}\u{0438}\u{0441}\u{043b}\u{0435}\u{043d}\u{0438}\u{0439}"
            . '</a>'
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

        try {
            $row = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->where('period', $effectiveMonthStart->toDateString())
                ->selectRaw('
                    COALESCE(SUM(rent_amount), 0) as rent_total,
                    COALESCE(SUM(utilities_amount), 0) as utilities_total,
                    COALESCE(SUM(electricity_amount), 0) as electricity_total,
                    COALESCE(SUM(management_fee), 0) as management_total
                ')
                ->first();
        } catch (\Throwable) {
            return $this->emptyChart('Не удалось прочитать начисления');
        }

        $rent = (float) ($row->rent_total ?? 0);
        $utilities = (float) ($row->utilities_total ?? 0);
        $electricity = (float) ($row->electricity_total ?? 0);
        $management = (float) ($row->management_total ?? 0);

        $segments = [];
        $data = [];
        $colors = [];

        if ($rent > 0) {
            $segments[] = 'Аренда';
            $data[] = round($rent, 2);
            $colors[] = '#f59e0b';
        }

        if ($utilities > 0) {
            $segments[] = 'Коммунальные';
            $data[] = round($utilities, 2);
            $colors[] = '#60a5fa';
        }

        if ($electricity > 0) {
            $segments[] = 'Электроэнергия';
            $data[] = round($electricity, 2);
            $colors[] = '#34d399';
        }

        if ($management > 0) {
            $segments[] = 'Управление';
            $data[] = round($management, 2);
            $colors[] = '#a78bfa';
        }

        if ($segments === []) {
            return $this->emptyChart('Нет начислений за ' . $this->formatMonthLabel($effectiveMonthStart->format('Y-m'), $tz));
        }

        $total = array_sum($data);
        $labels = array_map(
            static function (string $label, float|int $value) use ($total): string {
                $percent = $total > 0 ? ($value / $total) * 100 : 0;

                return sprintf('%s %.1f%%', $label, $percent);
            },
            $segments,
            $data,
        );

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
        return DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('period', $monthStart->toDateString())
            ->exists();
    }

    private function findLatestAccrualMonth(int $marketId): ?string
    {
        $period = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->max('period');

        if (! is_string($period) || $period === '') {
            return null;
        }

        return substr($period, 0, 7);
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
