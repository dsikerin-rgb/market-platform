<?php

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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketSpaceResource extends Resource
{
    protected static ?string $model = MarketSpace::class;

    protected static ?string $modelLabel = 'Торговое место';
    protected static ?string $pluralModelLabel = 'Торговые места';
    protected static ?string $navigationLabel = 'Торговые места';

    protected static \UnitEnum|string|null $navigationGroup = 'Рынки';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home-modern';

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = session('filament.admin.selected_market_id');

        // Показываем выбор рынка super-admin только если не выбран рынок через переключатель
        $marketSelect = Forms\Components\Select::make('market_id')
            ->label('Рынок')
            ->relationship('market', 'name')
            ->required()
            ->searchable()
            ->preload()
            ->reactive()
            ->visible(fn () => (bool) $user && $user->isSuperAdmin() && blank($selectedMarketId))
            ->dehydrated(true);

        // Если рынок выбран переключателем — фиксируем его скрыто
        $marketHiddenForSuperAdmin = Forms\Components\Hidden::make('market_id')
            ->default(fn () => filled($selectedMarketId) ? (int) $selectedMarketId : null)
            ->visible(fn () => (bool) $user && $user->isSuperAdmin() && filled($selectedMarketId))
            ->dehydrated(true);

        // Для рыночных ролей рынок всегда один — скрыто
        $marketHiddenForMarketUser = Forms\Components\Hidden::make('market_id')
            ->default(fn () => $user?->market_id)
            ->visible(fn () => ! ((bool) $user && $user->isSuperAdmin()))
            ->dehydrated(true);

        return $schema->components([
            $marketSelect,
            $marketHiddenForSuperAdmin,
            $marketHiddenForMarketUser,

            Forms\Components\Select::make('location_id')
                ->label('Локация')
                ->options(function ($get) use ($user, $selectedMarketId) {
                    $marketId = $get('market_id');

                    if (blank($marketId) && (bool) $user && $user->isSuperAdmin() && filled($selectedMarketId)) {
                        $marketId = (int) $selectedMarketId;
                    }

                    if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                        $marketId = $user->market_id;
                    }

                    if (blank($marketId)) {
                        return [];
                    }

                    return MarketLocation::query()
                        ->where('market_id', $marketId)
                        ->orderBy('name')
                        ->pluck('name', 'id');
                })
                ->searchable()
                ->preload()
                ->disabled(function ($get) use ($user, $selectedMarketId) {
                    if (! ((bool) $user && $user->isSuperAdmin())) {
                        return false;
                    }

                    $marketId = $get('market_id') ?? $selectedMarketId;

                    return blank($marketId);
                })
                ->nullable(),

            Forms\Components\Select::make('tenant_id')
                ->label('Арендатор')
                ->options(function ($get, ?MarketSpace $record) use ($user, $selectedMarketId) {
                    $marketId = $get('market_id') ?? $record?->market_id;

                    if (blank($marketId) && (bool) $user && $user->isSuperAdmin() && filled($selectedMarketId)) {
                        $marketId = (int) $selectedMarketId;
                    }

                    if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                        $marketId = $user->market_id;
                    }

                    if (blank($marketId)) {
                        return [];
                    }

                    return Tenant::query()
                        ->where('market_id', $marketId)
                        ->orderBy('name')
                        ->pluck('name', 'id');
                })
                ->searchable()
                ->preload()
                ->disabled(function ($get) use ($user, $selectedMarketId) {
                    if (! ((bool) $user && $user->isSuperAdmin())) {
                        return false;
                    }

                    $marketId = $get('market_id') ?? $selectedMarketId;

                    return blank($marketId);
                })
                ->nullable(),

            Forms\Components\TextInput::make('number')
                ->label('Номер места')
                ->maxLength(255)
                ->helperText('Например: A-101. Внутренний код места формируется автоматически.'),

            // code убран из формы — будет автогенерация на уровне модели

            Forms\Components\TextInput::make('area_sqm')
                ->label('Площадь, м²')
                ->numeric()
                ->inputMode('decimal'),

            Forms\Components\Select::make('type')
                ->label('Тип')
                ->options(function ($get, ?MarketSpace $record) use ($user, $selectedMarketId) {
                    $marketId = $get('market_id') ?? $record?->market_id;

                    if (blank($marketId) && (bool) $user && $user->isSuperAdmin() && filled($selectedMarketId)) {
                        $marketId = (int) $selectedMarketId;
                    }

                    if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                        $marketId = $user->market_id;
                    }

                    if (blank($marketId)) {
                        return [];
                    }

                    return MarketSpaceType::query()
                        ->where('market_id', $marketId)
                        ->where('is_active', true)
                        ->orderBy('name_ru')
                        ->pluck('name_ru', 'code');
                })
                ->searchable()
                ->preload()
                ->reactive()
                ->required()
                ->helperText('Типы и тарифы берутся из справочника “Типы мест”.'),

            Forms\Components\Select::make('status')
                ->label('Статус')
                ->options([
                    'free' => 'Свободно',
                    'occupied' => 'Занято',
                    'reserved' => 'Зарезервировано',
                    'maintenance' => 'На обслуживании',
                ])
                ->default('free'),

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
        $user = Filament::auth()->user();

        $table = $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('location.name')
                    ->label('Локация')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('tenant.name')
                    ->label('Арендатор')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('number')
                    ->label('Номер')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('spaceType.name_ru')
                    ->label('Тип')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'free' => 'Свободно',
                        'occupied' => 'Занято',
                        'reserved' => 'Зарезервировано',
                        'maintenance' => 'На обслуживании',
                        default => $state,
                    })
                    ->sortable(),

                TextColumn::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->recordUrl(fn (MarketSpace $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];
        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
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
            $selectedMarketId = session('filament.admin.selected_market_id');

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
