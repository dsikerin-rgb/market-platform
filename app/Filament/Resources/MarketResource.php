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
use Illuminate\Support\Str;

class MarketResource extends Resource
{
    protected static ?string $model = Market::class;

    protected static ?string $modelLabel = 'Рынок';
    protected static ?string $pluralModelLabel = 'Рынки';

    // В меню видно только super-admin
    protected static ?string $navigationLabel = 'Рынки';
    protected static \UnitEnum|string|null $navigationGroup = 'Рынки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $isSuperAdmin = (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = (bool) $user && method_exists($user, 'hasRole') && $user->hasRole('market-admin');

        return $schema->components([
            Forms\Components\TextInput::make('name')
                ->label('Название')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(function (?string $state, callable $set, callable $get) use ($isSuperAdmin): void {
                    // slug генерим/обновляем только для super-admin
                    if ($isSuperAdmin && filled($state) && blank($get('slug'))) {
                        $set('slug', Str::slug($state));
                    }
                }),

            Forms\Components\TextInput::make('address')
                ->label('Адрес')
                ->required()
                ->maxLength(255),

            Forms\Components\Select::make('timezone')
                ->label('Часовой пояс')
                ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->default(config('app.timezone', 'Europe/Moscow'))
                ->searchable()
                ->required(),

            // Ниже — только super-admin (чтобы market-admin не менял лишнее)
            Forms\Components\TextInput::make('slug')
                ->label('Слаг')
                ->maxLength(255)
                ->helperText('Если оставить пустым — будет сформирован из названия.')
                ->visible(fn () => $isSuperAdmin),

            Forms\Components\TextInput::make('code')
                ->label('Код')
                ->maxLength(255)
                ->visible(fn () => $isSuperAdmin),

            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true)
                ->visible(fn () => $isSuperAdmin),
        ]);
    }

    public static function table(Table $table): Table
    {
        // таблица по сути только для super-admin (market-admin не имеет ViewAny)
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

                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (Market $record): string => static::getUrl('edit', ['record' => $record]));
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

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');

        if ($isSuperAdmin) {
            return $query;
        }

        // market-admin: только свой рынок
        if ($isMarketAdmin && (int) $user->market_id > 0) {
            return $query->whereKey((int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        // список рынков — только super-admin
        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        // создавать рынки — только super-admin
        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        if ($isSuperAdmin) {
            return true;
        }

        // market-admin может редактировать только свой рынок
        $isMarketAdmin = method_exists($user, 'hasRole') && $user->hasRole('market-admin');

        return $isMarketAdmin
            && (int) $user->market_id > 0
            && (int) $record->id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        // удаление — только super-admin
        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
    }
}
