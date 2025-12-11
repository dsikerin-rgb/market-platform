<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceResource\Pages;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Tables;
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

    protected static ?string $navigationGroup = 'Рынки';

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';

    public static function form(Form $form): Form
    {
        $user = Filament::auth()->user();

        return $form
            ->schema([
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
                Forms\Components\Select::make('location_id')
                    ->label('Локация')
                    ->options(fn (Get $get) => MarketLocation::query()
                        ->where('market_id', $get('market_id'))
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->disabled(fn (Get $get) => blank($get('market_id')))
                    ->nullable(),
                Forms\Components\Select::make('tenant_id')
                    ->label('Арендатор')
                    ->options(function ($get, ?MarketSpace $record) {
                        $marketId = $get('market_id') ?? $record?->market_id;

                        if (! $marketId) {
                            return [];
                        }

                        return Tenant::query()
                            ->where('market_id', $marketId)
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->preload()
                    ->disabled(fn ($get) => blank($get('market_id')))
                    ->nullable(),
                Forms\Components\TextInput::make('number')
                    ->label('Номер места')
                    ->maxLength(255),
                Forms\Components\TextInput::make('code')
                    ->label('Код места')
                    ->maxLength(255),
                Forms\Components\TextInput::make('area_sqm')
                    ->label('Площадь, м²')
                    ->numeric()
                    ->inputMode('decimal'),
                Forms\Components\Select::make('type')
                    ->label('Тип')
                    ->options([
                        'retail' => 'Торговое место',
                        'storage' => 'Склад',
                        'office' => 'Офис',
                    ]),
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
        return $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable(),
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
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'retail' => 'Торговое место',
                        'storage' => 'Склад',
                        'office' => 'Офис',
                        default => $state,
                    })
                    ->sortable(),
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
            'index' => Pages\ListMarketSpaces::route('/'),
            'create' => Pages\CreateMarketSpace::route('/create'),
            'edit' => Pages\EditMarketSpace::route('/{record}/edit'),
        ];
    }

    protected static function getEloquentQuery(): Builder
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
