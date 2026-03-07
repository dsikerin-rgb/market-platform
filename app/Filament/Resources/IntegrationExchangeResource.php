<?php
# app/Filament/Resources/IntegrationExchangeResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationExchangeResource\Pages;
use App\Models\IntegrationExchange;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class IntegrationExchangeResource extends BaseResource
{
    

    protected static ?string $model = IntegrationExchange::class;

    protected static ?string $recordTitleAttribute = 'entity_type';

    protected static ?string $navigationLabel = 'Журнал интеграций';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?int $navigationSort = 90;

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

    private static function payloadInt(mixed $payload, string $key, int $default = 0): int
    {
        $value = Arr::get(is_array($payload) ? $payload : [], $key);

        if ($value === null) {
            return $default;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value) && trim($value) !== '' && is_numeric(trim($value))) {
            return (int) trim($value);
        }

        return $default;
    }

    private static function payloadString(mixed $payload, string $key, ?string $default = null): ?string
    {
        $value = Arr::get(is_array($payload) ? $payload : [], $key);

        if ($value === null) {
            return $default;
        }

        $value = is_scalar($value) ? (string) $value : null;

        return filled($value) ? $value : $default;
    }

    private static function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    private static function recordTimezone(?IntegrationExchange $record): string
    {
        return static::resolveTimezone($record?->market?->timezone);
    }

    
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'entity_type',
            'direction',
            'status',
            'error',
            'market.name',
        ];
    }
    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $components = [];

        // Рынок
        if ((bool) $user && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $components[] = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->default((int) $selectedMarketId)
                    ->disabled()
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
            $components[] = Forms\Components\Select::make('market_id')
                ->label('Рынок')
                ->relationship('market', 'name')
                ->default(fn () => $user?->market_id)
                ->disabled()
                ->dehydrated(true);
        }

        // Основные поля
        $leftColumn = [
            ...$components,

            Forms\Components\TextInput::make('entity_type')
                ->label('Тип сущности')
                ->maxLength(255),

            Forms\Components\TextInput::make('direction')
                ->label('Направление')
                ->maxLength(255),

            Forms\Components\TextInput::make('status')
                ->label('Статус')
                ->maxLength(255),

            Forms\Components\DateTimePicker::make('started_at')
                ->label('Начато')
                ->seconds(false)
                ->timezone(fn (?IntegrationExchange $record): string => static::recordTimezone($record)),

            Forms\Components\DateTimePicker::make('finished_at')
                ->label('Завершено')
                ->seconds(false)
                ->timezone(fn (?IntegrationExchange $record): string => static::recordTimezone($record)),
        ];

        $rightColumn = [
            Forms\Components\Textarea::make('payload')
                ->label('Данные (JSON)')
                ->rows(22)
                ->extraAttributes([
                    'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;',
                ])
                ->formatStateUsing(fn ($state) => blank($state) ? '' : json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                ->dehydrateStateUsing(fn ($state) => filled($state) ? (json_decode($state, true) ?? []) : []),
        ];

        // Нижняя “полоса” на всю ширину — ошибка + file_path
        $bottomRow = [
            Forms\Components\Textarea::make('error')
                ->label('Ошибка')
                ->rows(6)
                ->extraAttributes([
                    'style' => 'font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;',
                ]),

            Forms\Components\TextInput::make('file_path')
                ->label('Файл')
                ->maxLength(255),

            Forms\Components\Hidden::make('created_by')
                ->default(fn () => $user?->id)
                ->dehydrated(true),
        ];

        if (class_exists(Forms\Components\Grid::class)) {
            return $schema->components([
                // верх: 2 колонки
                Forms\Components\Grid::make(2)->components([
                    ...$leftColumn,
                    ...$rightColumn,
                ]),
                // низ: 1 колонка (на всю ширину)
                Forms\Components\Grid::make(1)->components([
                    ...$bottomRow,
                ]),
            ]);
        }

        return $schema->components([
            ...$leftColumn,
            ...$rightColumn,
            ...$bottomRow,
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

                TextColumn::make('entity_type')
                    ->label('Сущность')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->formatStateUsing(static function (?string $state): string {
                        return match ($state) {
                            'contract_debts' => 'Долги (1С)',
                            default => (string) ($state ?: '—'),
                        };
                    }),

                TextColumn::make('direction')
                    ->label('Напр.')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => match ($state) {
                        IntegrationExchange::DIRECTION_IN => 'IN',
                        IntegrationExchange::DIRECTION_OUT => 'OUT',
                        default => (string) ($state ?: '—'),
                    }),

                TextColumn::make('status')
                    ->label('Статус')
                    ->sortable()
                    ->badge()
                    ->formatStateUsing(static fn (?string $state): string => match ($state) {
                        IntegrationExchange::STATUS_OK => 'OK',
                        IntegrationExchange::STATUS_ERROR => 'ERROR',
                        IntegrationExchange::STATUS_IN_PROGRESS => 'В работе',
                        default => (string) ($state ?: '—'),
                    }),

                TextColumn::make('payload_counts')
                    ->label('Счётчики')
                    ->state(static function (IntegrationExchange $record): string {
                        $p = $record->payload;

                        $received = static::payloadInt($p, 'received', 0);
                        $inserted = static::payloadInt($p, 'inserted', 0);
                        $skipped = static::payloadInt($p, 'skipped', 0);

                        $parts = [];
                        if ($received > 0) {
                            $parts[] = "R:{$received}";
                        }
                        if ($inserted > 0) {
                            $parts[] = "I:{$inserted}";
                        }
                        if ($skipped > 0) {
                            $parts[] = "S:{$skipped}";
                        }

                        return ! empty($parts) ? implode(' · ', $parts) : '—';
                    }),

                TextColumn::make('payload_calculated_at')
                    ->label('Снимок')
                    ->state(static function (IntegrationExchange $record): string {
                        $p = $record->payload;
                        $value = static::payloadString($p, 'calculated_at');

                        return $value ?: '—';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('started_at')
                    ->label('Начато')
                    ->timezone(static fn (IntegrationExchange $record): string => static::recordTimezone($record))
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('duration_ms')
                    ->label('Длит.')
                    ->state(static function (IntegrationExchange $record): string {
                        $p = $record->payload;
                        $ms = static::payloadInt($p, 'duration_ms', $record->duration_ms ?? 0);

                        if ($ms <= 0) {
                            return '—';
                        }

                        if ($ms >= 1000) {
                            $sec = $ms / 1000;

                            return rtrim(rtrim(number_format($sec, 1, '.', ''), '0'), '.') . ' c';
                        }

                        return $ms . ' мс';
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy('started_at', $direction);
                    }),

                TextColumn::make('error')
                    ->label('Ошибка')
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('payload')
                    ->label('Payload')
                    ->boolean(fn ($state) => ! empty($state))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->recordUrl(fn (IntegrationExchange $record): string => static::getUrl('edit', ['record' => $record]));
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
        $query = parent::getEloquentQuery()
            ->with(['market:id,name,timezone']);
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

        return (bool) $user
            && ($user->isSuperAdmin() || ($user->hasRole('market-operator') && (bool) $user->market_id));
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($record instanceof IntegrationExchange && $record->direction === IntegrationExchange::DIRECTION_IN) {
            return $user->isSuperAdmin();
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->hasRole('market-operator')
            && $user->market_id
            && $record->market_id === $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return $user->isSuperAdmin();
    }
}
