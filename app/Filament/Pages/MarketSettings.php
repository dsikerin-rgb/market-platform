<?php
# app/Filament/Pages/MarketSettings.php

namespace App\Filament\Pages;

use App\Filament\Resources\IntegrationExchangeResource;
use App\Filament\Resources\MarketLocationTypeResource;
use App\Filament\Resources\MarketResource;
use App\Filament\Resources\MarketSpaceTypeResource;
use App\Filament\Resources\PermissionResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class MarketSettings extends Page
{
    protected static ?string $title = 'Настройки рынка';
    protected static ?string $navigationLabel = 'Настройки рынка';
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'market-settings';

    protected string $view = 'filament.pages.market-settings';

    public ?Market $market = null;

    /** Состояние формы */
    public array $data = [
        'name' => null,
        'address' => null,
        'timezone' => null,
    ];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) $user->market_id
        );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $key = "filament_{$panelId}_market_id";
        $value = session($key);

        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    protected function resolveMarket(): ?Market
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if ($isSuperAdmin) {
            $selectedId = static::selectedMarketIdFromSession();

            return $selectedId ? Market::query()->find($selectedId) : null;
        }

        return $user->market_id ? Market::query()->find((int) $user->market_id) : null;
    }

    protected function canEditMarket(): bool
    {
        $user = Filament::auth()->user();

        if (! $user || ! $this->market) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return $user->hasRole('market-admin')
            && (int) $user->market_id === (int) $this->market->id;
    }

    public function mount(): void
    {
        $this->market = $this->resolveMarket();

        if ($this->market) {
            $this->data = [
                'name' => $this->market->name,
                'address' => $this->market->address,
                'timezone' => $this->market->timezone ?: config('app.timezone', 'Europe/Moscow'),
            ];
        } else {
            $this->data = [
                'name' => null,
                'address' => null,
                'timezone' => config('app.timezone', 'Europe/Moscow'),
            ];
        }

        $this->form->fill($this->data);
    }

    /**
     * ВАЖНО: именно Schema, а не Form.
     */
    public function form(Schema $schema): Schema
    {
        $editable = $this->canEditMarket();
        $marketExists = (bool) $this->market;

        return $schema
            ->statePath('data')
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Название рынка')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn () => ! $editable || ! $marketExists),

                Forms\Components\TextInput::make('address')
                    ->label('Адрес')
                    ->required()
                    ->maxLength(255)
                    ->disabled(fn () => ! $editable || ! $marketExists),

                Forms\Components\Select::make('timezone')
                    ->label('Часовой пояс')
                    ->required()
                    ->native(false)
                    ->options(fn () => $this->timezoneOptionsRu())
                    // КРИТИЧНО: ограничиваем ширину и даём отступ на уровне обёртки поля.
                    // Это влияет на реальную компоновку (и на расстояние до кнопки ниже).
                    ->extraFieldWrapperAttributes([
                        'style' => 'max-width: 28rem; margin-bottom: 1rem;',
                    ])
                    ->disabled(fn () => ! $editable || ! $marketExists),
            ]);
    }

    public function save(): void
    {
        if (! $this->market) {
            Notification::make()
                ->title('Рынок не выбран')
                ->body('Для super-admin сначала выбери рынок (фильтр/переключатель рынка), затем открой “Настройки рынка”.')
                ->warning()
                ->send();

            return;
        }

        if (! $this->canEditMarket()) {
            abort(403);
        }

        $this->market->update([
            'name' => $this->data['name'] ?? $this->market->name,
            'address' => $this->data['address'] ?? $this->market->address,
            'timezone' => $this->data['timezone'] ?? $this->market->timezone,
        ]);

        Notification::make()
            ->title('Сохранено')
            ->success()
            ->send();
    }

    protected function getViewData(): array
    {
        $user = Filament::auth()->user();
        $isSuperAdmin = (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        return [
            'market' => $this->market,
            'canEditMarket' => $this->canEditMarket(),
            'isSuperAdmin' => $isSuperAdmin,

            'marketsUrl' => $isSuperAdmin ? MarketResource::getUrl('index') : null,
            'locationTypesUrl' => MarketLocationTypeResource::getUrl('index'),
            'spaceTypesUrl' => MarketSpaceTypeResource::getUrl('index'),
            'staffUrl' => StaffResource::getUrl('index'),
            'tenantUrl' => TenantResource::getUrl('index'),

            'permissionsUrl' => $isSuperAdmin ? PermissionResource::getUrl('index') : null,
            'rolesUrl' => $isSuperAdmin ? RoleResource::getUrl('index') : null,
            'integrationExchangesUrl' => $isSuperAdmin ? IntegrationExchangeResource::getUrl('index') : null,
        ];
    }

    /**
     * Российские часовые пояса: русские лейблы + IANA-значения.
     */
    protected function timezoneOptionsRu(): array
    {
        $russian = [
            'Europe/Kaliningrad' => 'Калининград',
            'Europe/Moscow' => 'Москва',
            'Europe/Samara' => 'Самара',
            'Asia/Yekaterinburg' => 'Екатеринбург',
            'Asia/Omsk' => 'Омск',
            'Asia/Krasnoyarsk' => 'Красноярск',
            'Asia/Irkutsk' => 'Иркутск',
            'Asia/Yakutsk' => 'Якутск',
            'Asia/Vladivostok' => 'Владивосток',
            'Asia/Magadan' => 'Магадан',
            'Asia/Kamchatka' => 'Камчатка',
        ];

        $out = [];

        foreach ($russian as $tz => $city) {
            $out[$tz] = sprintf('%s (%s)', $city, $this->formatUtcOffset($tz));
        }

        // На случай, если в базе уже сохранено значение вне списка
        $current = $this->data['timezone']
            ?? $this->market?->timezone
            ?? config('app.timezone', 'Europe/Moscow');

        if (filled($current) && ! array_key_exists($current, $out)) {
            $out = [$current => $current] + $out;
        }

        return $out;
    }

    protected function formatUtcOffset(string $timezone): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $tz);

            $offset = $tz->getOffset($now);
            $sign = $offset >= 0 ? '+' : '-';
            $offset = abs($offset);

            $hours = intdiv($offset, 3600);
            $minutes = intdiv($offset % 3600, 60);

            if ($minutes === 0) {
                return "UTC{$sign}{$hours}";
            }

            return sprintf('UTC%s%d:%02d', $sign, $hours, $minutes);
        } catch (\Throwable $e) {
            return $timezone;
        }
    }
}
