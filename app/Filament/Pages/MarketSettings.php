<?php
# app/Filament/Pages/MarketSettings.php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;

class MarketSettings extends Page
{
    protected static ?string $navigationLabel = 'Настройки рынка';

    /**
     * На prod у market-admin этот пункт лежит в “Панель управления”.
     * Важно: в Filament 4 тип должен совпадать с базовым классом.
     */
    protected static \UnitEnum|string|null $navigationGroup = 'Панель управления';

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'market-settings';
    protected static ?string $title = 'Настройки рынка';

    protected string $view = 'filament.pages.market-settings';

    public ?Market $market = null;

    public bool $isSuperAdmin = false;
    public bool $canEditMarket = false;

    public ?string $marketsUrl = null;
    public ?string $locationTypesUrl = null;
    public ?string $spaceTypesUrl = null;
    public ?string $staffUrl = null;
    public ?string $tenantUrl = null;
    public ?string $permissionsUrl = null;
    public ?string $rolesUrl = null;
    public ?string $integrationExchangesUrl = null;

    /**
     * URL страницы просмотра карты.
     * Ожидаем маршрут filament.admin.market-map (см. routes/web.php).
     */
    public ?string $marketMapViewerUrl = null;

    /**
     * @var array{
     *   name:?string,
     *   address:?string,
     *   timezone:?string,
     *   map_pdf_path?:string|null
     * }
     */
    public array $data = [
        'name' => null,
        'address' => null,
        'timezone' => null,
        'map_pdf_path' => null,
    ];

    public static function shouldRegisterNavigation(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $hasMarket = (int) ($user->market_id ?? 0) > 0;

        // На локали права могут быть не посеяны/отличаться, поэтому страхуемся ролями.
        $hasRoleAccess = method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['market-admin', 'market-maintenance']);

        $hasPermissionAccess =
            $user->can('markets.view') ||
            $user->can('markets.update') ||
            $user->can('markets.viewAny');

        return $hasMarket && ($hasRoleAccess || $hasPermissionAccess);
    }

    public static function canAccess(): bool
    {
        return static::shouldRegisterNavigation();
    }

    public function mount(): void
    {
        $user = Filament::auth()->user();

        $this->isSuperAdmin = (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();

        $this->market = $this->resolveMarketForUser();

        abort_unless($this->market, 404);

        $this->canEditMarket = $this->resolveCanEditMarket();

        $this->fillQuickLinks();

        $settings = (array) ($this->market->settings ?? []);

        $this->form->fill([
            'name' => $this->market->name,
            'address' => $this->market->address,
            'timezone' => $this->market->timezone ?: config('app.timezone', 'Europe/Moscow'),
            'map_pdf_path' => isset($settings['map_pdf_path']) && is_string($settings['map_pdf_path']) ? $settings['map_pdf_path'] : null,
        ]);
    }

    /**
     * Filament 4: Page-форма строится через Schema.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->market)
            ->components([
                Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Название рынка')
                            ->required()
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 8,
                            ]),

                        Forms\Components\TextInput::make('address')
                            ->label('Адрес')
                            ->required()
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 8,
                            ]),

                        Forms\Components\Select::make('timezone')
                            ->label('Часовой пояс')
                            ->options(fn (): array => $this->timezoneOptionsRu())
                            ->searchable()
                            ->native(false)
                            ->required()
                            ->helperText('Используется для дедлайнов, уведомлений и отображения дат.')
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpan([
                                'default' => 12,
                                'lg' => 4,
                            ]),
                    ])
                    ->columns(12),

                Section::make('Карта рынка')
                    ->description('PDF-карта для просмотра с масштабированием и перемещением.')
                    ->schema([
                        Forms\Components\FileUpload::make('map_pdf_path')
                            ->label('Карта (PDF)')
                            ->helperText('Загрузите векторный PDF (рекомендуется). Файл хранится приватно.')
                            ->acceptedFileTypes(['application/pdf'])
                            ->disk('local')
                            ->directory(fn (): string => $this->market ? 'market-maps/market_'.$this->market->id : 'market-maps')
                            ->visibility('private')
                            ->preserveFilenames()
                            // 20 МБ запасом (у нас сейчас ~2 МБ)
                            ->maxSize(20480)
                            ->disabled(fn (): bool => ! $this->canEditMarket)
                            ->columnSpanFull(),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('openMarketMap')
                                ->label('Открыть карту')
                                ->icon('heroicon-o-map')
                                ->url(fn (): ?string => $this->marketMapViewerUrl)
                                ->openUrlInNewTab()
                                // Важно: viewer берёт PDF из БД, поэтому без сохранения “свежая” загрузка может не отобразиться.
                                ->disabled(fn (): bool => blank($this->marketMapViewerUrl) || blank($this->data['map_pdf_path'] ?? null))
                                ->tooltip(function (): ?string {
                                    if (blank($this->marketMapViewerUrl)) {
                                        return 'Маршрут просмотра карты не найден (проверь routes/web.php).';
                                    }

                                    if (blank($this->data['map_pdf_path'] ?? null)) {
                                        return 'Сначала загрузите PDF-карту и сохраните настройки.';
                                    }

                                    return 'Откроется просмотр карты в новой вкладке.';
                                }),
                        ])->columnSpanFull(),
                    ])
                    ->columns(12),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->market, 404);
        abort_unless($this->canEditMarket, 403);

        $state = $this->form->getState();

        // Нормализуем путь (Filament отдаёт строку для single upload).
        $newMapPath = $state['map_pdf_path'] ?? null;
        if (is_array($newMapPath)) {
            $newMapPath = $newMapPath[0] ?? null;
        }
        $newMapPath = (is_string($newMapPath) && $newMapPath !== '') ? $newMapPath : null;

        $settings = (array) ($this->market->settings ?? []);
        $oldMapPath = isset($settings['map_pdf_path']) && is_string($settings['map_pdf_path']) ? $settings['map_pdf_path'] : null;

        // Если файл заменили/удалили — удаляем старый, чтобы не копить мусор.
        if ($oldMapPath && $oldMapPath !== $newMapPath) {
            try {
                Storage::disk('local')->delete($oldMapPath);
            } catch (\Throwable) {
                // ignore
            }
        }

        $settings['map_pdf_path'] = $newMapPath;

        $this->market->fill([
            'name' => (string) ($state['name'] ?? ''),
            'address' => (string) ($state['address'] ?? ''),
            // Храним IANA timezone (Asia/Omsk и т.д.) — это стандарт.
            'timezone' => (string) ($state['timezone'] ?? config('app.timezone', 'Europe/Moscow')),
            'settings' => $settings,
        ]);

        $this->market->save();

        Notification::make()
            ->title('Сохранено')
            ->body('Настройки рынка обновлены.')
            ->success()
            ->send();
    }

    protected function resolveCanEditMarket(): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->market) {
            return false;
        }

        if ($this->isSuperAdmin) {
            return true;
        }

        $sameMarket = (int) ($user->market_id ?? 0) > 0
            && (int) $user->market_id === (int) $this->market->id;

        if (! $sameMarket) {
            return false;
        }

        // Редактировать — market-admin (и/или permission markets.update).
        $canEditByRole = method_exists($user, 'hasRole') && $user->hasRole('market-admin');

        return $canEditByRole || $user->can('markets.update');
    }

    protected function resolveMarketForUser(): ?Market
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        if ($this->isSuperAdmin) {
            $selectedMarketId = $this->selectedMarketIdFromSession();

            if ($selectedMarketId) {
                return Market::query()->whereKey($selectedMarketId)->first();
            }

            return Market::query()->orderBy('id')->first();
        }

        $marketId = (int) ($user->market_id ?? 0);

        return $marketId > 0
            ? Market::query()->whereKey($marketId)->first()
            : null;
    }

    protected function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value = session("filament.{$panelId}.selected_market_id");

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    protected function fillQuickLinks(): void
    {
        $this->marketsUrl = $this->isSuperAdmin
            ? $this->resourceUrl([\App\Filament\Resources\MarketResource::class], 'index')
            : null;

        $this->locationTypesUrl = $this->resourceUrl([
            \App\Filament\Resources\LocationTypeResource::class,
            \App\Filament\Resources\MarketLocationTypeResource::class,
        ], 'index');

        $this->spaceTypesUrl = $this->resourceUrl([
            \App\Filament\Resources\SpaceTypeResource::class,
            \App\Filament\Resources\MarketSpaceTypeResource::class,
        ], 'index');

        $this->staffUrl = $this->resourceUrl([
            \App\Filament\Resources\StaffResource::class,
            \App\Filament\Resources\UserResource::class,
        ], 'index');

        $this->tenantUrl = $this->resourceUrl([
            \App\Filament\Resources\TenantResource::class,
        ], 'index');

        $this->permissionsUrl = $this->resourceUrl([
            \App\Filament\Resources\PermissionResource::class,
        ], 'index');

        $this->rolesUrl = $this->resourceUrl([
            \App\Filament\Resources\RoleResource::class,
        ], 'index');

        $this->integrationExchangesUrl = $this->resourceUrl([
            \App\Filament\Resources\IntegrationExchangeResource::class,
            \App\Filament\Resources\IntegrationExchangesResource::class,
        ], 'index');

        try {
            $this->marketMapViewerUrl = route('filament.admin.market-map');
        } catch (\Throwable) {
            $this->marketMapViewerUrl = null;
        }
    }

    /**
     * @param  array<int, class-string>  $candidates
     */
    protected function resourceUrl(array $candidates, string $page = 'index'): ?string
    {
        foreach ($candidates as $class) {
            if (! class_exists($class)) {
                continue;
            }

            if (! method_exists($class, 'getUrl')) {
                continue;
            }

            try {
                /** @var string $url */
                $url = $class::getUrl($page);

                return $url;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    protected function timezoneOptionsRu(): array
    {
        $timezones = timezone_identifiers_list();
        $out = [];

        foreach ($timezones as $tz) {
            $out[$tz] = $this->formatTimezoneLabelRu($tz);
        }

        $preferred = [
            'Europe/Moscow',
            'Asia/Omsk',
            'Asia/Barnaul',
            'Asia/Novosibirsk',
            'Asia/Yekaterinburg',
            'Asia/Krasnoyarsk',
            'Asia/Irkutsk',
            'Asia/Yakutsk',
            'Asia/Vladivostok',
        ];

        uksort($out, function (string $a, string $b) use ($preferred, $out): int {
            $ia = array_search($a, $preferred, true);
            $ib = array_search($b, $preferred, true);

            if ($ia !== false && $ib !== false) {
                return $ia <=> $ib;
            }

            if ($ia !== false) {
                return -1;
            }

            if ($ib !== false) {
                return 1;
            }

            return strcmp($out[$a], $out[$b]);
        });

        return $out;
    }

    protected function formatTimezoneLabelRu(string $timezone): string
    {
        $name = $this->timezoneCityNameRu($timezone);
        $offset = $this->formatUtcOffset($timezone);

        return "{$name} ({$offset})";
    }

    protected function timezoneCityNameRu(string $timezone): string
    {
        $map = [
            'Europe/Moscow' => 'Москва',
            'Asia/Omsk' => 'Омск',
            'Asia/Barnaul' => 'Барнаул',
            'Asia/Novosibirsk' => 'Новосибирск',
            'Asia/Yekaterinburg' => 'Екатеринбург',
            'Asia/Krasnoyarsk' => 'Красноярск',
            'Asia/Irkutsk' => 'Иркутск',
            'Asia/Yakutsk' => 'Якутск',
            'Asia/Vladivostok' => 'Владивосток',
        ];

        if (isset($map[$timezone])) {
            return $map[$timezone];
        }

        if (class_exists(\IntlTimeZone::class)) {
            try {
                $tz = \IntlTimeZone::createTimeZone($timezone);

                if ($tz) {
                    $label = $tz->getDisplayName(false, \IntlTimeZone::DISPLAY_GENERIC_LOCATION, 'ru_RU');

                    if (is_string($label) && $label !== '' && $label !== $timezone) {
                        return $label;
                    }
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        $parts = explode('/', $timezone);
        $city = end($parts) ?: $timezone;

        return str_replace('_', ' ', $city);
    }

    protected function formatUtcOffset(string $timezone): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTimeImmutable('now', $tz);
            $offsetSeconds = $tz->getOffset($now);
        } catch (\Throwable) {
            return 'UTC';
        }

        $sign = $offsetSeconds >= 0 ? '+' : '-';
        $offsetSeconds = abs($offsetSeconds);

        $hours = intdiv($offsetSeconds, 3600);
        $minutes = intdiv($offsetSeconds % 3600, 60);

        if ($minutes === 0) {
            return "UTC{$sign}{$hours}";
        }

        $mm = str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);

        return "UTC{$sign}{$hours}:{$mm}";
    }
}
