<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationExchangeResource\Pages;
use App\Models\IntegrationExchange;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class IntegrationExchangeResource extends Resource
{
    protected static ?string $model = IntegrationExchange::class;

    protected static ?string $modelLabel = 'Обмен интеграции';
    protected static ?string $pluralModelLabel = 'Обмены интеграций';

    /**
     * УБИРАЕМ из левого меню.
     * Это служебный журнал — доступ остаётся по URL / по ссылке из страницы "Настройки рынка".
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

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
        $selectedMarketId = static::selectedMarketIdFromSession();

        $components = [];

        if ((bool) $user && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $components[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
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
            $components[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        $formFields = [
            Forms\Components\TextInput::make('direction')
                ->label('Направление')
                ->maxLength(255),

            Forms\Components\TextInput::make('entity_type')
                ->label('Тип сущности')
                ->maxLength(255),

            Forms\Components\TextInput::make('status')
                ->label('Статус')
                ->maxLength(255),

            Forms\Components\TextInput::make('file_path')
                ->label('Файл')
                ->maxLength(255),

            Forms\Components\Textarea::make('payload')
                ->label('Данные (JSON)')
                ->rows(3)
                ->formatStateUsing(fn ($state) => blank($state) ? '' : json_encode($state, JSON_UNESCAPED_UNICODE))
                ->dehydrateStateUsing(fn ($state) => filled($state) ? (json_decode($state, true) ?? []) : []),

            Forms\Components\Textarea::make('error')
                ->label('Ошибка')
                ->rows(3),

            Forms\Components\Hidden::make('created_by')
                ->default(fn () => $user?->id)
                ->dehydrated(true),

            Forms\Components\DateTimePicker::make('started_at')
                ->label('Начато'),

            Forms\Components\DateTimePicker::make('finished_at')
                ->label('Завершено'),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                Forms\Components\Grid::make(2)->components([
                    ...$components,
                    ...$formFields,
                ]),
            ]);
        }

        return $schema->components([
            ...$components,
            ...$formFields,
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

                TextColumn::make('direction')
                    ->label('Направление')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('entity_type')
                    ->label('Тип сущности')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->sortable(),

                TextColumn::make('started_at')
                    ->label('Начато')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('finished_at')
                    ->label('Завершено')
                    ->dateTime()
                    ->sortable(),

                IconColumn::make('payload')
                    ->label('Данные')
                    ->boolean(fn ($state) => ! empty($state)),
            ])
            ->recordUrl(fn (IntegrationExchange $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationExchanges::route('/'),
            'create' => Pages\CreateIntegrationExchange::route('/create'),
            'edit' => Pages\EditIntegrationExchange::route('/{record}/edit'),
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
