<?php
# app/Filament/Pages/Dashboard.php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\MarketAttentionWidget;
use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use App\Filament\Widgets\MarketSwitcherWidget;
use App\Filament\Widgets\AccrualCompositionWidget;
use App\Filament\Widgets\OneCDebtSnapshotsHistoryWidget;
use App\Filament\Widgets\RecentTenantRequestsWidget;
use App\Filament\Widgets\TenantActivityStatsWidget;
use App\Models\ContractDebt;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\TenantRequest;
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

    protected string $view = 'filament.pages.dashboard';

    protected static ?string $navigationLabel = 'Панель управления';
    protected static ?string $title = 'Панель управления';

    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static ?int $navigationSort = 1;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    /**
     * Колонки виджетов на дашборде.
     * md=2 сохраняет привычную сетку,
     * xl=3 даёт возможность виджету занимать "2 колонки из 3" на больших экранах.
     *
     * ВАЖНО: сигнатура должна совпадать с Filament\Pages\Dashboard::getColumns(): array|int
     */
    public function getColumns(): array|int
    {
        return [
            'md' => 2,
            'xl' => 3,
        ];
    }

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

        $month = $this->resolveMonthFromRequestPeriod($tz);

        // Если нет period=... в URL — берём из сессии, иначе дефолт = "последний месяц с данными"
        $month = $month ?: $this->resolveMonthFromSessionOrLastWithData($marketId, $tz);

        session(['dashboard_month' => $month]);
        session(['dashboard_period' => $month . '-01']);

        return [
            'month' => $month,
        ];
    }

    private function bootstrapDashboardState(): void
    {
        $this->syncDashboardMarketId();

        $marketId = $this->resolveMarketId();
        $tz = $this->resolveMarketTimezone($marketId);

        $current = $this->resolveMonthFromRequestPeriod($tz);

        if (is_array($this->filters ?? null)) {
            $current = $this->filters['month'] ?? $current;
        }

        $fallback = $this->resolveLastMonthWithData($marketId, $tz);
        $month = $this->resolveMonthOrFallback($current ?: session('dashboard_month'), $fallback);

        $this->filters = array_merge((array) ($this->filters ?? []), ['month' => $month]);
        session(['dashboard_month' => $month]);
        session(['dashboard_period' => $month . '-01']);
    }

    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function shouldUseWorkspaceDashboard(): bool
    {
        return app()->environment(['local', 'staging', 'production']);
    }

    /**
     * @return array<int, class-string>
     */
    public function getWorkspaceHeaderWidgets(): array
    {
        if (! $this->shouldUseWorkspaceDashboard()) {
            return [];
        }

        return $this->getMarketSwitcherWidgets();
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     title: string,
     *     description: string,
     *     icon: string,
     *     columns: int|array<string, int>,
     *     widgets: array<int, class-string>
     * }>
     */
    public function getWorkspaceDashboardSections(): array
    {
        $sections = [
            [
                'key' => 'attention',
                'title' => 'Критическое внимание',
                'description' => 'Сигналы, которые требуют решения сегодня: ошибки обменов, срочные обращения и просроченные задачи.',
                'icon' => 'heroicon-o-shield-exclamation',
                'columns' => 1,
                'widgets' => $this->resolveVisibleWorkspaceWidgets([
                    MarketAttentionWidget::class,
                ]),
            ],
            [
                'key' => 'finance',
                'title' => 'Финансы 1С',
                'description' => 'Сводка по начислениям, покрытию 1С и динамике снимков задолженности без изменения источников данных.',
                'icon' => 'heroicon-o-banknotes',
                'columns' => [
                    'md' => 2,
                    'xl' => 3,
                ],
                'widgets' => $this->resolveVisibleWorkspaceWidgets([
                    \App\Filament\Widgets\RevenueYearChartWidget::class,
                    OneCDebtSnapshotsHistoryWidget::class,
                    AccrualCompositionWidget::class,
                ]),
            ],
            [
                'key' => 'spaces',
                'title' => 'Места и заполняемость',
                'description' => 'Оперативный контур рынка: занятость, свободный фонд и базовые показатели по арендаторам и местам.',
                'icon' => 'heroicon-o-home-modern',
                'columns' => [
                    'md' => 2,
                    'xl' => 3,
                ],
                'widgets' => $this->resolveVisibleWorkspaceWidgets([
                    MarketOverviewStatsWidget::class,
                    MarketSpacesStatusChartWidget::class,
                ]),
            ],
            [
                'key' => 'requests',
                'title' => 'Обращения арендаторов',
                'description' => 'Открытые обращения, которые требуют разбора и ответа по текущему рынку.',
                'icon' => 'heroicon-o-inbox',
                'columns' => [
                    'md' => 2,
                ],
                'widgets' => $this->resolveVisibleWorkspaceWidgets([
                    RecentTenantRequestsWidget::class,
                ]),
            ],
        ];

        return array_values(array_filter(
            $sections,
            static fn (array $section): bool => $section['widgets'] !== [],
        ));
    }

    /**
     * @return array{
     *     title: string,
     *     subtitle: string,
     *     market_name: string,
     *     market_selected: bool,
     *     period_label: string,
     *     stats: array<int, array{label: string, value: string, tone: string, url: string}>,
     *     links: array<int, array{title: string, description: string, meta: string, url: string, icon: string}>
     * }
     */
    public function getWorkspaceHeroData(): array
    {
        $this->syncDashboardMarketId();

        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name', 'timezone'])->find($marketId)
            : null;

        $tz = $this->resolveMarketTimezone($marketId);
        $fallbackMonth = $this->resolveLastMonthWithData($marketId, $tz);
        $selectedMonth = null;

        if (is_array($this->filters ?? null)) {
            $selectedMonth = $this->filters['month'] ?? null;
        }

        $month = $this->resolveMonthOrFallback($selectedMonth ?: session('dashboard_month'), $fallbackMonth);
        $periodLabel = $this->formatWorkspaceMonthLabel($month, $tz);
        $marketName = trim((string) ($market?->name ?? ''));
        $marketSelected = $marketId > 0 && $marketName !== '';

        $tenantsCount = 0;
        $totalSpaces = 0;
        $occupiedSpaces = 0;
        $vacantSpaces = 0;
        $openRequests = 0;
        $overdueTasks = 0;
        $vacantSpacesUrl = $this->appendQueryString(
            \App\Filament\Resources\MarketSpaceResource::getUrl('index'),
            [
                'tableFilters' => [
                    'status' => ['value' => 'vacant'],
                ],
            ],
        );
        $accrualsUrl = $this->appendQueryString(
            \App\Filament\Resources\TenantAccruals\TenantAccrualResource::getUrl('index'),
            ['tab' => 'one_c'],
        );
        $accrualsMonth = $this->resolveLatestOneCAccrualMonth($marketId, $tz) ?? $month;
        $accrualsPeriodLabel = $this->formatWorkspaceMonthLabel($accrualsMonth, $tz);

        if ($marketId > 0) {
            $tenantsCount = Tenant::query()
                ->where('market_id', $marketId)
                ->active()
                ->count();

            $spacesQuery = MarketSpace::query()->where('market_id', $marketId);
            $totalSpaces = (clone $spacesQuery)->count();
            $occupiedSpaces = (clone $spacesQuery)->where('status', 'occupied')->count();
            $vacantSpaces = (clone $spacesQuery)->where('status', 'vacant')->count();

            $openRequests = TenantRequest::query()
                ->where('market_id', $marketId)
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count();

            $overdueTasks = Task::query()
                ->where('market_id', $marketId)
                ->overdue()
                ->count();
        }

        return [
            'title' => 'Управленческий центр',
            'subtitle' => 'Главная страница в workspace-стилистике: единая точка контроля по рискам, финансам 1С, местам, обращениям и задачам.',
            'market_name' => $marketSelected ? $marketName : 'Рынок не выбран',
            'market_selected' => $marketSelected,
            'period_label' => $periodLabel,
            'stats' => [
                [
                    'label' => 'Арендаторы',
                    'value' => number_format($tenantsCount, 0, ',', ' '),
                    'tone' => 'neutral',
                    'url' => \App\Filament\Resources\TenantResource::getUrl('index'),
                ],
                [
                    'label' => 'Свободные места',
                    'value' => number_format($vacantSpaces, 0, ',', ' '),
                    'tone' => $vacantSpaces > 0 ? 'success' : 'neutral',
                    'url' => $vacantSpacesUrl,
                ],
                [
                    'label' => 'Открытые обращения',
                    'value' => number_format($openRequests, 0, ',', ' '),
                    'tone' => $openRequests > 0 ? 'warning' : 'neutral',
                    'url' => \App\Filament\Pages\Requests::getUrl(),
                ],
                [
                    'label' => 'Просроченные задачи',
                    'value' => number_format($overdueTasks, 0, ',', ' '),
                    'tone' => $overdueTasks > 0 ? 'danger' : 'neutral',
                    'url' => \App\Filament\Resources\TaskResource::getUrl('index'),
                ],
            ],
            'links' => [
                [
                    'title' => 'Обращения',
                    'description' => 'Открыть обращения арендаторов и проверить срочные вопросы.',
                    'meta' => $openRequests > 0
                        ? number_format($openRequests, 0, ',', ' ') . ' открыто'
                        : 'Открыть раздел',
                    'url' => \App\Filament\Pages\Requests::getUrl(),
                    'icon' => 'heroicon-o-inbox',
                ],
                [
                    'title' => 'Задачи',
                    'description' => 'Проверить просрочки, назначение исполнителей и оперативную загрузку команды.',
                    'meta' => $overdueTasks > 0
                        ? number_format($overdueTasks, 0, ',', ' ') . ' просрочено'
                        : 'Открыть раздел',
                    'url' => \App\Filament\Resources\TaskResource::getUrl('index'),
                    'icon' => 'heroicon-o-clipboard-document-list',
                ],
                [
                    'title' => 'Начисления',
                    'description' => 'Открыть начисления из 1С и проверить суммы за выбранный период.',
                    'meta' => $accrualsPeriodLabel,
                    'url' => $accrualsUrl,
                    'icon' => 'heroicon-o-banknotes',
                ],
                [
                    'title' => 'Торговые места',
                    'description' => 'Открыть фонд мест и быстро оценить текущую занятость и свободный остаток.',
                    'meta' => $totalSpaces > 0
                        ? number_format($occupiedSpaces, 0, ',', ' ') . ' из ' . number_format($totalSpaces, 0, ',', ' ') . ' занято'
                        : 'Открыть раздел',
                    'url' => \App\Filament\Resources\MarketSpaceResource::getUrl('index'),
                    'icon' => 'heroicon-o-home-modern',
                ],
            ],
        ];
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
                    'class' => 'dashboard-period-filter',
                    'style' => implode(';', [
                        'width:100%',
                        'max-width:34rem',
                        'min-width:18rem',
                        'flex:0 0 auto',
                    ]) . ';',
                ])
                ->schema([
                    Select::make('month')
                        ->label('Отчётный месяц')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Фильтр применяется к отчётным виджетам (начисления/отчётные показатели/история).')
                        ->placeholder('Выберите месяц')
                        ->options(fn (): array => $this->getMonthOptions(
                            $this->resolveMarketId(),
                            $resolveTz()
                        ))
                        ->default(function () use ($resolveTz): string {
                            $marketId = $this->resolveMarketId();
                            $tz = $resolveTz();

                            $value = $this->resolveMonthFromSessionOrLastWithData($marketId, $tz);

                            session(['dashboard_month' => $value]);
                            session(['dashboard_period' => $value . '-01']);

                            return $value;
                        })
                        ->native()
                        ->live()
                        ->columnSpanFull()
                        ->extraFieldWrapperAttributes(['style' => 'width:100%;'])
                        ->extraAttributes(['style' => 'width:100%;'])
                        ->extraInputAttributes([
                            'style' => 'width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;',
                        ])
                        ->afterStateHydrated(function (Select $component, $state) use ($resolveTz): void {
                            $marketId = $this->resolveMarketId();
                            $tz = $resolveTz();

                            $fallback = $this->resolveLastMonthWithData($marketId, $tz);
                            $value = $this->resolveMonthOrFallback($state, $fallback);

                            if ($state !== $value) {
                                $component->state($value);
                            }

                            session(['dashboard_month' => $value]);
                            session(['dashboard_period' => $value . '-01']);
                        })
                        ->afterStateUpdated(function ($state) use ($resolveTz): void {
                            $marketId = $this->resolveMarketId();
                            $tz = $resolveTz();

                            $fallback = $this->resolveLastMonthWithData($marketId, $tz);
                            $value = $this->resolveMonthOrFallback($state, $fallback);

                            session(['dashboard_month' => $value]);
                            session(['dashboard_period' => $value . '-01']);
                        }),
                ])
                ->columns(1),
        ]);
    }

    protected function getHeaderWidgets(): array
    {
        if ($this->shouldUseWorkspaceDashboard()) {
            return [];
        }

        return $this->getMarketSwitcherWidgets();
    }

    public function getWidgets(): array
    {
        $widgetMap = array_map(
            static fn (array $config): string => $config['class'],
            static::getAvailableDashboardWidgets()
        );
        $enabledKeys = $this->resolveEnabledDashboardWidgetKeys();

        return array_values(array_intersect_key($widgetMap, array_flip($enabledKeys)));
    }

    /**
     * @return array<int, class-string>
     */
    private function getMarketSwitcherWidgets(): array
    {
        $user = Filament::auth()->user();

        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return [MarketSwitcherWidget::class];
        }

        return [];
    }

    /**
     * @param  array<int, class-string>  $widgets
     * @return array<int, class-string>
     */
    private function resolveVisibleWorkspaceWidgets(array $widgets): array
    {
        return array_values(array_filter(
            $widgets,
            static fn (string $widget): bool => class_exists($widget) && $widget::canView(),
        ));
    }

    private function formatWorkspaceMonthLabel(string $month, string $tz): string
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m', $month, $tz)
                ->locale('ru')
                ->translatedFormat('F Y');
        } catch (\Throwable) {
            return $month;
        }
    }

    /**
     * @return array<string, array{class: class-string, label: string}>
     */
    public static function getAvailableDashboardWidgets(): array
    {
        $widgets = [
            'attention' => [
                'class' => MarketAttentionWidget::class,
                'label' => 'Требует внимания',
            ],
            'overview' => [
                'class' => MarketOverviewStatsWidget::class,
                'label' => 'Ключевые показатели',
            ],
            'tenant_activity' => [
                'class' => TenantActivityStatsWidget::class,
                'label' => 'Активность арендаторов',
            ],
            'spaces_status' => [
                'class' => MarketSpacesStatusChartWidget::class,
                'label' => 'Статусы торговых мест',
            ],
            'recent_requests' => [
                'class' => RecentTenantRequestsWidget::class,
                'label' => 'Последние обращения',
            ],
        ];

        if (class_exists(\App\Filament\Widgets\RevenueYearChartWidget::class)) {
            $widgets = array_slice($widgets, 0, 1, true)
                + [
                    'revenue_year' => [
                        'class' => \App\Filament\Widgets\RevenueYearChartWidget::class,
                        'label' => 'Выручка по году',
                    ],
                ]
                + array_slice($widgets, 1, null, true);
        }

        if (class_exists(OneCDebtSnapshotsHistoryWidget::class)) {
            $widgets = array_slice($widgets, 0, 2, true)
                + [
                    'onec_debt_snapshots' => [
                        'class' => OneCDebtSnapshotsHistoryWidget::class,
                        'label' => 'История 1С-снимков задолженности',
                    ],
                ]
                + array_slice($widgets, 2, null, true);
        }

        if (class_exists(AccrualCompositionWidget::class)) {
            $widgets = array_slice($widgets, 0, 3, true)
                + [
                    'accrual_composition' => [
                        'class' => AccrualCompositionWidget::class,
                        'label' => 'Структура начислений',
                    ],
                ]
                + array_slice($widgets, 3, null, true);
        }

        return $widgets;
    }

    /**
     * @return array<string, string>
     */
    public static function getAvailableDashboardWidgetOptions(): array
    {
        return array_map(
            static fn (array $config): string => $config['label'],
            static::getAvailableDashboardWidgets()
        );
    }

    /**
     * @return list<string>
     */
    public static function getDefaultDashboardWidgetKeys(): array
    {
        return array_keys(static::getAvailableDashboardWidgets());
    }

    /**
     * @return list<string>
     */
    private function resolveEnabledDashboardWidgetKeys(): array
    {
        $defaults = static::getDefaultDashboardWidgetKeys();
        $marketId = $this->resolveMarketId();

        if ($marketId <= 0) {
            return $defaults;
        }

        $market = Market::query()
            ->select(['id', 'settings'])
            ->find($marketId);

        $enabled = data_get($market?->settings, 'dashboard.enabled_widgets');

        if (! is_array($enabled)) {
            return $defaults;
        }

        $allowed = array_flip($defaults);
        $normalized = array_values(array_filter(
            $enabled,
            static fn ($key): bool => is_string($key) && isset($allowed[$key]),
        ));

        return $normalized === [] ? $defaults : $normalized;
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

        if (filled($value)) {
            return (int) $value;
        }

        return $this->resolveDefaultMarketId();
    }

    private function syncDashboardMarketId(): void
    {
        $user = Filament::auth()->user();

        if (! $user || ! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())) {
            return;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $marketId = $this->resolveSelectedMarketId();

        if ($marketId > 0) {
            session(['dashboard_market_id' => $marketId]);
            session(["filament.{$panelId}.selected_market_id" => $marketId]);
            session(["filament_{$panelId}_market_id" => $marketId]);
            session(['filament.admin.selected_market_id' => $marketId]);
        }
    }

    private function resolveDefaultMarketId(): int
    {
        $marketId = Market::query()
            ->orderBy('name')
            ->value('id');

        return $marketId ? (int) $marketId : 0;
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

    private function resolveMonthFromRequestPeriod(string $tz): ?string
    {
        $periodRaw = request()->query('period');

        if (! is_string($periodRaw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodRaw)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $periodRaw, $tz)->format('Y-m');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveMonthFromSessionOrLastWithData(int $marketId, string $tz): string
    {
        $raw = session('dashboard_month');
        $fallback = $this->resolveLastMonthWithData($marketId, $tz);

        return $this->resolveMonthOrFallback($raw, $fallback);
    }

    private function resolveMonthOrFallback(mixed $candidate, string $fallbackYm): string
    {
        if (is_string($candidate) && preg_match('/^\d{4}-\d{2}$/', $candidate)) {
            return $candidate;
        }

        return $fallbackYm;
    }

    /**
     * Дефолт для дашборда:
     * 1) последний месяц с данными в tenant_accruals (витрина начислений/график),
     * 2) иначе — последний месяц в contract_debts (снимки долгов из 1С),
     * 3) иначе — operations,
     * 4) иначе — текущий месяц.
     */
    private function resolveLastMonthWithData(int $marketId, string $tz): string
    {
        $nowYm = CarbonImmutable::now($tz)->format('Y-m');

        if ($marketId <= 0) {
            return $nowYm;
        }

        // 1) tenant_accruals
        if (DbSchema::hasTable('tenant_accruals') && DbSchema::hasColumn('tenant_accruals', 'market_id')) {
            $periodCol = $this->pickFirstExistingAccrualPeriodColumn();

            if ($periodCol) {
                try {
                    $v = DB::table('tenant_accruals')
                        ->where('market_id', $marketId)
                        ->orderByDesc($periodCol)
                        ->value($periodCol);

                    $ym = $this->normalizeYm($v, $tz);
                    if ($ym) {
                        return $ym;
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        // 2) contract_debts (1С)
        if (DbSchema::hasTable('contract_debts') && DbSchema::hasColumn('contract_debts', 'market_id')) {
            try {
                $v = ContractDebt::query()
                    ->where('market_id', $marketId)
                    ->orderByDesc('period')
                    ->value('period');

                if (is_string($v) && preg_match('/^\d{4}-\d{2}$/', $v)) {
                    return $v;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // 3) operations
        if (DbSchema::hasTable('operations') && DbSchema::hasColumn('operations', 'effective_month')) {
            try {
                $v = DB::table('operations')
                    ->where('market_id', $marketId)
                    ->orderByDesc('effective_month')
                    ->value('effective_month');

                $ym = $this->normalizeYm($v, $tz);
                if ($ym) {
                    return $ym;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        return $nowYm;
    }

    private function getMonthOptions(int $marketId, string $tz): array
    {
        $months = [];

        // tenant_accruals
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
                        $ym = $this->normalizeYm($value, $tz);
                        if ($ym) {
                            $months[$ym] = true;
                        }
                    }
                } catch (\Throwable) {
                    // ignore
                }
            }
        }

        // contract_debts (1С)
        if (
            $marketId > 0
            && DbSchema::hasTable('contract_debts')
            && DbSchema::hasColumn('contract_debts', 'market_id')
            && DbSchema::hasColumn('contract_debts', 'period')
        ) {
            try {
                $raw = ContractDebt::query()
                    ->where('market_id', $marketId)
                    ->select('period')
                    ->distinct()
                    ->orderBy('period')
                    ->pluck('period')
                    ->all();

                foreach ($raw as $value) {
                    if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value)) {
                        $months[$value] = true;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        // operations
        if ($marketId > 0 && DbSchema::hasTable('operations') && DbSchema::hasColumn('operations', 'effective_month')) {
            try {
                $raw = DB::table('operations')
                    ->where('market_id', $marketId)
                    ->select('effective_month')
                    ->distinct()
                    ->orderBy('effective_month')
                    ->pluck('effective_month')
                    ->all();

                foreach ($raw as $value) {
                    $ym = $this->normalizeYm($value, $tz);
                    if ($ym) {
                        $months[$ym] = true;
                    }
                }
            } catch (\Throwable) {
                // ignore
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

    private function normalizeYm(mixed $value, string $tz): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            try {
                return CarbonImmutable::instance($value)->setTimezone($tz)->format('Y-m');
            } catch (\Throwable) {
                return null;
            }
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d{6}$/', $value))) {
            $s = (string) $value;

            return substr($s, 0, 4) . '-' . substr($s, 4, 2);
        }

        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^\d{4}-\d{2}$/', $value)) {
                return $value;
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                try {
                    return CarbonImmutable::parse($value)->setTimezone($tz)->format('Y-m');
                } catch (\Throwable) {
                    return substr($value, 0, 7);
                }
            }
        }

        return null;
    }

    private function resolveLatestOneCAccrualMonth(int $marketId, string $tz): ?string
    {
        if (
            $marketId <= 0
            || ! DbSchema::hasTable('tenant_accruals')
            || ! DbSchema::hasColumn('tenant_accruals', 'market_id')
            || ! DbSchema::hasColumn('tenant_accruals', 'period')
        ) {
            return null;
        }

        try {
            $query = DB::table('tenant_accruals')
                ->where('market_id', $marketId)
                ->whereNotNull('period');

            if (DbSchema::hasColumn('tenant_accruals', 'source')) {
                $query->where('source', '1c');
            }

            $value = $query
                ->orderByDesc('period')
                ->value('period');

            return $this->normalizeYm($value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function appendQueryString(string $baseUrl, array $query): string
    {
        $queryString = http_build_query(array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        ));

        if ($queryString === '') {
            return $baseUrl;
        }

        return $baseUrl . (str_contains($baseUrl, '?') ? '&' : '?') . $queryString;
    }
}
