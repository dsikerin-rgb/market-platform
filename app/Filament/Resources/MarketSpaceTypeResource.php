<?php
# app/Filament/Resources/MarketSpaceTypeResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\MarketSpaceTypeResource\Pages;
use App\Models\MarketSpaceType;
use App\Support\AdminCapabilities;
use App\Support\MarketContext;
use Filament\Facades\Filament;
use Filament\Forms;
use App\Filament\Resources\BaseResource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class MarketSpaceTypeResource extends BaseResource
{
    

    protected static ?string $model = MarketSpaceType::class;

    protected static ?string $recordTitleAttribute = 'name_ru';

    protected static ?string $modelLabel = 'Тип места';
    protected static ?string $pluralModelLabel = 'Типы мест';

    /**
     * ВАЖНО: убираем из левого меню.
     * Доступ остаётся по URL и со страницы "Настройки рынка" (хаб) позже.
     */
    protected static bool $shouldRegisterNavigation = false;

    // Метаданные оставляем (на меню не влияют при shouldRegisterNavigation=false)
    protected static ?string $navigationLabel = 'Типы мест';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-squares-2x2';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        return app(MarketContext::class)->selectedMarketIdFromSession();
    }

    public static function makeUniqueCode(int $marketId, string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'type';
        }

        $base = Str::limit($base, 220, '');
        $code = $base;
        $index = 2;

        while (MarketSpaceType::query()
            ->where('market_id', $marketId)
            ->where('code', $code)
            ->exists()) {
            $code = Str::limit($base, 210, '').'-'.$index;
            $index++;
        }

        return $code;
    }

    public static function categoryOptions(): array
    {
        return [
            'commercial' => 'Торговое место',
            'service' => 'Служебный объект',
            'common_area' => 'Место общего пользования',
            'infrastructure' => 'Инфраструктура',
        ];
    }

    public static function normalizeNameForLookup(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name));

        return mb_strtolower($normalized ?? trim($name), 'UTF-8');
    }

    public static function findDuplicateByName(int $marketId, string $name, ?int $ignoreId = null): ?MarketSpaceType
    {
        $needle = static::normalizeNameForLookup($name);

        if ($needle === '') {
            return null;
        }

        $query = MarketSpaceType::query()
            ->where('market_id', $marketId)
            ->select(['id', 'market_id', 'name_ru', 'code', 'is_active']);

        if ($ignoreId !== null) {
            $query->whereKeyNot($ignoreId);
        }

        return $query
            ->get()
            ->first(fn (MarketSpaceType $type): bool => static::normalizeNameForLookup((string) $type->name_ru) === $needle);
    }

    
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'name_ru',
            'market.name',
        ];
    }
    public static function form(Schema $schema): Schema
    {
        $user = Filament::auth()->user();
        $selectedMarketId = static::selectedMarketIdFromSession();

        $components = [];

        // ВАЖНО: в форме всегда должен быть РОВНО ОДИН market_id.
        if ((bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
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
            Forms\Components\TextInput::make('name_ru')
                ->label('Название типа')
                ->required()
                ->maxLength(255)
                ->helperText('Тип отвечает на вопрос "что сдаётся". Локация отвечает на вопрос "где находится". Совпадение названий допустимо, если смысл разный.'),

            Forms\Components\Select::make('category')
                ->label('Категория')
                ->options(static::categoryOptions())
                ->default('commercial')
                ->required(),

            Forms\Components\Select::make('unit')
                ->label('Единица расчёта по умолчанию')
                ->options([
                    'sqm' => 'За м²',
                    'fixed' => 'Фиксированная ставка',
                ])
                ->required(),

            Forms\Components\TextInput::make('price')
                ->label('Базовая ставка по умолчанию')
                ->numeric()
                ->inputMode('decimal'),

            Forms\Components\Toggle::make('is_active')
                ->label('Активен')
                ->default(true),
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

        $table = $table
            ->columns([
                TextColumn::make('market.name')
                    ->label('Рынок')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()),

                TextColumn::make('name_ru')
                    ->label('Название типа')
                    ->sortable()
                    ->searchable(),

                TextColumn::make('category')
                    ->label('Категория')
                    ->formatStateUsing(fn (?string $state) => static::categoryOptions()[(string) $state] ?? $state)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Единица расчёта')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'sqm' => 'За м²',
                        'fixed' => 'Фиксированная ставка',
                        default => $state,
                    }),

                TextColumn::make('price')
                    ->label('Базовая ставка')
                    ->numeric(decimalPlaces: 2),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
            ])
            ->recordUrl(fn (MarketSpaceType $record): ?string => static::canEdit($record)
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketSpaceTypes::route('/'),
            'create' => Pages\CreateMarketSpaceType::route('/create'),
            'edit' => Pages\EditMarketSpaceType::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Filament::auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
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

        return AdminCapabilities::canViewMarketDirectory($user);
    }

    public static function canCreate(): bool
    {
        $user = Filament::auth()->user();

        return AdminCapabilities::canManageMarketDirectory($user);
    }

    public static function canEdit($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return AdminCapabilities::canManageMarketDirectory($user, (int) ($record->market_id ?? 0));
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        return AdminCapabilities::canManageMarketDirectory($user, (int) ($record->market_id ?? 0));
    }
}
