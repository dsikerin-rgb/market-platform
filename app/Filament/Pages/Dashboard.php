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

    /**
     * В некоторых сборках Livewire/Filament хук mount может “не находиться” (кэш/особенности загрузки),
     * из-за чего Livewire дергает __call('mount') и падает с BadMethodCallException.
     *
     * Мы:
     * 1) держим реальный mount
     * 2) добавляем страховку в __call для 'mount'
     * 3) дублируем дефолты через getDefaultFilters (если Filament их использует)
     */
    public function mount(...$params): void
    {
        // если у родителя есть mount — пробуем дать ему отработать (без риска свалить страницу)
        try {
            $parent = get_parent_class($this) ?: null;

            if ($parent && method_exists($parent, 'mount')) {
                /** @phpstan-ignore-next-line */
                parent::mount(...$params);
            }
        } catch (\Throwable) {
            // ignore
        }

        $this->bootstrapDashboardState();
    }

    /**
     * Страховка: если Livewire по какой-то причине не “видит” mount и вызывает его через __call — не падаем.
     */
    public function __call($method, $params)
    {
        if ($method === 'mount') {
            $this->bootstrapDashboardState();

            return null;
        }

        return parent::__call($method, $params);
    }

    protected function getDefaultFilters(): array
    {
        $this->syncDashboardMarketId();

        $marketId = $this->resolveMarketId();
        $tz = $this->resolveMarketTimezone($marketId);

        $raw = session('dashboard_month');

        $month = (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw))
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        session(['dashboard_month' => $month]);

        return [
            'month' => $month,
        ];
    }

    private function bootstrapDashboardState(): void
    {
        $this->syncDashboardMarketId();

        $marketId = $this->resolveMarketId();
        $tz = $this->resolveMarketTimezone($marketId);

        $current = null;

        if (is_array($this->filters ?? null)) {
            $current = $this->filters['month'] ?? null;
        }

        $current = $current ?: session('dashboard_month');

        $month = (is_string($current) && preg_match('/^\d{4}-\d{2}$/', $current))
            ? $current
            : CarbonImmutable::now($tz)->format('Y-m');

        $this->filters = array_merge((array) ($this->filters ?? []), ['month' => $month]);
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
     * Filament 4: фильтры дашборда строятся через Schema.
     */
    public function filtersForm(Schema $schema): Schema
    {
        $this->syncDashboardMarketId();

        $resolveTz = function (): string {
            return $this->resolveMarketTimezone($this->resolveMarketId());
        };

        return $schema->schema([
            Section::make()
                ->columnSpanFull()
                ->extraAttributes([
                    // расширяем контейнер секции на всякий случай
                    'class' => 'w-full',
                    'style' => 'width:100%;',
                ])
                ->schema([
                    Select::make('month')
                        ->label('Период (месяц)')
                        ->placeholder('Выбери месяц')
                        ->options(fn (): array => $this->getMonthOptions(
                            $this->resolveMarketId(),
                            $resolveTz()
                        ))
                        ->default(function () use ($resolveTz): string {
                            $tz = $resolveTz();
                            $raw = session('dashboard_month');

                            return (is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw))
                                ? $raw
                                : CarbonImmutable::now($tz)->format('Y-m');
                        })
                        // Нативный select — самый стабильный (без “вертикальных цифр” и CSS-ломаний).
                        ->native()
                        ->live()
                        ->columnSpanFull()
                        ->extraFieldWrapperAttributes([
                            'class' => 'w-full',
                            'style' => 'width:100%;min-width:16rem;',
                        ])
                        // на разных версиях Filament атрибуты могут ложиться на разные узлы,
                        // поэтому дублируем и class, и style
                        ->extraAttributes([
                            'class' => 'w-full',
                            'style' => 'width:100%;min-width:16rem;',
                        ])
                        ->extraInputAttributes([
                            'class' => 'w-full',
                            'style' => 'width:100%;min-width:16rem;',
                        ])
                        ->afterStateHydrated(function (Select $component, $state) use ($resolveTz): void {
                            $tz = $resolveTz();
                            $fallback = (string) (session('dashboard_month') ?: CarbonImmutable::now($tz)->format('Y-m'));

                            $value = (is_string($state) && preg_match('/^\d{4}-\d{2}$/', $state))
                                ? $state
                                : $fallback;

                            if ($state !== $value) {
                                $component->state($value);
                            }

                            session(['dashboard_month' => $value]);
                        })
                        ->afterStateUpdated(function ($state) use ($resolveTz): void {
                            $tz = $resolveTz();

                            $value = (is_string($state) && preg_match('/^\d{4}-\d{2}$/', $state))
                                ? $state
                                : CarbonImmutable::now($tz)->format('Y-m');

                            session(['dashboard_month' => $value]);
                        }),
                ])
                ->columns(1),
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
        $widgets = [
            MarketOverviewStatsWidget::class,
            TenantActivityStatsWidget::class,
            MarketSpacesStatusChartWidget::class,

            ExpiringContractsWidget::class,
            RecentTenantRequestsWidget::class,
        ];

        // Если виджет графика выручки уже создан — подключаем без риска фатала.
        if (class_exists(\App\Filament\Widgets\RevenueYearChartWidget::class)) {
            array_splice($widgets, 1, 0, [\App\Filament\Widgets\RevenueYearChartWidget::class]);
        }

        return $widgets;
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

        return $this->resolveSelectedMarketId();
    }

    private function resolveSelectedMarketId(): int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        return (int) ($value ?: 0);
    }

    /**
     * Приводим выбор рынка к единому ключу dashboard_market_id (для super-admin).
     */
    private function syncDashboardMarketId(): void
    {
        $user = Filament::auth()->user();

        if (! $user || ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }

        $marketId = $this->resolveSelectedMarketId();

        if ($marketId > 0) {
            session(['dashboard_market_id' => $marketId]);
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

    /**
     * 'YYYY-MM' => 'MM.YYYY' (свежие сверху)
     */
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
                    // ignore and fallback
                }
            }
        }

        // текущий месяц всегда доступен
        $months[CarbonImmutable::now($tz)->format('Y-m')] = true;

        // если данных мало — рисуем последние 24 месяца
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

    /**
     * int YYYYMM | "YYYYMM" | "YYYY-MM" | "YYYY-MM-DD" => "YYYY-MM"
     */
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
