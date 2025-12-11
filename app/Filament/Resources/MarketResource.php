<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketResource\Pages;
use App\Models\Market;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class MarketResource extends Resource
{
    protected static ?string $model = Market::class;

    protected static ?string $navigationGroup = 'Рынки';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Название')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->label('Slug')
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
            'index' => Pages\ListMarkets::route('/'),
            'create' => Pages\CreateMarket::route('/create'),
            'edit' => Pages\EditMarket::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return $user?->hasAnyRole(['super-admin', 'market-admin']) ?? false;
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return $user?->hasAnyRole(['super-admin', 'market-admin']) ?? false;
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        return $user?->hasAnyRole(['super-admin', 'market-admin']) ?? false;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        return $user?->hasAnyRole(['super-admin', 'market-admin']) ?? false;
    }
}
