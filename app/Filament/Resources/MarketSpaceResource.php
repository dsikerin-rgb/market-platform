<?php
# app/Filament/Resources/MarketSpaceResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceResource\Pages;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
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

class MarketSpaceResource extends Resource
{
    protected static ?string $model = MarketSpace::class;

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
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        return $user->isSuperAdmin() ? 'Рынки' : 'Рынок';
    }

    public static function getNavigationSort(): int
    {
        return 30;
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
                        Section::make('Карта')
                            ->schema([
                                Forms\Components\Placeholder::make('map_status')
                                    ->hiddenLabel()
                                    ->content(function (?MarketSpace $record): HtmlString {
                                        if (! $record) {
                                            return new HtmlString('');
                                        }

                                        $isMapLinked = false;

                                        if (SchemaFacade::hasTable('market_space_map_shapes')) {
                                            $isMapLinked = MarketSpaceMapShape::query()
                                                ->where('market_id', (int) $record->market_id)
                                                ->where('market_space_id', (int) $record->id)
                                                ->where('is_active', true)
                                                ->exists();
                                        }

                                        $statusText = $isMapLinked
                                            ? 'Торговое место привязано к карте.'
                                            : 'Торговое место не привязано к объектам карты.';

                                        return new HtmlString(view('admin.market-space-edit', [
                                            'isMapLinked' => $isMapLinked,
                                            'statusText' => $statusText,
                                        ])->render());
                                    })
                                    ->visible(fn (?MarketSpace $record): bool => (bool) $record),
                            ])
                            ->columns(1),

                        Section::make('Основные данные')
                            ->description('Заполни основные параметры торгового места. Подсказки доступны при наведении на иконку вопроса.')
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
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                            ->all();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Текущий арендатор (если место занято). Для “Свободно” арендатора можно не выбирать.')
                                    ->disabled(function ($get, ?MarketSpace $record) use ($user) {
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
                                    ->afterStateUpdated(function ($state, callable $set, callable $get) {
                                        // Для создания: если display_name пуст — подставляем "Место {number}"
                                        if (blank($get('display_name')) && filled($state)) {
                                            $set('display_name', 'Место ' . trim((string) $state));
                                        }
                                    })
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Короткий идентификатор места. Используется в поиске и в импорте начислений.'),

                                Forms\Components\TextInput::make('display_name')
                                    ->label('Название (для отображения)')
                                    ->maxLength(255)
                                    ->placeholder('Например: Аптека 22')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Понятное название для пользователей. Обычно заполняется импортом, но можно редактировать вручную.')
                                    ->nullable(),

                                Forms\Components\TextInput::make('activity_type')
                                    ->label('Вид деятельности')
                                    ->maxLength(255)
                                    ->placeholder('Например: аптека / электро / мясо')
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Заполняется импортом начислений и может уточняться вручную.')
                                    ->nullable(),

                                Forms\Components\Select::make('type')
                                    ->label('Тариф')
                                    ->options(function ($get, ?MarketSpace $record) use ($user) {
                                        $marketId = $get('market_id') ?? $record?->market_id;

                                        if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                                            $marketId = $user->market_id;
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
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->reactive()
                                    ->nullable()
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Тариф/категория места для расчётов. Берётся из справочника “Типы мест”.'),

                                Forms\Components\TextInput::make('area_sqm')
                                    ->label('Площадь, м²')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->placeholder('Например: 48')
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
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Используется для быстрой визуальной оценки занятости. В таблице помечается цветом.'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Активно')
                                    ->default(true)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Если выключить — место скрывается из большинства сценариев, но данные остаются в системе.'),
                            ])
                            ->columns(2),

                        Section::make('Ставка аренды')
                            ->description('Управленческая ставка (не начисления). История изменений хранится отдельно.')
                            ->schema([
                                Forms\Components\TextInput::make('rent_rate_value')
                                    ->label('Ставка аренды')
                                    ->numeric()
                                    ->inputMode('decimal')
                                    ->placeholder('Например: 1500'),

                                Forms\Components\Select::make('rent_rate_unit')
                                    ->label('Единица ставки')
                                    ->options(static::rentRateUnitOptions())
                                    ->placeholder('Не указано')
                                    ->nullable(),

                                Forms\Components\Placeholder::make('rent_rate_updated_at')
                                    ->label('Обновлено')
                                    ->content(function (?MarketSpace $record): string {
                                        if (! $record?->rent_rate_updated_at) {
                                            return '—';
                                        }

                                        return $record->rent_rate_updated_at->format('d.m.Y H:i');
                                    }),
                            ])
                            ->columns(2),

                        Section::make('Примечания')
                            ->schema([
                                Forms\Components\Textarea::make('notes')
                                    ->label('Примечания')
                                    ->rows(4)
                                    ->hintIcon('heroicon-m-question-mark-circle')
                                    ->hintIconTooltip('Свободный комментарий. Это поле не должно перетираться импортом.')
                                    ->columnSpanFull(),
                            ]),
                    ]),
                Tab::make('История')
                    ->schema([
                        Section::make('История арендаторов')
                            ->schema([
                                Forms\Components\Placeholder::make('tenant_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderTenantHistory($record))
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('История ставки')
                            ->schema([
                                Forms\Components\Placeholder::make('rent_rate_history')
                                    ->hiddenLabel()
                                    ->dehydrated(false)
                                    ->content(fn (?MarketSpace $record): HtmlString => static::renderRentRateHistory($record))
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

                // "Тариф" — отдельная сущность от локации, по умолчанию прячем
                TextColumn::make('type')
                    ->label('Тариф')
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
                    ->label('Тариф')
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
            ])
            ->recordUrl(fn (MarketSpace $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        // Icon-only actions (no text), keep tooltips for usability
        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()
                ->label('')
                ->tooltip('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()
                ->label('')
                ->tooltip('Редактировать')
                ->icon('heroicon-o-pencil-square')
                ->iconButton();
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()
                ->label('')
                ->tooltip('Удалить')
                ->icon('heroicon-o-trash')
                ->iconButton();
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()
                ->label('')
                ->tooltip('Удалить')
                ->icon('heroicon-o-trash')
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

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isSuperAdmin()) {
            $selectedMarketId = static::selectedMarketIdFromSession();

            return filled($selectedMarketId)
                ? $query->where('market_id', (int) $selectedMarketId)
                : $query;
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
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $record->market_id === $user->market_id;
    }
}
