<?php
# app/Filament/Resources/MarketSpaceResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceResource\Pages;
use App\Models\MarketLocation;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use App\Services\Operations\MarketPeriodResolver;
use App\Services\Operations\OperationsStateService;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;

class MarketSpaceResource extends BaseResource
{
    

    protected static ?string $model = MarketSpace::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $modelLabel = 'Торговое место';
    protected static ?string $pluralModelLabel = 'Торговые места';
    protected static ?string $navigationLabel = 'Торговые места';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home-modern';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationSort(): int
    {
        return 40;
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $key = "filament_{$panelId}_market_id";

        $value = session($key);

        return filled($value) ? (int) $value : null;
    }

    /**
     * Resolve label for MarketSpace.type via market_space_types (market_id + code).
     */
    protected static function resolveSpaceTypeLabel(?int $marketId, ?string $typeCode): ?string
    {
        if (blank($marketId) || blank($typeCode)) {
            return null;
        }

        static $cache = []; // [marketId => [code => name_ru]]

        if (! isset($cache[$marketId])) {
            $cache[$marketId] = MarketSpaceType::query()
                ->where('market_id', (int) $marketId)
                ->pluck('name_ru', 'code')
                ->all();
        }

        return $cache[$marketId][$typeCode] ?? $typeCode;
    }

    protected static function resolveSpaceTypeOptions(?int $marketId, ?string $currentTypeCode = null): array
    {
        if (blank($marketId)) {
            return [];
        }

        $options = MarketSpaceType::query()
            ->where('market_id', (int) $marketId)
            ->where('is_active', true)
            ->orderBy('name_ru')
            ->pluck('name_ru', 'code')
            ->all();

        if (filled($currentTypeCode) && ! isset($options[$currentTypeCode])) {
            $options[$currentTypeCode] = 'Текущий код: ' . $currentTypeCode . ' (нет в справочнике)';
        }

        return $options;
    }

    /**
     * Canonical statuses for UI (legacy "free" => "vacant").
     */
    protected static function normalizeStatus(?string $state): ?string
    {
        if ($state === 'free') {
            return 'vacant';
        }

        return $state;
    }

    protected static function statusLabel(?string $state): ?string
    {
        $state = static::normalizeStatus($state);

        return match ($state) {
            'vacant' => 'Свободно',
            'occupied' => 'Занято',
            'reserved' => 'Зарезервировано',
            'maintenance' => 'На обслуживании',
            default => $state,
        };
    }

    /**
     * Color mapping for badge() in table.
     * Требование: Занято = зелёный, Свободно = красный.
     */
    protected static function statusColor(?string $state): string
    {
        $state = static::normalizeStatus($state);

        return match ($state) {
            'occupied' => 'success',
            'vacant' => 'danger',
            'reserved' => 'warning',
            'maintenance' => 'gray',
            default => 'gray',
        };
    }

    
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'number',
            'space_group_token',
            'space_group_slot',
            'display_name',
            'activity_type',
            'location.name',
            'tenant.name',
        ];
    }
    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        // ВАЖНО: в форме должен быть РОВНО ОДИН market_id.
        $components = [];

        if ((bool) $user && $user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            if (filled($selectedMarketId)) {
                $components[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $components[] = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->hintIcon('heroicon-m-question-mark-circle')
                    ->hintIconTooltip('Рынок нужен, чтобы корректно фильтровать локации, арендаторов и тарифы.')
                    ->dehydrated(true);
            }
        } else {
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        $tabs = Tabs::make('market_space_tabs')
            ->columnSpanFull();

        // Безопасно: если в вашей версии Filament нет этого метода — просто пропускаем.
        if (method_exists($tabs, 'persistTabInQueryString')) {
            $tabs->persistTabInQueryString();
        }

        return $schema->components([
            ...$components,
            $tabs->tabs([
                Tab::make('Основное')
                    ->schema([
                        Section::make()
                            ->schema([
                                Forms\Components\Select::make('location_id')
                                    ->label('Локация')
                                    ->options(function ($get, ?MarketSpace $record) use ($user) {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (blank($marketId)) {
                                            return [];
                                        }

                                        return MarketLocation::query()
                                            ->where('market_id', (int) $marketId)
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Физическая зона рынка: павильоны, острова, уличная торговля и т.д.')
                                    ->disabled(function ($get, ?MarketSpace $record) use ($user) {
                                        if (! ((bool) $user && $user->isSuperAdmin())) {
                                            return false;
                                        }

                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        return blank($marketId);
                                    })
                                    ->nullable(),

                    Forms\Components\Select::make('tenant_id')
                        ->label('Арендатор')
                        ->options(function ($get, ?MarketSpace $record) use ($user) {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (blank($marketId)) {
                                            return [];
                                        }

                                        return Tenant::query()
                                            ->where('market_id', (int) $marketId)
                                            ->active()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                        })
                        ->searchable()
                        ->preload()
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Текущий арендатор (если место занято). Для “Свободно” арендатора можно не выбирать.')
                        ->disabled(function ($get, ?MarketSpace $record) use ($user) {
                            if ($record) {
                                return true;
                            }

                            if (! ((bool) $user && $user->isSuperAdmin())) {
                                return false;
                            }

                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        return blank($marketId);
                                    })
                                    ->nullable(),

                                Forms\Components\TextInput::make('number')
                                    ->label('Номер места')
                                    ->maxLength(255)
                                    ->reactive()
                                    ->placeholder('Например: П/1 или A-101')
                                    ->disabled(fn (?MarketSpace $record): bool => filled($record?->id))
                                    ->helperText(function (?MarketSpace $record): HtmlString|string|null {
                                        if (! filled($record?->id)) {
                                            return null;
                                        }

                                        $url = route('filament.admin.market-map', [
                                            'mode' => 'review',
                                            'market_space_id' => (int) $record->id,
                                        ]);

                                        return new HtmlString(
                                            '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer" style="font-weight:600;color:#2563eb;text-decoration:none;">Изменить через Карта → Ревизия</a>'
                                        );
                                    })
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Для создания: если display_name пуст — подставляем "Место {number}"
                                        if (blank($get('display_name')) && filled($state)) {
                                            $set('display_name', 'Место ' . trim((string) $state));
                                        }
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Короткий идентификатор места. Используется в поиске, импорте начислений и привязке договоров. После создания номер меняется только через режим "Карта -> Ревизия".'),

                                Forms\Components\TextInput::make('display_name')
                                    ->label('Название (для отображения)')
                                    ->maxLength(255)
                                    ->placeholder('Например: Аптека 22')
                                    ->disabled(fn (?MarketSpace $record): bool => filled($record?->id))
                                    ->helperText(function (?MarketSpace $record): HtmlString|string|null {
                                        if (! filled($record?->id)) {
                                            return null;
                                        }

                                        $url = route('filament.admin.market-map', [
                                            'mode' => 'review',
                                            'market_space_id' => (int) $record->id,
                                        ]);

                                        return new HtmlString(
                                            '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer" style="font-weight:600;color:#2563eb;text-decoration:none;">Изменить через Карта → Ревизия</a>'
                                        );
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Отображаемое имя места. После создания меняется только через режим "Карта -> Ревизия", чтобы не расходиться с ревизией идентичности места.')
                                    ->nullable(),

                                Forms\Components\Toggle::make('has_space_grouping')
                                    ->label('Входит в группу')
                                    ->default(fn (?MarketSpace $record): bool => filled($record?->space_group_token) || filled($record?->space_group_slot))
                                    ->dehydrated(false)
                                    ->live()
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Включайте только для мест, которые входят в состав общей группы: острова, холодильной линии или другой общей зоны.')
                                    ->afterStateUpdated(function (bool $state, callable $set): void {
                                        if ($state) {
                                            return;
                                        }

                                        $set('space_group_token', null);
                                        $set('space_group_slot', null);
                                    }),

                                Section::make('Групповое место')
                                    ->visible(fn (callable $get): bool => (bool) $get('has_space_grouping'))
                                    ->schema([
                                        Forms\Components\TextInput::make('space_group_token')
                                            ->label('Группа мест')
                                            ->maxLength(255)
                                            ->placeholder('Например: ОС8, ХК1, ПАВИЛЬОН3')
                                            ->hintIcon('heroicon-m-question-mark-circle')
                                            ->hintIconTooltip('Используйте для группировки мест внутри острова, холодильной линии или другой общей зоны. Заполняется для мест, которые входят в состав одной группы. Это упрощает привязку договоров вроде ОС8 14-15. Для ОС8/14 и ОС8/15 указывайте одну и ту же группу ОС8.')
                                            ->nullable(),

                                        Forms\Components\TextInput::make('space_group_slot')
                                            ->label('Номер внутри группы')
                                            ->maxLength(255)
                                            ->placeholder('Например: 14, 15, 14-15')
                                            ->hintIcon('heroicon-m-question-mark-circle')
                                            ->hintIconTooltip('Позиция места внутри группы. Обычно это номер стола, витрины или секции. Для одиночного места указывайте один номер, например 14. Для комбинированного служебного обозначения можно хранить 14-15.')
                                            ->nullable(),
                                    ])
                                    ->columns([
                                        'default' => 1,
                                        'md' => 2,
                                    ])
                                    ->compact(),

                                Forms\Components\TextInput::make('activity_type')
                                    ->label('Вид деятельности')
                                    ->maxLength(255)
                                    ->placeholder('Например: аптека / электро / мясо')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Заполняется импортом начислений и может уточняться вручную.')
                                    ->nullable(),

                    Forms\Components\Select::make('type')
                        ->label('Тип места')
                        ->options(function ($get, ?MarketSpace $record) use ($user) {
                            $marketId = $get('market_id') ?? $record?->market_id;

                            if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                $marketId = $user->market_id;
                            }

                            return static::resolveSpaceTypeOptions(
                                filled($marketId) ? (int) $marketId : null,
                                (string) ($get('type') ?? $record?->type ?? '')
                            );
                        })
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->nullable()
                                    ->placeholder('—')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip(function ($get, ?MarketSpace $record) use ($user): string {
                                        $parts = ['Категория места для отчётности. Берётся из справочника “Типы мест”.'];
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
                                        }

                                        if (filled($marketId)) {
                                            $currentType = (string) ($get('type') ?? $record?->type ?? '');
                                            $activeTypeCount = MarketSpaceType::query()
                                                ->where('market_id', (int) $marketId)
                                                ->where('is_active', true)
                                                ->count();
                                            $hasCurrentActiveType = $currentType !== ''
                                                && MarketSpaceType::query()
                                                    ->where('market_id', (int) $marketId)
                                                    ->where('is_active', true)
                                                    ->where('code', $currentType)
                                                    ->exists();

                                            if ($activeTypeCount === 0) {
                                                $parts[] = 'Для этого рынка справочник типов мест пока пуст. Сначала заполните "Типы мест".';
                                            } elseif ($currentType !== '' && ! $hasCurrentActiveType) {
                                                $parts[] = 'Текущий тип больше не найден среди активных типов мест.';
                                            }
                                        }

                                        return implode(' ', $parts);
                                    }),

                                Forms\Components\TextInput::make('area_sqm')
                                    ->label('Площадь, м²')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->placeholder('Например: 48')
                                    ->suffix('м²')
                                    ->extraFieldWrapperAttributes(['style' => 'width:min(100%, 14rem);'])
                                    ->extraInputAttributes(['style' => 'width:100%;'])
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Площадь используется в отчётах и расчётах. Допускаются десятичные значения.'),

                                Forms\Components\Select::make('status')
                                    ->label('Статус')
                                    ->options([
                                        'vacant' => 'Свободно',
                                        'occupied' => 'Занято',
                                        'reserved' => 'Зарезервировано',
                                        'maintenance' => 'На обслуживании',
                                    ])
                                    ->default('vacant')
                                    ->afterStateHydrated(function (Forms\Components\Select $component, $state): void {
                                        if ($state === 'free') {
                                            $component->state('vacant');
                                        }
                                    })
                                    ->dehydrateStateUsing(fn ($state) => $state === 'free' ? 'vacant' : $state)
                                    ->visible(fn (?MarketSpace $record): bool => ! $record)
                                    ->disabled(fn (?MarketSpace $record): bool => (bool) $record)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip(fn (?MarketSpace $record): string => $record
                                        ? 'Используется для быстрой визуальной оценки занятости. В таблице помечается цветом. Для существующих мест статус меняется через режим «Карта -> Ревизия».'
                                        : 'Используется для быстрой визуальной оценки занятости. В таблице помечается цветом.'),

                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ]),

                    Section::make('Ставка аренды')
                        ->schema([
                            Forms\Components\Placeholder::make('rent_rate_fact')
                                ->label('Фактическая ставка за период')
                                ->hintIcon('heroicon-m-question-mark-circle')
                                ->hintIconTooltip('Ставка, которую система фактически видит для выбранного периода. Берётся из операций и начислений, поэтому может отличаться от текущей ставки в карточке.')
                                ->content(fn (?MarketSpace $record): HtmlString => static::rentRateFactHtml($record)),

                            Forms\Components\TextInput::make('rent_rate_value')
                                ->label('Текущая ставка')
                                ->numeric()
                                ->inputMode('decimal')
                                ->placeholder('Например: 1500')
                                ->hintIcon('heroicon-m-question-mark-circle')
                                ->hintIconTooltip('Текущее значение ставки в карточке места. Используется в интерфейсах и операциях как актуальный снапшот ставки.')
                                ->disabled(fn (?MarketSpace $record): bool => (bool) $record),

                            Forms\Components\Select::make('rent_rate_unit')
                                ->label('Единица ставки')
                                ->options(static::rentRateUnitOptions())
                                ->placeholder('Не указано')
                                ->hintIcon('heroicon-m-question-mark-circle')
                                ->hintIconTooltip('Показывает, как интерпретировать текущую ставку: за м² в месяц или за всё место в месяц.')
                                ->nullable()
                                ->disabled(fn (?MarketSpace $record): bool => (bool) $record),

                            ])
                            ->columns([
                                'default' => 1,
                                'md' => 2,
                            ])
                            ->collapsible(),

                        Section::make('Примечания')
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->hiddenLabel()
                                    ->rows(3)
                                    ->placeholder('Добавьте комментарий по торговому месту…')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Свободный комментарий. Это поле не должно перетираться импортом.')
                                    ->columnSpanFull(),
                            ])
                            ->collapsible()
                            ->collapsed(),
                    ]),
                Tab::make('История')
                    ->schema([
                        Section::make('Арендаторы')
                            ->schema([
                                Forms\Components\Placeholder::make('tenant_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderTenantHistory($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('Ставка')
                            ->schema([
                                Forms\Components\Placeholder::make('rent_rate_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderRentRateHistory($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('Операции')
                            ->schema([
                                Forms\Components\Placeholder::make('operations')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderOperations($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),
                    ]),
            ]),
        ]);
    }

    /**
     * Опции единицы ставки.
     *
     * @return array<string, string>
     */
    protected static function rentRateUnitOptions(): array
    {
        return [
            'per_sqm_month' => 'за м² в месяц',
            'per_space_month' => 'за место в месяц',
        ];
    }

    protected static function rentRateUnitLabel(?string $unit): string
    {
        return static::rentRateUnitOptions()[$unit] ?? '—';
    }

    private static function rentRateFactHtml(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('—');
        }

        $market = Market::query()->find($record->market_id);
        if (! $market) {
            return new HtmlString('—');
        }

        $period = static::resolveOperationPeriod($record);
        $rentRate = static::resolveRentRateFact($record, $period);
        $unitLabel = $record->rent_rate_unit ? static::rentRateUnitLabel($record->rent_rate_unit) : null;

        $display = $rentRate !== null
            ? number_format($rentRate, 2, ',', ' ') . ' ₽'
            : 'Не задано';

        $extra = '';
        if ($unitLabel) {
            $extra .= '<div style="margin-top:4px;opacity:.7;">Единица: ' . e($unitLabel) . '</div>';
        }

        $estimate = static::rentRateEstimateHtml($record, $period);

        return new HtmlString(
            '<div style="font-size:13px;">' .
            '<div><strong>' . e($display) . '</strong></div>' .
            $extra .
            $estimate .
            '</div>'
        );
    }

    private static function rentRateEstimateHtml(MarketSpace $record, \Carbon\CarbonImmutable $period): string
    {
        $row = DB::table('tenant_accruals')
            ->where('market_id', (int) $record->market_id)
            ->where('market_space_id', (int) $record->id)
            ->where('period', $period->toDateString())
            ->select(['rent_amount', 'area_sqm'])
            ->first();

        if (! $row || ! $row->rent_amount || ! $row->area_sqm) {
            return '';
        }

        $area = (float) $row->area_sqm;
        if ($area <= 0) {
            return '';
        }

        $value = (float) $row->rent_amount / $area;
        $display = number_format($value, 2, ',', ' ');

        return '<div style="margin-top:4px;opacity:.65;">Оценочно: ' . e($display) . ' ₽/м² за период (справочно).</div>';
    }

    private static function resolveOperationPeriod(MarketSpace $record): \Carbon\CarbonImmutable
    {
        $market = Market::query()->find($record->market_id);
        $resolver = app(MarketPeriodResolver::class);
        $periodInput = request()->query('period');

        if ($market) {
            return $resolver->resolveMarketPeriod($market, is_string($periodInput) ? $periodInput : null);
        }

        return \Carbon\CarbonImmutable::now(config('app.timezone', 'UTC'))->startOfMonth();
    }

    private static function resolveRentRateFact(MarketSpace $record, \Carbon\CarbonImmutable $period): ?float
    {
        $stateService = app(OperationsStateService::class);
        $state = $stateService->getSpaceStateForPeriod((int) $record->market_id, $period, (int) $record->id);
        $rentRate = $state['rent_rate'];

        if ($rentRate === null) {
            $fallback = DB::table('tenant_accruals')
                ->where('market_id', (int) $record->market_id)
                ->where('market_space_id', (int) $record->id)
                ->where('period', $period->toDateString())
                ->value('rent_rate');

            if ($fallback !== null) {
                $rentRate = (float) $fallback;
            }
        }

        return $rentRate !== null ? (float) $rentRate : null;
    }

    private static function renderTenantHistory(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">История появится после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('market_space_tenant_histories')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица истории арендаторов ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('market_space_tenant_histories as h')
            ->leftJoin('tenants as old_t', 'old_t.id', '=', 'h.old_tenant_id')
            ->leftJoin('tenants as new_t', 'new_t.id', '=', 'h.new_tenant_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
            ->where('h.market_space_id', (int) $record->id)
            ->orderByDesc('h.changed_at')
            ->limit(200)
            ->get([
                'h.changed_at',
                'old_t.name as old_name',
                'old_t.short_name as old_short_name',
                'new_t.name as new_name',
                'new_t.short_name as new_short_name',
                'u.name as user_name',
            ]);

        $items = $rows->map(function ($row): array {
            $oldShort = trim((string) ($row->old_short_name ?? ''));
            $newShort = trim((string) ($row->new_short_name ?? ''));

            $oldLabel = $oldShort !== '' ? $oldShort : trim((string) ($row->old_name ?? ''));
            $newLabel = $newShort !== '' ? $newShort : trim((string) ($row->new_name ?? ''));

            return [
                'changed_at' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y H:i') : '—',
                'old_label' => $oldLabel !== '' ? $oldLabel : '—',
                'new_label' => $newLabel !== '' ? $newLabel : '—',
                'user_name' => $row->user_name ? (string) $row->user_name : '—',
            ];
        })->all();

        return new HtmlString(view('filament.market-spaces.tenant-history', [
            'items' => $items,
        ])->render());
    }

    private static function renderRentRateHistory(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">История появится после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('market_space_rent_rate_histories')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица истории ставки ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('market_space_rent_rate_histories as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
            ->where('h.market_space_id', (int) $record->id)
            ->orderByDesc('h.changed_at')
            ->limit(200)
            ->get([
                'h.changed_at',
                'h.old_value',
                'h.new_value',
                'h.unit',
                'h.note',
                'u.name as user_name',
            ]);

        $items = $rows->map(function ($row): array {
            return [
                'changed_at' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y H:i') : '—',
                'old_value' => $row->old_value !== null ? (float) $row->old_value : null,
                'new_value' => $row->new_value !== null ? (float) $row->new_value : null,
                'unit_label' => $row->unit ? static::rentRateUnitLabel((string) $row->unit) : '—',
                'note' => $row->note ? (string) $row->note : '',
                'user_name' => $row->user_name ? (string) $row->user_name : '—',
            ];
        })->all();

        $chartRows = DB::table('market_space_rent_rate_histories')
            ->where('market_space_id', (int) $record->id)
            ->whereNotNull('new_value')
            ->orderBy('changed_at')
            ->get(['changed_at', 'new_value', 'unit']);

        $chart = $chartRows->map(function ($row): array {
            return [
                'label' => $row->changed_at ? (string) \Carbon\Carbon::parse($row->changed_at)->format('d.m.Y') : '',
                'value' => (float) $row->new_value,
                'unit' => $row->unit ? (string) $row->unit : null,
            ];
        })->all();

        $unitLabel = static::rentRateUnitLabel($record->rent_rate_unit);

        return new HtmlString(view('filament.market-spaces.rent-rate-history', [
            'items' => $items,
            'chart' => $chart,
            'unitLabel' => $unitLabel,
        ])->render());
    }

    private static function renderOperations(?MarketSpace $record): HtmlString
    {
        if (! $record) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Операции появятся после сохранения торгового места.</div>');
        }

        if (! SchemaFacade::hasTable('operations')) {
            return new HtmlString('<div style="font-size:13px;opacity:.8;">Таблица операций ещё не создана — выполните миграции.</div>');
        }

        $rows = DB::table('operations')
            ->where('market_id', (int) $record->market_id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', (int) $record->id)
            ->orderByDesc('effective_at')
            ->limit(50)
            ->get([
                'effective_at',
                'type',
                'status',
                'payload',
            ]);

        $items = $rows->map(function ($row): array {
            $payload = is_array($row->payload) ? $row->payload : (json_decode((string) $row->payload, true) ?: []);

            $labels = \App\Domain\Operations\OperationType::labels();

            return [
                'effective_at' => $row->effective_at ? (string) \Carbon\Carbon::parse($row->effective_at)->format('d.m.Y H:i') : '—',
                'type' => $labels[$row->type] ?? (string) $row->type,
                'status' => (string) $row->status,
                'summary' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        })->all();

        return new HtmlString(view('filament.market-spaces.operations', [
            'items' => $items,
            'spaceId' => (int) $record->id,
            'reviewUrl' => route('filament.admin.market-map', ['mode' => 'review']),
        ])->render());
    }

    public static function table(Table $table): Table
    {
        $table = $table
            ->columns([
                TextColumn::make('location.name')
                    ->label('Локация')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->tooltip(fn (MarketSpace $record) => $record->location?->name ?: null),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->tooltip(fn (MarketSpace $record) => $record->tenant?->name ?: null),

                TextColumn::make('display_name')
                    ->label('Название')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable()
                    ->tooltip(fn (MarketSpace $record) => $record->display_name ?: null),

                TextColumn::make('number')
                    ->label('Номер')
                    ->sortable()
                    ->searchable()
                    ->tooltip(fn (MarketSpace $record) => $record->number ?: null),

                // "Тип места" — отдельная сущность от локации, по умолчанию прячем
                TextColumn::make('type')
                    ->label('Тип места')
                    ->formatStateUsing(fn (?string $state, MarketSpace $record) => static::resolveSpaceTypeLabel($record->market_id, $state))
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('activity_type')
                    ->label('Вид деятельности')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable()
                    ->tooltip(fn (MarketSpace $record) => $record->activity_type ?: null),

                TextColumn::make('space_group_token')
                    ->label('Группа мест')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('space_group_slot')
                    ->label('Номер в группе')
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => static::statusLabel($state))
                    ->badge()
                    ->color(fn (?string $state) => static::statusColor($state))
                    ->sortable()
                    ->tooltip(function (MarketSpace $record) {
                        $label = static::statusLabel($record->status);
                        return $label ? "Статус: {$label}" : null;
                    }),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->tooltip(fn (MarketSpace $record) => $record->is_active ? 'Активно' : 'Неактивно'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'vacant' => 'Свободно',
                        'occupied' => 'Занято',
                        'reserved' => 'Зарезервировано',
                        'maintenance' => 'На обслуживании',
                    ]),

                SelectFilter::make('type')
                    ->label('Тип места')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $marketId = static::selectedMarketIdFromSession();
                        } else {
                            $marketId = $user?->market_id;
                        }

                        if (blank($marketId)) {
                            return [];
                        }

                        return MarketSpaceType::query()
                            ->where('market_id', (int) $marketId)
                            ->where('is_active', true)
                            ->orderBy('name_ru')
                            ->pluck('name_ru', 'code')
                            ->all();
                    }),

                SelectFilter::make('activity_type')
                    ->label('Вид деятельности')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $marketId = static::selectedMarketIdFromSession();
                        } else {
                            $marketId = $user?->market_id;
                        }

                        if (blank($marketId)) {
                            return [];
                        }

                        return DB::table('market_spaces')
                            ->where('market_id', (int) $marketId)
                            ->whereNotNull('activity_type')
                            ->where('activity_type', '!=', '')
                            ->distinct()
                            ->orderBy('activity_type')
                            ->pluck('activity_type', 'activity_type')
                            ->all();
                    }),

                SelectFilter::make('space_group_token')
                    ->label('Группа мест')
                    ->options(function () {
                        $user = Filament::auth()->user();

                        $marketId = null;
                        if ($user?->isSuperAdmin()) {
                            $marketId = static::selectedMarketIdFromSession();
                        } else {
                            $marketId = $user?->market_id;
                        }

                        if (blank($marketId)) {
                            return [];
                        }

                        if (! SchemaFacade::hasColumn('market_spaces', 'space_group_token')) {
                            return [];
                        }

                        return DB::table('market_spaces')
                            ->where('market_id', (int) $marketId)
                            ->whereNotNull('space_group_token')
                            ->where('space_group_token', '!=', '')
                            ->distinct()
                            ->orderBy('space_group_token')
                            ->pluck('space_group_token', 'space_group_token')
                            ->all();
                    }),
            ])
            ->recordUrl(fn (MarketSpace $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        // Icon-only actions (no text), keep tooltips for usability
        if (class_exists(\Filament\Actions\EditAction::class)) {
            $editAction = \Filament\Actions\EditAction::make()
                ->label('')
                ->tooltip('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->iconButton();

            if (method_exists($editAction, 'slideOver')) {
                $editAction->slideOver();
            }

            if (method_exists($editAction, 'modalWidth')) {
                $editAction->modalWidth('7xl');
            }

            $actions[] = $editAction;
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $editAction = \Filament\Tables\Actions\EditAction::make()
                ->label('')
                ->tooltip('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->iconButton();

            if (method_exists($editAction, 'slideOver')) {
                $editAction->slideOver();
            }

            if (method_exists($editAction, 'modalWidth')) {
                $editAction->modalWidth('7xl');
            }

            $actions[] = $editAction;
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()
                ->label('')
                ->tooltip('Удалить')
                ->icon('heroicon-o-trash')
                ->visible(fn (MarketSpace $record): bool => static::canDelete($record))
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('')
                ->tooltip('Удалить')
                ->icon('heroicon-o-trash')
                ->visible(fn (MarketSpace $record): bool => static::canDelete($record))
                ->iconButton();
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketSpaces::route('/'),
            'create' => Pages\CreateMarketSpace::route('/create'),
            'edit' => Pages\EditMarketSpace::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        $applyOnlyVacant = static function (Builder $builder): Builder {
            if (! request()->boolean('only_vacant')) {
                return $builder;
            }

            return $builder->where('status', 'vacant');
        };

        if (! $user) {
            return $applyOnlyVacant($query->whereRaw('1 = 0'));
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            $query = filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;

            return $applyOnlyVacant($query);
        }

        if ($user->market_id) {
            return $applyOnlyVacant($query->where('market_id', $user->market_id));
        }

        return $applyOnlyVacant($query->whereRaw('1 = 0'));
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $record->market_id === $user->market_id;
    }

    public static function canDelete($record): bool
    {
        if (! $record instanceof MarketSpace) {
            return false;
        }

        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (! $user->isSuperAdmin()) {
            return false;
        }

        return ! static::hasDeleteDependencies($record);
    }

    private static function hasDeleteDependencies(MarketSpace $record): bool
    {
        if (filled($record->tenant_id)) {
            return true;
        }

        $recordId = (int) $record->getKey();

        if ($recordId <= 0) {
            return true;
        }

        $tableChecks = [
            ['tenant_contracts', 'market_space_id'],
            ['tenant_requests', 'market_space_id'],
            ['tenant_accruals', 'market_space_id'],
            ['market_space_map_shapes', 'market_space_id'],
            ['market_space_tenant_histories', 'market_space_id'],
            ['market_space_rent_rate_histories', 'market_space_id'],
            ['market_space_tenant_bindings', 'market_space_id'],
            ['tenant_user_market_spaces', 'market_space_id'],
            ['tenant_space_showcases', 'market_space_id'],
            ['marketplace_products', 'market_space_id'],
            ['marketplace_chats', 'market_space_id'],
            ['tickets', 'market_space_id'],
            ['tenant_reviews', 'market_space_id'],
        ];

        foreach ($tableChecks as [$table, $column]) {
            if (! SchemaFacade::hasTable($table) || ! SchemaFacade::hasColumn($table, $column)) {
                continue;
            }

            if (DB::table($table)->where($column, $recordId)->exists()) {
                return true;
            }
        }

        if (
            SchemaFacade::hasTable('operations')
            && SchemaFacade::hasColumn('operations', 'entity_type')
            && SchemaFacade::hasColumn('operations', 'entity_id')
            && DB::table('operations')
                ->where('entity_type', 'market_space')
                ->where('entity_id', $recordId)
                ->exists()
        ) {
            return true;
        }

        return false;
    }
}
