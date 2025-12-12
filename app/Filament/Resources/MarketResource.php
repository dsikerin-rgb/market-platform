<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketResource\Pages;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketResource extends Resource
{
    protected static ?string $model = Market::class;

    protected static ?string $modelLabel = 'Рынок';
    protected static ?string $pluralModelLabel = 'Рынки';
    protected static ?string $navigationLabel = 'Рынки';

    protected static \UnitEnum|string|null $navigationGroup = 'Рынки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('slug')
                ->label('Слаг')
                ->maxLength(255),

            Forms\Components\TextInput::make('code')
                ->label('Код')
                ->maxLength(255),

            Forms\Components\TextInput::make('address')
                ->label('Адрес')
                ->maxLength(255),

            Forms\Components\Select::make('timezone')
                ->label('Часовой пояс')
                ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->default('Europe/Moscow')
                ->searchable(),

            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('address')
                    ->label('Адрес')
                    ->searchable()
                    ->limit(50),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (Market $record): string => static::getUrl('edit', ['record' => $record]))
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
            'index' => Pages\ListMarkets::route('/'),
            'create' => Pages\CreateMarket::route('/create'),
            'edit' => Pages\EditMarket::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        return $user?->isSuperAdmin() ?? false;
    }
}
