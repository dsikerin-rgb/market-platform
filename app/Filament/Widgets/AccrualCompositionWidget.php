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
        [$monthYm] = $this->resolveMonthRange($tz);

        return 'Локация: ' . (string) $market->name
            . ' • ' . $this->formatMonthLabel($monthYm, $tz)
            . ' • Источник: детализация начислений';
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
        [$monthYm, $monthStart] = $this->resolveMonthRange($tz);

        try {
            $row = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->where('period', $monthStart->toDateString())
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

        if ($labels === []) {
            return $this->emptyChart('Нет начислений за ' . $this->formatMonthLabel($monthYm, $tz));
        }

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
