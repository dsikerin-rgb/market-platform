<?php
# app/Filament/Resources/MarketSpaceResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceResource\Pages;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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

        return $schema->components([
            ...$components,

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
                        ->required()
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

            Section::make('Примечания')
                ->schema([
                    Forms\Components\Textarea::make('notes')
                        ->label('Примечания')
                        ->rows(4)
                        ->hintIcon('heroicon-m-question-mark-circle')
                        ->hintIconTooltip('Свободный комментарий. Это поле не должно перетираться импортом.')
                        ->columnSpanFull(),
                ]),
        ]);
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
