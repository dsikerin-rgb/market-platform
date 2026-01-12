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

    // В меню видно только тем, у кого есть markets.viewAny (обычно super-admin)
    protected static ?string $navigationLabel = 'Рынки';
    protected static \UnitEnum|string|null $navigationGroup = 'Рынки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?int $navigationSort = 10;

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('markets.viewAny');
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();

        // Системные поля (slug/code/is_active) — только super-admin (не через permissions).
        $isSuperAdmin = (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();

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

            // Ниже — только super-admin (чтобы market-admin не менял системные атрибуты)
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
        // Таблица по сути только для тех, у кого markets.viewAny
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

        // Полный список — только markets.viewAny
        if ($user->can('markets.viewAny')) {
            return $query;
        }

        // Если когда-нибудь дадим роль/право "видеть/редактировать свой рынок" через MarketResource:
        if (
            ($user->can('markets.view') || $user->can('markets.update'))
            && (int) ($user->market_id ?? 0) > 0
        ) {
            return $query->whereKey((int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('markets.viewAny');
    }

    public static function canView($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->can('markets.viewAny')) {
            return true;
        }

        return $user->can('markets.view')
            && (int) ($user->market_id ?? 0) > 0
            && (int) $record->id === (int) $user->market_id;
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('markets.create');
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! $user->can('markets.update')) {
            return false;
        }

        // Если есть viewAny — можно редактировать любой рынок
        if ($user->can('markets.viewAny')) {
            return true;
        }

        // Иначе — только свой рынок
        return (int) ($user->market_id ?? 0) > 0
            && (int) $record->id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('markets.delete');
    }
}
