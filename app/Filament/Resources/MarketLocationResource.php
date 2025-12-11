<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketLocationResource\Pages;
use App\Models\MarketLocation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MarketLocationResource extends Resource
{
    protected static ?string $model = MarketLocation::class;

    protected static ?string $navigationGroup = 'Рынки';

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive(),
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
                        'building' => 'building',
                        'floor' => 'floor',
                        'row' => 'row',
                        'zone' => 'zone',
                    ]),
                Forms\Components\Select::make('parent_id')
                    ->label('Родитель')
                    ->options(fn (Get $get) => MarketLocation::query()
                        ->where('market_id', $get('market_id'))
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get) => blank($get('market_id')))
                    ->nullable(),
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок')
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
                    ->sortable(),
                TextColumn::make('parent.name')
                    ->label('Родитель')
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
