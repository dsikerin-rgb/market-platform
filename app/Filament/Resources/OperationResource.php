<?php

# app/Filament/Resources/OperationResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Operations\OperationType;
use App\Filament\Resources\OperationResource\Pages;
use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Operations\MarketPeriodResolver;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class OperationResource extends BaseResource
{
    

    protected static ?string $model = Operation::class;

    protected static ?string $recordTitleAttribute = 'type';

    protected static ?string $modelLabel = 'Управленческая операция';
    protected static ?string $pluralModelLabel = 'Управленческие операции';
    protected static ?string $navigationLabel = 'Управленческие операции';

    // Filament v4 требует именно UnitEnum|string|null
    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?int $navigationSort = 100;

    
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'type',
            'entity_type',
            'status',
            'effective_month',
        ];
    }
    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        $marketId = static::resolveMarketId();
        $market = $marketId > 0 ? Market::query()->find($marketId) : null;

        $resolver = app(MarketPeriodResolver::class);

        $tz = $market
            ? (string) ($market->timezone ?: config('app.timezone', 'UTC'))
            : (string) config('app.timezone', 'UTC');

        $periodInput = request()->query('period');
        $focus = (string) (request()->query('focus') ?? '');
        $returnUrl = request()->query('return_url');

        $spaceId = request()->query('market_space_id') ?? request()->query('entity_id');
        $space = $spaceId ? MarketSpace::query()->find((int) $spaceId) : null;
        $spaceLabel = $space?->display_name ?: ($space?->number ?: ($space?->code ?: null));

        $contextHtml = null;
        if ($spaceLabel || $returnUrl) {
            $title = $spaceLabel
                ? ('Операция создаётся для места: <strong>' . e((string) $spaceLabel) . '</strong>')
                : 'Операция создаётся для места';

            $back = $returnUrl
                ? ('<div style="margin-top:6px;"><a href="' . e((string) $returnUrl) . '" style="text-decoration:underline;">Вернуться к месту</a></div>')
                : '';

            $contextHtml = new HtmlString('<div style="font-size:13px;opacity:.85;">' . $title . $back . '</div>');
        }

        $period = $market
            ? $resolver->resolveMarketPeriod($market, is_string($periodInput) ? $periodInput : null)
            : CarbonImmutable::now($tz)->startOfMonth();

        $components = [];

        if ($contextHtml) {
            $components[] = Section::make('Контекст')
                ->schema([
                    Forms\Components\Placeholder::make('operation_context')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => $contextHtml),
                ])
                ->columnSpanFull();
        }

        $typeSelect = Forms\Components\Select::make('type')
            ->label('Тип операции')
            ->options(OperationType::managementLabels())
            ->default(function () {
                $requestedType = (string) request()->query('type');

                return in_array($requestedType, OperationType::managementValues(), true)
                    ? $requestedType
                    : null;
            })
            ->hintIcon('heroicon-m-question-mark-circle')
            ->hintIconTooltip('Этот журнал предназначен только для локальных управленческих действий. Договоры, ставка аренды и финансовая истина остаются в 1С.')
            ->required();

        static::makeLive($typeSelect);

        if (method_exists($typeSelect, 'afterStateUpdated')) {
            $typeSelect->afterStateUpdated(function (Set $set, $state): void {
                $isPeriodClose = (string) $state === OperationType::PERIOD_CLOSE;

                $set('entity_type', $isPeriodClose ? 'market' : 'market_space');

                if ($isPeriodClose) {
                    $set('entity_id', null);
                    $set('payload.market_space_id', null);
                    $set('payload.from_tenant_id', null);
                    $set('payload.to_tenant_id', null);
                    $set('payload.from_rent_rate', null);
                    $set('payload.rent_rate', null);
                    $set('payload.unit', null);
                }
            });
        }

        $effectiveAt = Forms\Components\DateTimePicker::make('effective_at')
            ->label('Дата вступления в силу')
            ->helperText('Укажите дату и время в часовом поясе рынка.')
            ->default(fn () => $period->startOfMonth())
            ->seconds(false)
            ->required();

        if (method_exists($effectiveAt, 'timezone')) {
            $effectiveAt->timezone($tz);
        }

        $syncMarketSpaceContext = function (Set $set, mixed $state) use ($marketId): void {
            $spaceId = is_numeric($state) ? (int) $state : 0;

            if ($spaceId <= 0) {
                $set('entity_id', null);
                $set('payload.from_tenant_id', null);
                $set('payload.from_rent_rate', null);
                return;
            }

            $space = MarketSpace::query()
                ->where('market_id', $marketId)
                ->whereKey($spaceId)
                ->first(['id', 'tenant_id', 'rent_rate_value', 'rent_rate_unit']);

            if (! $space) {
                $set('entity_id', null);
                $set('payload.from_tenant_id', null);
                $set('payload.from_rent_rate', null);
                return;
            }

            $set('entity_id', (int) $space->id);
            $set('payload.from_tenant_id', $space->tenant_id ? (int) $space->tenant_id : null);
            $set('payload.from_rent_rate', $space->rent_rate_value !== null ? (float) $space->rent_rate_value : null);

            if (filled($space->rent_rate_unit)) {
                $set('payload.unit', (string) $space->rent_rate_unit);
            }
        };

        $marketSpaceSelect = Forms\Components\Select::make('payload.market_space_id')
            ->label('Торговое место')
            ->options(function () use ($marketId): array {
                return MarketSpace::query()
                    ->where('market_id', $marketId)
                    ->orderBy('number')
                    ->get(['id', 'number', 'display_name'])
                    ->mapWithKeys(function (MarketSpace $space): array {
                        $number = trim((string) ($space->number ?? ''));
                        $displayName = trim((string) ($space->display_name ?? ''));
                        $label = $number !== ''
                            ? $number . ($displayName !== '' ? ' — ' . $displayName : '')
                            : ($displayName !== '' ? $displayName : (string) $space->id);

                        return [(int) $space->id => $label];
                    })
                    ->all();
            })
            ->searchable()
            ->preload()
            ->default(function () {
                $requestEntityId = request()->query('entity_id');

                return is_numeric($requestEntityId) ? (int) $requestEntityId : null;
            })
            ->required(fn (Get $get): bool => (string) $get('type') !== OperationType::PERIOD_CLOSE)
            ->visible(fn (Get $get): bool => (string) $get('type') !== OperationType::PERIOD_CLOSE)
            ->hintIcon('heroicon-m-question-mark-circle')
            ->hintIconTooltip('Выбор места, к которому применяется операция.')
            ->afterStateUpdated(fn (Set $set, $state) => $syncMarketSpaceContext($set, $state));

        if (method_exists($marketSpaceSelect, 'afterStateHydrated')) {
            $marketSpaceSelect->afterStateHydrated(function (Get $get, Set $set, $state) use ($syncMarketSpaceContext): void {
                if (filled($state)) {
                    $syncMarketSpaceContext($set, $state);
                    return;
                }

                $entityId = $get('entity_id');
                if (filled($entityId)) {
                    $set('payload.market_space_id', (int) $entityId);
                    $syncMarketSpaceContext($set, $entityId);
                }
            });
        }

        return $schema->components([
            ...$components,

            Section::make('Что меняем')
                ->schema([
                    Forms\Components\Hidden::make('market_id')
                        ->default(fn () => $marketId)
                        ->dehydrated(true),

                    Forms\Components\Hidden::make('entity_type')
                        ->default(function () {
                            $requestedType = (string) (request()->query('type') ?? '');

                            return $requestedType === OperationType::PERIOD_CLOSE
                                ? 'market'
                                : 'market_space';
                        })
                        ->dehydrated(true),

                    Forms\Components\Hidden::make('entity_id')
                        ->default(function () {
                            $requestEntityId = request()->query('entity_id');

                            return is_numeric($requestEntityId) ? (int) $requestEntityId : null;
                        })
                        ->dehydrated(true),

                    $typeSelect,

                    $marketSpaceSelect,

                    $effectiveAt,
                ])
                ->columns(2),

            Section::make('Параметры операции')
                ->schema([
                    Forms\Components\Select::make('payload.from_tenant_id')
                        ->label('Текущий арендатор')
                        ->options(fn () => Tenant::query()
                            ->where('market_id', $marketId)
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->disabled()
                        ->default(fn () => request()->query('from_tenant_id'))
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Справочное поле. Подставляется автоматически из карточки места.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::TENANT_SWITCH),

                    Forms\Components\Select::make('payload.to_tenant_id')
                        ->label('Новый арендатор')
                        ->options(fn () => Tenant::query()
                            ->where('market_id', $marketId)
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->required(fn (Get $get): bool => (string) $get('type') === OperationType::TENANT_SWITCH)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('После сохранения и статуса "Применено" место будет закреплено за выбранным арендатором.')
                        ->extraInputAttributes($focus === 'to_tenant_id' ? ['autofocus' => true] : [])
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::TENANT_SWITCH),

                    Forms\Components\TextInput::make('payload.from_rent_rate')
                        ->label('Текущая ставка')
                        ->numeric()
                        ->inputMode('decimal')
                        ->disabled()
                        ->default(fn () => request()->query('from_rent_rate'))
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Справочное значение текущей ставки на дату операции.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::RENT_RATE_CHANGE),

                    Forms\Components\TextInput::make('payload.rent_rate')
                        ->label('Новая ставка')
                        ->numeric()
                        ->inputMode('decimal')
                        ->required(fn (Get $get): bool => (string) $get('type') === OperationType::RENT_RATE_CHANGE)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Новое значение ставки. Применяется после сохранения операции со статусом "Применено".')
                        ->extraInputAttributes($focus === 'to_rent_rate' ? ['autofocus' => true] : [])
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::RENT_RATE_CHANGE),

                    Forms\Components\Select::make('payload.unit')
                        ->label('Единица ставки')
                        ->options([
                            'per_sqm_month' => 'за м² в месяц',
                            'per_space_month' => 'за место в месяц',
                        ])
                        ->placeholder('Не указано')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Если не указать, сохранится текущее значение из карточки места.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::RENT_RATE_CHANGE),

                    Forms\Components\TextInput::make('payload.area_sqm')
                        ->label('Площадь, м²')
                        ->numeric()
                        ->inputMode('decimal')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Изменение характеристик места. Оставьте пустым, если площадь не меняется.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::SPACE_ATTRS_CHANGE),

                    Forms\Components\TextInput::make('payload.activity_type')
                        ->label('Вид деятельности')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Новый вид деятельности для карточки торгового места.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::SPACE_ATTRS_CHANGE),

                    Forms\Components\Select::make('payload.location_id')
                        ->label('Локация')
                        ->options(fn () => MarketLocation::query()
                            ->where('market_id', $marketId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Новая локация торгового места.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::SPACE_ATTRS_CHANGE),

                    Forms\Components\TextInput::make('payload.amount')
                        ->label('Электроэнергия, ₽')
                        ->numeric()
                        ->inputMode('decimal')
                        ->required(fn (Get $get): bool => (string) $get('type') === OperationType::ELECTRICITY_INPUT)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Ручной ввод значения электроэнергии за период.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::ELECTRICITY_INPUT),

                    Forms\Components\TextInput::make('payload.amount_delta')
                        ->label('Корректировка (±)')
                        ->numeric()
                        ->inputMode('decimal')
                        ->required(fn (Get $get): bool => (string) $get('type') === OperationType::ACCRUAL_ADJUSTMENT)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Положительное значение увеличит сумму, отрицательное уменьшит.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::ACCRUAL_ADJUSTMENT),

                    Forms\Components\Textarea::make('payload.reason')
                        ->label('Причина')
                        ->rows(2)
                        ->placeholder('Кратко укажите причину изменения…')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Причина фиксируется в payload операции для аудита.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::ACCRUAL_ADJUSTMENT),

                    Forms\Components\TextInput::make('payload.period')
                        ->label('Период закрытия (YYYY-MM-01)')
                        ->default(fn () => $period->toDateString())
                        ->required(fn (Get $get): bool => (string) $get('type') === OperationType::PERIOD_CLOSE)
                        ->rule('date_format:Y-m-d')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Период, который нужно закрыть. Формат: YYYY-MM-01.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::PERIOD_CLOSE),

                    Forms\Components\Toggle::make('payload.closed')
                        ->label('Период закрыт')
                        ->default(true)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Включите, чтобы зафиксировать закрытие выбранного периода.')
                        ->visible(fn (Get $get): bool => (string) $get('type') === OperationType::PERIOD_CLOSE),
                ])
                ->columns(2),

            Section::make('Применение')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label('Статус применения')
                        ->options([
                            'draft' => 'Черновик',
                            'applied' => 'Применено',
                            'canceled' => 'Отменено',
                        ])
                        ->default('applied')
                        ->required()
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Только статус "Применено" влияет на фактическое состояние места.'),

                    Forms\Components\Textarea::make('comment')
                        ->label('Комментарий')
                        ->rows(3)
                        ->placeholder('Комментарий для команды и истории изменений…')
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Необязательное поле. Удобно для аудита и пояснений.')
                        ->nullable(),
                ])
                ->columns(2)
                ->collapsible(),

            Section::make('Как это работает')
                ->schema([
                    Forms\Components\Placeholder::make('help')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => new HtmlString(
                            '<div style="font-size:13px;opacity:.8;">' .
                            '1С остается источником финансовой истины. Этот журнал используется только для локальных управленческих действий по месту и периоду. ' .
                            'Смена арендатора и ставка аренды больше не ведутся через операции. Для отмены используйте компенсирующую операцию, а не ручное переписывание истории.' .
                            '</div>'
                        )),
                ])
                ->columnSpanFull()
                ->collapsible()
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $tz = static::resolveMarketTimezone();

        return $table
            ->columns([
                TextColumn::make('effective_month')
                    ->label('Период')
                    ->date('Y-m')
                    ->sortable(),

                TextColumn::make('effective_at')
                    ->label('Дата/время')
                    ->formatStateUsing(function ($state) use ($tz): string {
                        if (! $state) {
                            return '—';
                        }

                        try {
                            if ($state instanceof \DateTimeInterface) {
                                return CarbonImmutable::instance($state)->timezone($tz)->format('d.m.Y H:i');
                            }

                            return CarbonImmutable::parse((string) $state)->timezone($tz)->format('d.m.Y H:i');
                        } catch (\Throwable) {
                            return '—';
                        }
                    })
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state): string => OperationType::labels()[$state] ?? (string) $state)
                    ->sortable(),

                TextColumn::make('entity')
                    ->label('Объект')
                    ->formatStateUsing(function ($state, Operation $record): string {
                        if ($record->entity_type === 'market_space') {
                            $space = MarketSpace::query()->find($record->entity_id);
                            $label = $space?->number ?: ($space?->code ?: (string) $record->entity_id);
                            return 'Место: ' . $label;
                        }

                        if ($record->entity_type === 'tenant') {
                            $tenant = Tenant::query()->find($record->entity_id);
                            $label = $tenant?->name ?: (string) $record->entity_id;
                            return 'Арендатор: ' . $label;
                        }

                        return (string) $record->entity_type;
                    }),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'draft' => 'warning',
                        'applied' => 'success',
                        'canceled' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('created_by')
                    ->label('Создал')
                    ->formatStateUsing(function ($state, Operation $record): string {
                        if (! $record->created_by) {
                            return '—';
                        }

                        $user = User::query()->find((int) $record->created_by);

                        return $user?->name ?: '—';
                    }),

                TextColumn::make('summary')
                    ->label('Сводка')
                    ->formatStateUsing(fn ($state, Operation $record): string => static::summaryForOperation($record)),
            ])
            ->filters([
                SelectFilter::make('effective_month')
                    ->label('Период')
                    ->options(fn (): array => static::periodOptions()),

                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(OperationType::labels()),

                SelectFilter::make('entity_type')
                    ->label('Объект')
                    ->options([
                        'market_space' => 'Торговое место',
                        'tenant' => 'Арендатор',
                        'contract' => 'Договор',
                        'market' => 'Рынок',
                    ]),

                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'draft' => 'Черновик',
                        'applied' => 'Применено',
                        'canceled' => 'Отменено',
                    ]),
            ])
            ->defaultSort('effective_at', 'desc')
            ->recordUrl(fn (Operation $record): ?string => static::getUrl('view', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOperations::route('/'),
            'create' => Pages\CreateOperation::route('/create'),
            'view' => Pages\ViewOperation::route('/{record}'),
            'edit' => Pages\EditOperation::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $marketId = static::resolveMarketId();

            return $marketId > 0 ? $query->where('market_id', $marketId) : $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || (bool) $user->market_id);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && ($user->isSuperAdmin() || $user->hasRole('market-admin'));
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (! ($user->isSuperAdmin() || $user->hasRole('market-admin'))) {
            return false;
        }

        return $record instanceof Operation
            && in_array((string) $record->type, OperationType::managementValues(), true)
            && (string) $record->status === 'draft';
    }

    public static function canView($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $record instanceof Operation && (int) $record->market_id === (int) $user->market_id;
    }

    public static function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! $user->isSuperAdmin()) {
            return (int) ($user->market_id ?: 0);
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        return (int) ($value ?: 0);
    }

    public static function resolveMarketTimezone(): string
    {
        $marketId = static::resolveMarketId();
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
     * @return array<string, string>
     */
    private static function periodOptions(): array
    {
        $marketId = static::resolveMarketId();
        $market = $marketId > 0 ? Market::query()->find($marketId) : null;

        $resolver = app(MarketPeriodResolver::class);

        $tz = $market
            ? $resolver->marketNow($market)->getTimezone()->getName()
            : (string) config('app.timezone', 'UTC');

        return $resolver->availablePeriods($marketId, $tz);
    }

    private static function summaryForOperation(Operation $record): string
    {
        $payload = is_array($record->payload) ? $record->payload : [];

        return match ($record->type) {
            OperationType::TENANT_SWITCH => sprintf(
                'Арендатор: %s → %s',
                static::tenantLabel($payload['from_tenant_id'] ?? null),
                static::tenantLabel($payload['to_tenant_id'] ?? null),
            ),
            OperationType::RENT_RATE_CHANGE => 'Ставка: ' . (string) ($payload['rent_rate'] ?? '—'),
            OperationType::ELECTRICITY_INPUT => 'Электроэнергия: ' . (string) ($payload['amount'] ?? '—'),
            OperationType::ACCRUAL_ADJUSTMENT => 'Корректировка: ' . (string) ($payload['amount_delta'] ?? '—'),
            OperationType::SPACE_ATTRS_CHANGE => 'Изменение характеристик',
            OperationType::PERIOD_CLOSE => 'Закрытие периода',
            default => '—',
        };
    }

    private static function tenantLabel(mixed $tenantId): string
    {
        $id = is_numeric($tenantId) ? (int) $tenantId : 0;

        if ($id <= 0) {
            return '—';
        }

        $tenant = Tenant::query()->find($id);

        return $tenant?->name ?: (string) $id;
    }

    private static function makeLive(object $component): void
    {
        if (method_exists($component, 'live')) {
            $component->live();
            return;
        }

        if (method_exists($component, 'reactive')) {
            $component->reactive();
        }
    }
}
