<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketLocationResource\Pages;
use App\Models\MarketLocation;
use Filament\Forms;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
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

        return $schema
            ->components([
                Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->default($user?->market_id)
                    ->disabled(fn () => $user && ! $user->isSuperAdmin())
                    ->dehydrated(true),
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('code')
                    ->label('Код')
                    ->maxLength(255),
                Forms\Components\Select::make('type')
                    ->label('Тип')
                    ->options([
                        'building' => 'Здание',
                        'floor' => 'Этаж',
                        'row' => 'Ряд',
                        'zone' => 'Зона',
                    ]),
                Forms\Components\Select::make('parent_id')
                    ->label('Родительская локация')
                    ->options(fn ($get) => MarketLocation::query()
                        ->where('market_id', $get('market_id'))
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->disabled(fn ($get) => blank($get('market_id')))
                    ->nullable(),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок отображения')
                    ->numeric()
                    ->default(0),
                Forms\Components\Toggle::make('is_active')
                    ->label('Активен')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'building' => 'Здание',
                        'floor' => 'Этаж',
                        'row' => 'Ряд',
                        'zone' => 'Зона',
                        default => $state,
                    })
                    ->sortable(),
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
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
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
            return $query;
        }

        if ($user->market_id) {
            return $query->where('market_id', $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin() || (bool) $user->market_id;
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin() || (bool) $user->market_id;
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
