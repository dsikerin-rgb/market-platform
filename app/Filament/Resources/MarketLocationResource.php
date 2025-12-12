<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketLocationResource\Pages;
use App\Models\MarketLocation;
use App\Models\MarketLocationType;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketLocationResource extends Resource
{
    protected static ?string $model = MarketLocation::class;

    protected static ?string $modelLabel = 'Локация';
    protected static ?string $pluralModelLabel = 'Локации рынка';
    protected static ?string $navigationLabel = 'Локации рынка';

    protected static \UnitEnum|string|null $navigationGroup = 'Рынки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-map-pin';

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        // ВАЖНО: в форме всегда должен быть РОВНО ОДИН market_id.
        $components = [];

        if ((bool) $user && $user->isSuperAdmin()) {
            $selectedMarketId = session('filament.admin.selected_market_id');

            if (filled($selectedMarketId)) {
                // Рынок выбран через переключатель — фиксируем скрыто
                $components[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) session('filament.admin.selected_market_id'))
                    ->dehydrated(true);
            } else {
                // Иначе super-admin выбирает рынок вручную
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
            // Рыночные роли: рынок всегда один — скрыто
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        return $schema->components([
            ...$components,

            Forms\Components\TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255)
                ->helperText('Например: Здание 1, Этаж 2, Сектор А.'),

            // code убран из формы — генерируется на уровне модели

            Forms\Components\Select::make('type')
                ->label('Тип')
                ->options(function ($get, ?MarketLocation $record) use ($user) {
                    $marketId = $get('market_id') ?? $record?->market_id;

                    if (blank($marketId) && (bool) $user && ! $user->isSuperAdmin()) {
                        $marketId = $user->market_id;
                    }

                    if (blank($marketId)) {
                        return [];
                    }

                    return MarketLocationType::query()
                        ->where('market_id', $marketId)
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->orderBy('name_ru')
                        ->pluck('name_ru', 'code');
                })
                ->searchable()
                ->preload()
                ->reactive()
                ->required()
                ->helperText('Типы берутся из справочника рынка. Добавьте новые в разделе “Типы локаций”.'),

            Forms\Components\Select::make('parent_id')
                ->label('Родительская локация')
                ->options(function ($get, ?MarketLocation $record) {
                    $marketId = $get('market_id');

                    if (blank($marketId)) {
                        return [];
                    }

                    return MarketLocation::query()
                        ->where('market_id', $marketId)
                        ->when($record?->id, fn ($q) => $q->whereKeyNot($record->id))
                        ->orderBy('name')
                        ->pluck('name', 'id');
                })
                ->searchable()
                ->preload()
                ->disabled(fn ($get) => blank($get('market_id')))
                ->nullable()
                ->helperText('Если это “этаж” — выбери “здание” как родителя. Если это “зона/ряд” — выбери “этаж/здание”.'),

            Forms\Components\TextInput::make('sort_order')
                ->label('Порядок отображения')
                ->numeric()
                ->default(0)
                ->helperText('Для ручной сортировки (если нужно). 0 — по умолчанию.'),

            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('name')
                    ->label('Название')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('locationType.name_ru')
                    ->label('Тип')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('parent.name')
                    ->label('Родительская локация')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->recordUrl(fn (MarketLocation $record): string => static::getUrl('edit', ['record' => $record]))
            ->filters([
                //
            ])
            ->bulkActions([
                //
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketLocations::route('/'),
            'create' => Pages\CreateMarketLocation::route('/create'),
            'edit' => Pages\EditMarketLocation::route('/{record}/edit'),
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
