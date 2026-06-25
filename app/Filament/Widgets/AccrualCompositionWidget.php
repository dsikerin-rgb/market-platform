<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ResolvesDashboardFilterMonth;
use App\Models\Market;
use App\Models\User;
use App\Support\AdminCapabilities;
use App\Support\MarketContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccrualCompositionWidget extends Widget
{
    use InteractsWithPageFilters;
    use ResolvesDashboardFilterMonth;

    private const TOP_PACKAGE_LIMIT = 5;

    protected string $view = 'filament.widgets.accrual-composition-widget';

    protected ?string $heading = 'Состав начислений 1С';

    protected int|string|array $columnSpan = [
        'default' => 'full',
        'lg' => 1,
    ];

    public static function canView(): bool
    {
        return AdminCapabilities::canViewFinance(Filament::auth()->user());
    }

    protected function getViewData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->emptyData('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return $this->emptyData('Выберите рынок');
        }

        if (! Schema::hasTable('tenant_accruals')) {
            return $this->emptyData('Нет данных начислений');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);
        [$selectedMonthYm, $selectedMonthStart] = $this->resolveMonthRange($tz);
        [$effectiveMonthYm, $effectiveMonthStart] = $this->resolveEffectiveMonthRange($marketId, $selectedMonthYm, $selectedMonthStart, $tz);

        $packages = $this->loadAccrualPackages($marketId, $effectiveMonthStart);

        if ($packages === null) {
            return $this->emptyData('Не удалось прочитать начисления');
        }

        if ($packages === []) {
            return $this->emptyData('Нет начислений за ' . $this->formatMonthLabel($effectiveMonthStart->format('Y-m'), $tz));
        }

        $topPackages = array_slice($packages, 0, self::TOP_PACKAGE_LIMIT);
        $tailPackages = array_slice($packages, self::TOP_PACKAGE_LIMIT);
        $totalAmount = array_sum(array_column($packages, 'amount'));

        $visiblePackages = [];

        foreach ($topPackages as $index => $package) {
            $visiblePackages[] = $this->formatPackageRow($package, $index, $totalAmount);
        }

        if ($tailPackages !== []) {
            $visiblePackages[] = $this->formatPackageRow([
                'name' => 'Прочие группы',
                'amount' => array_sum(array_column($tailPackages, 'amount')),
                'rows' => array_sum(array_column($tailPackages, 'rows')),
            ], count($visiblePackages), $totalAmount, count($tailPackages));
        }

        return [
            'heading' => $this->heading,
            'description' => $this->formatMonthLabel($effectiveMonthYm, $tz) . ' • группировка по составу услуг',
            'packages' => $visiblePackages,
            'totalAmount' => round($totalAmount, 2),
            'rowsCount' => array_sum(array_column($packages, 'rows')),
            'packagesCount' => count($packages),
            'emptyReason' => null,
        ];
    }

    private function formatPercent(float $value): string
    {
        if (abs($value - round($value)) < 0.0001) {
            return (string) (int) round($value);
        }

        return number_format($value, 1, '.', '');
    }

    /**
     * @param  array{name:string, amount:float, rows:int}  $package
     * @return array{
     *   label:string,
     *   full_label:string,
     *   amount:float,
     *   percent:float,
     *   percent_label:string,
     *   rows:int,
     *   packages_count:int|null,
     *   color:string,
     *   width:string
     * }
     */
    private function formatPackageRow(array $package, int $index, float $totalAmount, ?int $packagesCount = null): array
    {
        $amount = (float) $package['amount'];
        $percent = $totalAmount > 0 ? ($amount / $totalAmount) * 100 : 0.0;

        return [
            'label' => $this->formatPackageLabel($package['name']),
            'full_label' => $package['name'],
            'amount' => round($amount, 2),
            'percent' => round($percent, 2),
            'percent_label' => $this->formatPercent($percent),
            'rows' => (int) $package['rows'],
            'packages_count' => $packagesCount,
            'color' => $this->packageColor($index),
            'width' => max(2, min(100, round($percent, 2))) . '%',
        ];
    }

    /**
     * @return array<int, array{name:string, amount:float, rows:int}>|null
     */
    private function loadAccrualPackages(int $marketId, CarbonImmutable $monthStart): ?array
    {
        try {
            $rows = $this->accrualsBaseQuery($marketId)
                ->where('period', $monthStart->toDateString())
                ->selectRaw("COALESCE(NULLIF(service_name, ''), 'Без вида начисления') as package_name")
                ->selectRaw('
                    COUNT(*) as rows_count,
                    COALESCE(SUM(
                        COALESCE(
                            total_with_vat,
                            total_no_vat,
                            COALESCE(rent_amount, 0)
                                + COALESCE(management_fee, 0)
                                + COALESCE(utilities_amount, 0)
                                + COALESCE(electricity_amount, 0)
                        )
                    ), 0) as total_amount
                ')
                ->groupByRaw("COALESCE(NULLIF(service_name, ''), 'Без вида начисления')")
                ->orderByDesc('total_amount')
                ->get();
        } catch (\Throwable) {
            return null;
        }

        return $rows
            ->map(static fn ($row): array => [
                'name' => (string) ($row->package_name ?? 'Без вида начисления'),
                'amount' => (float) ($row->total_amount ?? 0),
                'rows' => (int) ($row->rows_count ?? 0),
            ])
            ->filter(static fn (array $row): bool => $row['amount'] > 0)
            ->values()
            ->all();
    }

    private function formatPackageLabel(string $packageName): string
    {
        $parts = array_values(array_unique(array_filter(array_map(
            fn (string $part): string => $this->shortServiceName($part),
            preg_split('/;+/u', $packageName) ?: [],
        ), static fn (string $part): bool => $part !== '')));

        if ($parts === []) {
            $parts = ['Без вида начисления'];
        }

        if (count($parts) > 3) {
            $visible = array_slice($parts, 0, 3);
            $visible[] = '+' . (count($parts) - 3);

            return implode(' + ', $visible);
        }

        return implode(' + ', $parts);
    }

    private function shortServiceName(string $name): string
    {
        $normalized = trim(preg_replace('/^Услуги:\s*/u', '', $name) ?? $name);

        return match ($normalized) {
            'Арендная плата' => 'Аренда',
            'Компенсация потребленной эл/энергии' => 'Эл/энергия',
            'Компенсация расходов на водоснабжение и канализацию' => 'Вода/канализация',
            'Компенсация расходов на чистку жироуловителей' => 'Жироуловители',
            'Место для размещения рекламной информации' => 'Реклама',
            'Компенсация расходов по звуковой рекламе' => 'Звуковая реклама',
            'Доп.электрооборудование',
            'Установка доп.эл.оборудования' => 'Доп. эл/оборудование',
            'Компенсация расходов на газоснабжение' => 'Газ',
            'Компенсация расходов на обслуживание газового оборудования' => 'Газовое оборудование',
            'Откачка житких бытовых отходов' => 'ЖБО',
            'Эксплуатационные расходы' => 'Эксплуатация',
            default => $this->truncateLabel($normalized),
        };
    }

    private function truncateLabel(string $label): string
    {
        if (mb_strlen($label) <= 34) {
            return $label;
        }

        return rtrim(mb_substr($label, 0, 31)) . '...';
    }

    private function packageColor(int $index): string
    {
        $colors = [
            '#2563eb',
            '#f59e0b',
            '#10b981',
            '#8b5cf6',
            '#ef4444',
            '#06b6d4',
            '#64748b',
        ];

        return $colors[$index % count($colors)];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyData(string $reason): array
    {
        return [
            'heading' => $this->heading,
            'description' => null,
            'packages' => [],
            'totalAmount' => 0.0,
            'rowsCount' => 0,
            'packagesCount' => 0,
            'emptyReason' => $reason,
        ];
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        return app(MarketContext::class)->currentMarketId($user instanceof User ? $user : null);
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
        $raw = $this->resolveDashboardFilterMonthRaw();

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
