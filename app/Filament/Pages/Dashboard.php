<?php
# app/Filament/Pages/Dashboard.php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpiringContractsWidget;
use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use App\Filament\Widgets\MarketSwitcherWidget;
use App\Filament\Widgets\RecentTenantRequestsWidget;
use App\Filament\Widgets\TenantActivityStatsWidget;
use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as DbSchema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Панель управления';
    protected static ?string $title = 'Панель управления';

    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static ?int $navigationSort = 1;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    public function mount(): void
    {
        parent::mount();

        // Приводим разные session-ключи выбора рынка к единому dashboard_market_id.
        $this->syncDashboardMarketId();

        // Гарантируем, что dashboard_month задан.
        $tz = $this->resolveMarketTimezone($this->resolveMarketId());

        $month = $this->filters['month'] ?? session('dashboard_month');

        if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = CarbonImmutable::now($tz)->format('Y-m');
        }

        session(['dashboard_month' => $month]);
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    /**
     * Глобальные фильтры дашборда (прокидываются во все виджеты через InteractsWithPageFilters).
     */
    public function filtersForm(Schema $schema): Schema
    {
        $marketId = $this->resolveMarketId();
        $tz = $this->resolveMarketTimezone($marketId);

        return $schema->schema([
            Section::make()
                ->schema([
                    Select::make('month')
                        ->label('Период (месяц)')
                        ->placeholder('Выбери месяц')
                        ->options(fn (): array => $this->getMonthOptions($marketId, $tz))
                        ->default(fn (): string => CarbonImmutable::now($tz)->format('Y-m'))
                        ->native(false)
                        ->searchable()
                        ->live()
                        ->afterStateHydrated(function (Select $component, $state) use ($tz): void {
                            $value = (is_string($state) && preg_match('/^\d{4}-\d{2}$/', $state))
                                ? $state
                                : CarbonImmutable::now($tz)->format('Y-m');

                            if ($state !== $value) {
                                $component->state($value);
                            }

                            session(['dashboard_month' => $value]);
                        })
                        ->afterStateUpdated(function ($state): void {
                            if (is_string($state) && preg_match('/^\d{4}-\d{2}$/', $state)) {
                                session(['dashboard_month' => $state]);
                            }
                        }),
                ])
                ->columns([
                    'md' => 2,
                    'xl' => 3,
                ]),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        $user = Filament::auth()->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return [MarketSwitcherWidget::class];
        }

        return [];
    }

    public function getWidgets(): array
    {
        return [
            MarketOverviewStatsWidget::class,
            TenantActivityStatsWidget::class,
            MarketSpacesStatusChartWidget::class,

            ExpiringContractsWidget::class,
            RecentTenantRequestsWidget::class,
        ];
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return (int) ($user->market_id ?: 0);
        }

        return (int) (session('dashboard_market_id') ?: 0);
    }

    private function syncDashboardMarketId(): void
    {
        $user = Filament::auth()->user();

        if (! $user || ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }

        if (filled(session('dashboard_market_id'))) {
            return;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        // Поддерживаем ВСЕ варианты ключей, которые встречались в проекте
        $fallback =
            session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        if (filled($fallback)) {
            session(['dashboard_market_id' => (int) $fallback]);
        }
    }

    private function resolveMarketTimezone(int $marketId): string
    {
        $tz = (string) config('app.timezone', 'UTC');

        if ($marketId > 0) {
            $market = Market::query()->select(['id', 'timezone'])->find($marketId);
            $candidate = trim((string) ($market?->timezone ?? ''));

            if ($candidate !== '') {
                $tz = $candidate;
            }
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    private function getMonthOptions(int $marketId, string $tz): array
    {
        $months = [];

        if (
            $marketId > 0
            && DbSchema::hasTable('tenant_accruals')
            && DbSchema::hasColumn('tenant_accruals', 'market_id')
        ) {
            $periodCol = $this->pickFirstExistingAccrualPeriodColumn();

            if ($periodCol) {
                try {
                    $raw = DB::table('tenant_accruals')
                        ->where('market_id', $marketId)
                        ->select($periodCol)
                        ->distinct()
                        ->orderBy($periodCol)
                        ->pluck($periodCol)
                        ->all();

                    foreach ($raw as $value) {
                        $ym = $this->normalizeYm($value);
                        if ($ym) {
                            $months[$ym] = true;
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        $months[CarbonImmutable::now($tz)->format('Y-m')] = true;

        if ($months === [] || count($months) < 3) {
            $now = CarbonImmutable::now($tz)->startOfMonth();
            for ($i = 0; $i < 24; $i++) {
                $months[$now->subMonths($i)->format('Y-m')] = true;
            }
        }

        $keys = array_keys($months);
        sort($keys);

        $options = [];
        foreach ($keys as $ym) {
            try {
                $options[$ym] = CarbonImmutable::createFromFormat('Y-m', $ym, $tz)->format('m.Y');
            } catch (\Throwable) {
                $options[$ym] = $ym;
            }
        }

        return array_reverse($options, true);
    }

    private function pickFirstExistingAccrualPeriodColumn(): ?string
    {
        foreach ([
            'period',
            'period_ym',
            'period_start',
            'period_date',
            'accrual_period',
            'month',
        ] as $col) {
            if (DbSchema::hasColumn('tenant_accruals', $col)) {
                return $col;
            }
        }

        return null;
    }

    private function normalizeYm(mixed $value): ?string
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d{6}$/', $value))) {
            $s = (string) $value;
            return substr($s, 0, 4) . '-' . substr($s, 4, 2);
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}/', $value)) {
            return substr($value, 0, 7);
        }

        return null;
    }
}
