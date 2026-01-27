<?php

# app/Filament/Resources/MarketResource.php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\MarketResource\Pages;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
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

        // Системные поля (slug/code/is_active) — только super-admin.
        $isSuperAdmin = (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();

        $name = Forms\Components\TextInput::make('name')
            ->label('Название рынка')
            ->placeholder('Например: Экоярмарка ВДНХ')
            ->required()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(function (?string $state, callable $set, callable $get) use ($isSuperAdmin): void {
                // slug генерим/обновляем только для super-admin
                if (! $isSuperAdmin) {
                    return;
                }

                if (filled($state) && blank($get('slug'))) {
                    $set('slug', Str::slug($state));
                }
            });

        if (method_exists($name, 'autofocus')) {
            $name->autofocus();
        }

        if (method_exists($name, 'prefixIcon')) {
            $name->prefixIcon('heroicon-o-building-storefront');
        }

        $address = Forms\Components\TextInput::make('address')
            ->label('Адрес')
            ->placeholder('Город, улица, дом')
            ->required()
            ->maxLength(255);

        if (method_exists($address, 'prefixIcon')) {
            $address->prefixIcon('heroicon-o-map-pin');
        }

        $timezone = Forms\Components\Select::make('timezone')
            ->label('Часовой пояс')
            ->options(fn () => static::timezoneOptionsRu())
            ->default(config('app.timezone', 'Europe/Moscow'))
            ->searchable()
            ->required()
            ->helperText('Используется для отображения времени в задачах, обращениях и уведомлениях.');

        if (method_exists($timezone, 'native')) {
            $timezone->native(false);
        }

        // Super-admin: системные атрибуты
        $slug = Forms\Components\TextInput::make('slug')
            ->label('Слаг')
            ->maxLength(255)
            ->helperText('Если оставить пустым — будет сформирован из названия.')
            ->visible(fn (): bool => $isSuperAdmin);

        if (method_exists($slug, 'prefixIcon')) {
            $slug->prefixIcon('heroicon-o-link');
        }

        $code = Forms\Components\TextInput::make('code')
            ->label('Код')
            ->placeholder('Внутренний код (если нужен)')
            ->maxLength(255)
            ->visible(fn (): bool => $isSuperAdmin);

        if (method_exists($code, 'prefixIcon')) {
            $code->prefixIcon('heroicon-o-identification');
        }

        $isActive = Forms\Components\Toggle::make('is_active')
            ->label('Активен')
            ->default(true)
            ->visible(fn (): bool => $isSuperAdmin);

        // Лэйаут:
        // - На lg: слева основная форма (8/12), справа "Системные" (4/12)
        // - На мобилке: всё в столбик (12/12)
        // Это решает “поля на всю ширину экрана” и делает страницу визуально легче.
        return $schema->components([
            Grid::make()
                ->columns(12)
                ->schema([
                    Section::make('Параметры рынка')
                        ->description('Основные данные, которые видят пользователи и которые используются в интерфейсе.')
                        ->schema([
                            Grid::make()
                                ->columns(12)
                                ->schema([
                                    $name->columnSpan(['default' => 12, 'lg' => 12]),
                                    $address->columnSpan(['default' => 12, 'lg' => 12]),
                                    $timezone->columnSpan(['default' => 12, 'lg' => 8]),
                                ]),
                        ])
                        ->columnSpan(['default' => 12, 'lg' => 8]),

                    Section::make('Системные')
                        ->description('Доступно только super-admin. Эти поля влияют на системное поведение.')
                        ->schema([
                            $slug,
                            $code,
                            $isActive,
                        ])
                        ->visible(fn (): bool => $isSuperAdmin)
                        ->columnSpan(['default' => 12, 'lg' => 4]),
                ])
                ->columnSpanFull(),
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
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('address')
                    ->label('Адрес')
                    ->searchable()
                    ->limit(60),

                TextColumn::make('timezone')
                    ->label('Часовой пояс')
                    ->formatStateUsing(fn (?string $state): string => static::formatTimezoneLabel($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),

                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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

        if ($user->can('markets.viewAny')) {
            return $query;
        }

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

        if ($user->can('markets.viewAny')) {
            return true;
        }

        return (int) ($user->market_id ?? 0) > 0
            && (int) $record->id === (int) $user->market_id;
    }

    public static function canDelete($record): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->can('markets.delete');
    }

    /**
     * Дружественные подписи таймзон вида "Омск (UTC+6)" при хранении IANA ID (Asia/Omsk).
     * Ключи массива — IANA, значения — label.
     *
     * @return array<string, array<string, string>|string>
     */
    protected static function timezoneOptionsRu(): array
    {
        static $options = null;

        if (is_array($options)) {
            return $options;
        }

        // Основные (Россия) — с русскими названиями.
        $ru = [
            'Europe/Kaliningrad' => 'Калининград',
            'Europe/Moscow'      => 'Москва',
            'Europe/Samara'      => 'Самара',
            'Asia/Yekaterinburg' => 'Екатеринбург',
            'Asia/Omsk'          => 'Омск',
            'Asia/Novosibirsk'   => 'Новосибирск',
            'Asia/Barnaul'       => 'Барнаул',
            'Asia/Krasnoyarsk'   => 'Красноярск',
            'Asia/Irkutsk'       => 'Иркутск',
            'Asia/Yakutsk'       => 'Якутск',
            'Asia/Vladivostok'   => 'Владивосток',
            'Asia/Magadan'       => 'Магадан',
            'Asia/Kamchatka'     => 'Камчатка',
        ];

        $ruOptions = [];
        foreach ($ru as $tz => $city) {
            $ruOptions[$tz] = sprintf('%s (UTC%s)', $city, static::formatUtcOffset($tz));
        }

        // Остальные — на случай, если рынки будут не только в РФ.
        $otherOptions = [];
        foreach (timezone_identifiers_list() as $tz) {
            if (isset($ruOptions[$tz])) {
                continue;
            }

            $otherOptions[$tz] = sprintf('%s (UTC%s)', $tz, static::formatUtcOffset($tz));
        }

        // Optgroup-структура (Filament Select это поддерживает)
        $options = [
            'Россия' => $ruOptions,
            'Другое' => $otherOptions,
        ];

        return $options;
    }

    protected static function formatTimezoneLabel(?string $timezone): string
    {
        if (blank($timezone)) {
            return '—';
        }

        // Пробуем найти в наших RU-опциях, иначе показываем IANA + offset
        $options = static::timezoneOptionsRu();

        foreach ($options as $group) {
            if (is_array($group) && isset($group[$timezone])) {
                return (string) $group[$timezone];
            }
        }

        return sprintf('%s (UTC%s)', $timezone, static::formatUtcOffset($timezone));
    }

    protected static function formatUtcOffset(string $timezone): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTimeImmutable('now', $tz);
            $offsetSeconds = $tz->getOffset($now);

            $sign = $offsetSeconds >= 0 ? '+' : '-';
            $offsetSeconds = abs($offsetSeconds);

            $hours = intdiv($offsetSeconds, 3600);
            $minutes = intdiv($offsetSeconds % 3600, 60);

            if ($minutes === 0) {
                return sprintf('%s%d', $sign, $hours);
            }

            return sprintf('%s%d:%02d', $sign, $hours, $minutes);
        } catch (\Throwable) {
            return '+0';
        }
    }
}
