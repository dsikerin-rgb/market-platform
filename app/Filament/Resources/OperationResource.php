<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Operations\OperationType;
use App\Filament\Resources\OperationResource\Pages;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Services\Operations\MarketPeriodResolver;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class OperationResource extends Resource
{
    protected static ?string $model = Operation::class;

    protected static ?string $modelLabel = 'Операция';
    protected static ?string $pluralModelLabel = 'Операции';
    protected static ?string $navigationLabel = 'Операции';
    protected static ?string $navigationGroup = 'Управление';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $marketId = static::resolveMarketId();
        $market = $marketId > 0 ? Market::query()->find($marketId) : null;
        $resolver = app(MarketPeriodResolver::class);
        $tz = $market ? (string) ($market->timezone ?: config('app.timezone', 'UTC')) : (string) config('app.timezone', 'UTC');

        $periodInput = request()->query('period');
        $focus = (string) (request()->query('focus') ?? '');
        $returnUrl = request()->query('return_url');
        $spaceId = request()->query('market_space_id') ?? request()->query('entity_id');
        $space = $spaceId ? MarketSpace::query()->find((int) $spaceId) : null;
        $spaceLabel = $space?->display_name ?: ($space?->number ?: ($space?->code ?: null));
        $contextHtml = null;

        if ($spaceLabel || $returnUrl) {
            $title = $spaceLabel ? ('Операция создаётся для места: <strong>' . e((string) $spaceLabel) . '</strong>') : 'Операция создаётся для места';
            $back = $returnUrl ? ('<div style="margin-top:6px;"><a href="' . e((string) $returnUrl) . '" style="text-decoration:underline;">Вернуться к месту</a></div>') : '';
            $contextHtml = new HtmlString('<div style="font-size:13px;opacity:.85;">' . $title . $back . '</div>');
        }
        $period = $market ? $resolver->resolveMarketPeriod($market, is_string($periodInput) ? $periodInput : null) : CarbonImmutable::now($tz)->startOfMonth();

        $components = [];

        if ($contextHtml) {
            $components[] = Section::make('Контекст')
                ->schema([
                    Forms\Components\Placeholder::make('operation_context')
                        ->hiddenLabel()
                        ->content(fn () => $contextHtml),
                ])
                ->columnSpanFull();
        }

        return $schema->components([
            ...$components,
            Section::make('Основные параметры')
                ->schema([
                    Forms\Components\Hidden::make('market_id')
                        ->default(fn () => $marketId)
                        ->dehydrated(true),

                    Forms\Components\Select::make('type')
                        ->label('Тип операции')
                        ->options(OperationType::labels())
                        ->default(fn () => request()->query('type'))
                        ->required()
                        ->reactive(),

                    Forms\Components\Select::make('entity_type')
                        ->label('Объект')
                        ->options([
                            'market_space' => 'Торговое место',
                            'tenant' => 'Арендатор',
                            'contract' => 'Договор',
                            'market' => 'Рынок',
                        ])
                        ->default(fn () => request()->query('entity_type') ?: 'market_space')
                        ->reactive()
                        ->required(),

                    Forms\Components\Select::make('entity_id')
                        ->label('ID объекта')
                        ->options(function ($get) use ($marketId) {
                            $entityType = $get('entity_type');

                            if ($entityType === 'market_space') {
                                return MarketSpace::query()
                                    ->where('market_id', $marketId)
                                    ->orderBy('number')
                                    ->pluck('number', 'id')
                                    ->all();
                            }

                            if ($entityType === 'tenant') {
                                return Tenant::query()
                                    ->where('market_id', $marketId)
                                    ->orderBy('name')
                                    ->pluck('name', 'id')
                                    ->all();
                            }

                            return [];
                        })
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->required(fn ($get) => $get('entity_type') !== 'market')
                        ->default(fn () => request()->query('entity_id')),

                    Forms\Components\DateTimePicker::make('effective_at')
                        ->label('Дата вступления в силу')
                        ->helperText('Укажите дату и время в часовом поясе рынка.')
                        ->default(fn () => $period->startOfMonth()->format('Y-m-d 00:00:00'))
                        ->seconds(false)
                        ->required(),

                    Forms\Components\Select::make('status')
                        ->label('Статус')
                        ->options([
                            'draft' => 'Черновик',
                            'applied' => 'Применено',
                            'canceled' => 'Отменено',
                        ])
                        ->default('applied')
                        ->required(),

                    Forms\Components\Textarea::make('comment')
                        ->label('Комментарий')
                        ->rows(3)
                        ->nullable(),
                ])
                ->columns(2),

            Section::make('Данные операции')
                ->schema([
                    Forms\Components\Select::make('payload.market_space_id')
                        ->label('Торговое место')
                        ->options(fn () => MarketSpace::query()
                            ->where('market_id', $marketId)
                            ->orderBy('number')
                            ->pluck('number', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->default(fn () => request()->query('entity_id'))
                        ->required(fn ($get) => in_array($get('type'), [
                            OperationType::TENANT_SWITCH,
                            OperationType::RENT_RATE_CHANGE,
                            OperationType::SPACE_ATTRS_CHANGE,
                            OperationType::ELECTRICITY_INPUT,
                            OperationType::ACCRUAL_ADJUSTMENT,
                        ], true))
                        ->visible(fn ($get) => in_array($get('type'), [
                            OperationType::TENANT_SWITCH,
                            OperationType::RENT_RATE_CHANGE,
                            OperationType::SPACE_ATTRS_CHANGE,
                            OperationType::ELECTRICITY_INPUT,
                            OperationType::ACCRUAL_ADJUSTMENT,
                        ], true)),

                    Forms\Components\Select::make('payload.from_tenant_id')
                        ->label('Текущий арендатор')
                        ->options(fn () => Tenant::query()
                            ->where('market_id', $marketId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->disabled()
                        ->default(fn () => request()->query('from_tenant_id'))
                        ->visible(fn ($get) => $get('type') === OperationType::TENANT_SWITCH),

                    Forms\Components\Select::make('payload.to_tenant_id')
                        ->label('Новый арендатор')
                        ->options(fn () => Tenant::query()
                            ->where('market_id', $marketId)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->preload()
                        ->nullable()
                        ->extraInputAttributes($focus === 'to_tenant_id' ? ['autofocus' => true] : [])
                        ->visible(fn ($get) => $get('type') === OperationType::TENANT_SWITCH),

                    Forms\Components\TextInput::make('payload.from_rent_rate')
                        ->label('Текущая ставка')
                        ->numeric()
                        ->inputMode('decimal')
                        ->disabled()
                        ->default(fn () => request()->query('from_rent_rate'))
                        ->visible(fn ($get) => $get('type') === OperationType::RENT_RATE_CHANGE),

                    Forms\Components\TextInput::make('payload.rent_rate')
                        ->label('Новая ставка')
                        ->numeric()
                        ->inputMode('decimal')
                        ->extraInputAttributes($focus === 'to_rent_rate' ? ['autofocus' => true] : [])
                        ->visible(fn ($get) => $get('type') === OperationType::RENT_RATE_CHANGE),

                    Forms\Components\Select::make('payload.unit')
                        ->label('Единица ставки')
                        ->options([
                            'per_sqm_month' => 'за м² в месяц',
                            'per_space_month' => 'за место в месяц',
                        ])
                        ->placeholder('Не указано')
                        ->visible(fn ($get) => $get('type') === OperationType::RENT_RATE_CHANGE),

                    Forms\Components\TextInput::make('payload.area_sqm')
                        ->label('Площадь, м²')
                        ->numeric()
                        ->inputMode('decimal')
                        ->visible(fn ($get) => $get('type') === OperationType::SPACE_ATTRS_CHANGE),

                    Forms\Components\TextInput::make('payload.activity_type')
                        ->label('Вид деятельности')
                        ->visible(fn ($get) => $get('type') === OperationType::SPACE_ATTRS_CHANGE),

                    Forms\Components\Select::make('payload.location_id')
                        ->label('Локация')
                        ->options(fn () => MarketSpace::query()
                            ->where('market_id', $marketId)
                            ->whereNotNull('location_id')
                            ->distinct()
                            ->pluck('location_id', 'location_id')
                            ->all())
                        ->visible(fn ($get) => $get('type') === OperationType::SPACE_ATTRS_CHANGE),

                    Forms\Components\TextInput::make('payload.amount')
                        ->label('Электроэнергия')
                        ->numeric()
                        ->inputMode('decimal')
                        ->visible(fn ($get) => $get('type') === OperationType::ELECTRICITY_INPUT),

                    Forms\Components\TextInput::make('payload.amount_delta')
                        ->label('Корректировка (±)')
                        ->numeric()
                        ->inputMode('decimal')
                        ->visible(fn ($get) => $get('type') === OperationType::ACCRUAL_ADJUSTMENT),

                    Forms\Components\Textarea::make('payload.reason')
                        ->label('Причина')
                        ->rows(2)
                        ->visible(fn ($get) => in_array($get('type'), [
                            OperationType::TENANT_SWITCH,
                            OperationType::ACCRUAL_ADJUSTMENT,
                        ], true)),

                    Forms\Components\TextInput::make('payload.period')
                        ->label('Период закрытия (YYYY-MM-01)')
                        ->default(fn () => $period->toDateString())
                        ->visible(fn ($get) => $get('type') === OperationType::PERIOD_CLOSE),

                    Forms\Components\Toggle::make('payload.closed')
                        ->label('Период закрыт')
                        ->default(true)
                        ->visible(fn ($get) => $get('type') === OperationType::PERIOD_CLOSE),
                ])
                ->columns(2),

            Section::make('Подсказка')
                ->schema([
                    Forms\Components\Placeholder::make('help')
                        ->hiddenLabel()
                        ->content(fn (): HtmlString => new HtmlString(
                            '<div style="font-size:13px;opacity:.8;">' .
                            'Операции фиксируют изменения по датам и не перетирают историю. ' .
                            'Если нужно отменить действие — создайте компенсирующую операцию.' .
                            '</div>'
                        )),
                ])
                ->columnSpanFull(),
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
                    ->formatStateUsing(fn ($state) => $state ? CarbonImmutable::parse($state)->timezone($tz)->format('d.m.Y H:i') : '—')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state) => OperationType::labels()[$state] ?? $state)
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
                    ->color(fn (?string $state) => match ($state) {
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

                        $user = \App\Models\User::query()->find($record->created_by);
                        return $user?->name ?: '—';
                    }),

                TextColumn::make('summary')
                    ->label('Сводка')
                    ->formatStateUsing(fn ($state, Operation $record): string => static::summaryForOperation($record)),
            ])
            ->filters([
                SelectFilter::make('effective_month')
                    ->label('Период')
                    ->options(fn () => static::periodOptions()),

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
            return $query->where('market_id', $user->market_id);
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

        return (bool) $user && ($user->isSuperAdmin() || $user->isMarketAdmin());
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (! ($user->isSuperAdmin() || $user->isMarketAdmin())) {
            return false;
        }

        return $record instanceof Operation && $record->status === 'draft';
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

        return $record instanceof Operation && $record->market_id === $user->market_id;
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
        $tz = $market ? $resolver->marketNow($market)->getTimezone()->getName() : config('app.timezone', 'UTC');

        return $resolver->availablePeriods($marketId, $tz);
    }

    private static function summaryForOperation(Operation $record): string
    {
        $payload = is_array($record->payload) ? $record->payload : [];

        return match ($record->type) {
            OperationType::TENANT_SWITCH => sprintf(
                'Арендатор: %s → %s',
                static::tenantLabel($payload['from_tenant_id'] ?? null),
                static::tenantLabel($payload['to_tenant_id'] ?? null)
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
}
