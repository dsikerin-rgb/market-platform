<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantResource\Pages;
use App\Filament\Resources\TenantResource\RelationManagers\ContractsRelationManager;
use App\Filament\Resources\TenantResource\RelationManagers\RequestsRelationManager;
use App\Models\Tenant;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $modelLabel = 'Арендатор';
    protected static ?string $pluralModelLabel = 'Арендаторы';

    protected static ?string $navigationLabel = 'Арендаторы';

    /**
     * Группа динамическая:
     * - super-admin видит "Рынки"
     * - market-admin и остальные сотрудники не видят "Рынки", но могут открыть через "Настройки рынка"
     */
    public static function getNavigationGroup(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        return $user->isSuperAdmin() ? 'Рынки' : 'Рынок'; // Динамическое название группы
    }

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

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

        return $schema->components([

            // Рынок только для super-admin, для других заполняется автоматически из сессии
            Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->relationship('market', 'name')
                ->required()
                ->searchable()
                ->preload()
                ->default(function () use ($user) {
                    if (! $user) {
                        return null;
                    }

                    if ($user->isSuperAdmin()) {
                        // Если фильтр "Все рынки", то не подставляем null — пусть выберет рынок явно
                        return static::selectedMarketIdFromSession() ?: null;
                    }

                    return $user->market_id;
                })
                ->disabled(fn () => (bool) $user && ! $user->isSuperAdmin())
                ->dehydrated(true),

            Forms\Components\TextInput::make('name')
                ->label('Название арендатора')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('short_name')
                ->label('Краткое название / вывеска')
                ->maxLength(255),

            Forms\Components\Select::make('type')
                ->label('Тип арендатора')
                ->options([
                    'llc' => 'ООО',
                    'sole_trader' => 'ИП',
                    'self_employed' => 'Самозанятый',
                    'individual' => 'Физическое лицо',
                ])
                ->nullable(),

            Forms\Components\TextInput::make('inn')
                ->label('ИНН')
                ->maxLength(20),

            Forms\Components\TextInput::make('ogrn')
                ->label('ОГРН / ОГРНИП')
                ->maxLength(20),

            Forms\Components\TextInput::make('phone')
                ->label('Телефон'),

            Forms\Components\TextInput::make('email')
                ->label('Email'),

            Forms\Components\TextInput::make('contact_person')
                ->label('Контактное лицо'),

            Forms\Components\Select::make('status')
                ->label('Статус договора')
                ->options([
                    'active' => 'В аренде',
                    'paused' => 'Приостановлено',
                    'finished' => 'Завершён договор',
                ])
                ->nullable(),

            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true),

            Forms\Components\Textarea::make('notes')
                ->label('Примечания')
                ->rows(3)
                ->columnSpanFull(),
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
                    ->label('Название арендатора')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('short_name')
                    ->label('Краткое название')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('inn')
                    ->label('ИНН')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'active' => 'В аренде',
                        'paused' => 'Приостановлено',
                        'finished' => 'Завершён договор',
                        default => $state,
                    })
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (Tenant $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getRelations(): array
    {
        return [
            ContractsRelationManager::class,
            RequestsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
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
