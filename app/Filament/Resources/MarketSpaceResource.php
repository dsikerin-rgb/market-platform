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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    ->dehydrated(true);
            }
        } else {
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        return $schema->components([
            ...$components,

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
                ->helperText('Например: A-101. Внутренний код места формируется автоматически.'),

            Forms\Components\TextInput::make('area_sqm')
                ->label('Площадь, м²')
                ->numeric()
                ->inputMode('decimal'),

            Forms\Components\Select::make('type')
                ->label('Тип')
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
                ->helperText('Типы и тарифы берутся из справочника “Типы мест”.'),

            // Канон: vacant/occupied/reserved/maintenance (legacy "free" нормализуем в vacant)
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
                ->dehydrateStateUsing(fn ($state) => $state === 'free' ? 'vacant' : $state),

            Forms\Components\Toggle::make('is_active')
                ->label('Активно')
                ->default(true),

            Forms\Components\Textarea::make('notes')
                ->label('Примечания')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $statusOptions = [
            'vacant' => 'Свободно',
            'occupied' => 'Занято',
            'reserved' => 'Зарезервировано',
            'maintenance' => 'На обслуживании',
            'free' => 'Свободно', // legacy
        ];

        $table = $table
            ->columns([
                // Колонку "Рынок" скрыли намеренно:
                // для обычного пользователя рынок однозначен,
                // для super-admin есть переключатель рынка.

                TextColumn::make('location.name')
                    ->label('Локация')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('number')
                    ->label('Номер')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('spaceType.name_ru')
                    ->label('Тип')
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => $statusOptions[$state ?? ''] ?? $state)
                    ->sortable()
                    ->badge(),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
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
