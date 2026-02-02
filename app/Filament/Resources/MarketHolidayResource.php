<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketHolidayResource\Pages;
use App\Models\MarketHoliday;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MarketHolidayResource extends Resource
{
    protected static ?string $model = MarketHoliday::class;

    protected static ?string $modelLabel = 'Праздник рынка';
    protected static ?string $pluralModelLabel = 'Праздники рынка';
    protected static ?string $navigationLabel = 'Праздники рынка';
    protected static \UnitEnum|string|null $navigationGroup = 'Настройки';
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-calendar-days';

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasRole('market-admin');
    }

    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $marketField = [];

        if ($user && $user->isSuperAdmin()) {
            if (filled($selectedMarketId)) {
                $marketField[] = Forms\Components\Hidden::make('market_id')
                    ->default(fn () => (int) $selectedMarketId)
                    ->dehydrated(true);
            } else {
                $marketField[] = Forms\Components\Select::make('market_id')
                    ->label('Рынок')
                    ->relationship('market', 'name')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->reactive()
                    ->dehydrated(true);
            }
        } else {
            $marketField[] = Forms\Components\Hidden::make('market_id')
                ->default(fn () => $user?->market_id)
                ->dehydrated(true);
        }

        return $schema->components([
            Section::make()
                ->schema([
                    ...$marketField,

                    Forms\Components\TextInput::make('title')
                        ->label('Название')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\DatePicker::make('starts_at')
                        ->label('Дата начала')
                        ->required(),

                    Forms\Components\DatePicker::make('ends_at')
                        ->label('Дата окончания')
                        ->helperText('Можно оставить пустым для одного дня.'),

                    Forms\Components\Toggle::make('all_day')
                        ->label('Весь день')
                        ->default(true),

                    Forms\Components\TextInput::make('notify_before_days')
                        ->label('Уведомить за (дней)')
                        ->numeric()
                        ->helperText('Если не задано, используется настройка рынка.'),

                    Forms\Components\Textarea::make('description')
                        ->label('Описание')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        $user = Filament::auth()->user();

        $table = $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && $user->isSuperAdmin()),

                TextColumn::make('title')
                    ->label('Название')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('starts_at')
                    ->label('Начало')
                    ->date()
                    ->sortable(),

                TextColumn::make('ends_at')
                    ->label('Окончание')
                    ->date()
                    ->sortable(),

                IconColumn::make('all_day')
                    ->label('Весь день')
                    ->boolean(),

                TextColumn::make('notify_before_days')
                    ->label('Уведомить за')
                    ->suffix(' дн.')
                    ->sortable(),

                TextColumn::make('source')
                    ->label('Источник')
                    ->sortable(),
            ])
            ->recordUrl(fn (MarketHoliday $record): ?string => static::canEdit($record)
                ? static::getUrl('edit', ['record' => $record])
                : null);

        $actions = [];

        if (class_exists(\Filament\Actions\EditAction::class)) {
            $actions[] = \Filament\Actions\EditAction::make()->label('Редактировать');
        } elseif (class_exists(\Filament\Tables\Actions\EditAction::class)) {
            $actions[] = \Filament\Tables\Actions\EditAction::make()->label('Редактировать');
        }

        if (class_exists(\Filament\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Actions\DeleteAction::make()->label('Удалить');
        } elseif (class_exists(\Filament\Tables\Actions\DeleteAction::class)) {
            $actions[] = \Filament\Tables\Actions\DeleteAction::make()->label('Удалить');
        }

        if (! empty($actions)) {
            $table = $table->actions($actions);
        }

        return $table;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketHolidays::route('/'),
            'create' => Pages\CreateMarketHoliday::route('/create'),
            'edit' => Pages\EditMarketHoliday::route('/{record}/edit'),
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
            return $query->where('market_id', (int) $user->market_id);
        }

        return $query->whereRaw('1 = 0');
    }

    public static function canViewAny(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id && $user->hasRole('market-admin');
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! ($record instanceof MarketHoliday)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->market_id
            && (int) $record->market_id === (int) $user->market_id
            && $user->hasRole('market-admin');
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value = session("filament.{$panelId}.selected_market_id");

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }
}
