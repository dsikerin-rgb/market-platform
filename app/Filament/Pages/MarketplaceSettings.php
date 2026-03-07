<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MarketplaceSettings extends Page
{
    protected static ?string $navigationLabel = 'Настройки маркетплейса';

    protected static \UnitEnum|string|null $navigationGroup = null;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'marketplace-settings';

    protected static ?string $title = 'Настройки маркетплейса';

    protected string $view = 'filament.pages.marketplace-settings';

    public ?Market $market = null;

    public bool $isSuperAdmin = false;

    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'brand_name' => 'Маркетплейс Экоярмарки',
        'logo_path' => null,
        'hero_title' => 'Покупки на Экоярмарке в одном месте',
        'hero_subtitle' => 'Единая витрина товаров, карта Экоярмарки, прямой чат с продавцами, отзывы и анонсы мероприятий.',
        'public_phone' => '+7 (3852) 55-67-55',
        'public_email' => 'Ekobarnaul22@yandex.ru',
        'public_address' => null,
        'slider_enabled' => true,
        'slider_autoplay_enabled' => true,
        'slider_autoplay_interval_ms' => 7000,
        'legacy_site_merge_enabled' => true,
        'allow_public_sales_without_active_contracts' => false,
    ];

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return static::canManageMarketplaceSettings();
    }

    public function mount(): void
    {
        $user = Filament::auth()->user();

        $this->isSuperAdmin = (bool) $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
        $this->market = $this->resolveMarketForUser();

        abort_unless($this->market, 404);

        $settings = (array) (($this->market->settings ?? [])['marketplace'] ?? []);

        $this->form->fill([
            'brand_name' => trim((string) ($settings['brand_name'] ?? '')) ?: 'Маркетплейс Экоярмарки',
            'logo_path' => $settings['logo_path'] ?? null,
            'hero_title' => trim((string) ($settings['hero_title'] ?? '')) ?: 'Покупки на Экоярмарке в одном месте',
            'hero_subtitle' => trim((string) ($settings['hero_subtitle'] ?? '')) ?: 'Единая витрина товаров, карта Экоярмарки, прямой чат с продавцами, отзывы и анонсы мероприятий.',
            'public_phone' => trim((string) ($settings['public_phone'] ?? '+7 (3852) 55-67-55')),
            'public_email' => trim((string) ($settings['public_email'] ?? 'Ekobarnaul22@yandex.ru')),
            'public_address' => trim((string) ($settings['public_address'] ?? ($this->market->address ?? ''))),
            'slider_enabled' => array_key_exists('slider_enabled', $settings) ? (bool) $settings['slider_enabled'] : true,
            'slider_autoplay_enabled' => array_key_exists('slider_autoplay_enabled', $settings) ? (bool) $settings['slider_autoplay_enabled'] : true,
            'slider_autoplay_interval_ms' => is_numeric($settings['slider_autoplay_interval_ms'] ?? null)
                ? (int) $settings['slider_autoplay_interval_ms']
                : 7000,
            'legacy_site_merge_enabled' => array_key_exists('legacy_site_merge_enabled', $settings) ? (bool) $settings['legacy_site_merge_enabled'] : true,
            'allow_public_sales_without_active_contracts' => array_key_exists('allow_public_sales_without_active_contracts', $settings)
                ? (bool) $settings['allow_public_sales_without_active_contracts']
                : (bool) config('marketplace.contracts.allow_public_sales_without_active_contracts', false),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Бренд и контакты')
                    ->schema([
                        Forms\Components\TextInput::make('brand_name')
                            ->label('Название маркетплейса')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\FileUpload::make('logo_path')
                            ->label('Логотип')
                            ->image()
                            ->imageEditor()
                            ->disk('public')
                            ->directory('marketplace/brand')
                            ->visibility('public')
                            ->maxSize(5120),

                        Forms\Components\TextInput::make('public_phone')
                            ->label('Телефон')
                            ->placeholder('+7 (3852) 55-67-55')
                            ->helperText('Можно указывать номер в обычном формате: +7 (3852) 55-67-55')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('public_email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('public_address')
                            ->label('Адрес')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Главный экран')
                    ->description('Этот блок управляет главным сообщением маркетплейса. Календарные акции, праздники и санитарные дни продолжают публиковаться отдельно в разделе анонсов и событий.')
                    ->schema([
                        Forms\Components\TextInput::make('hero_title')
                            ->label('Заголовок hero-блока')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('hero_subtitle')
                            ->label('Подзаголовок hero-блока')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Слайдер информации')
                    ->description('Слайды дополняют главную страницу маркетплейса и не заменяют события из календаря. Календарь остаётся отдельным слоем публикаций.')
                    ->schema([
                        Forms\Components\Toggle::make('slider_enabled')
                            ->label('Показывать слайдер на главной')
                            ->default(true),

                        Forms\Components\Toggle::make('slider_autoplay_enabled')
                            ->label('Включить автопрокрутку')
                            ->default(true),

                        Forms\Components\TextInput::make('slider_autoplay_interval_ms')
                            ->label('Интервал автопрокрутки')
                            ->numeric()
                            ->minValue(4000)
                            ->maxValue(20000)
                            ->step(500)
                            ->suffix('мс'),

                        Forms\Components\Toggle::make('legacy_site_merge_enabled')
                            ->label('Показывать стартовые слайды как fallback')
                            ->helperText('Если в разделе слайдов нет активного контента, главная страница возьмёт базовый набор автоматически.')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Публикация продавцов')
                    ->description('Управляет правилом публичного отображения товаров и витрин на маркетплейсе.')
                    ->schema([
                        Forms\Components\Toggle::make('allow_public_sales_without_active_contracts')
                            ->label('Показывать товары и витрины без действующих договоров')
                            ->helperText('Временный режим для запуска или сбоя интеграции. Если включён, публичный маркетплейс не будет скрывать продавцов без активного договора в системе.')
                            ->default(false),
                    ])
                    ->columns(2),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->market, 404);

        $state = $this->form->getState();
        $settings = (array) ($this->market->settings ?? []);
        $settings['marketplace'] = [
            'brand_name' => trim((string) ($state['brand_name'] ?? '')) ?: 'Маркетплейс Экоярмарки',
            'logo_path' => $state['logo_path'] ?? null,
            'hero_title' => trim((string) ($state['hero_title'] ?? '')) ?: 'Покупки на Экоярмарке в одном месте',
            'hero_subtitle' => trim((string) ($state['hero_subtitle'] ?? '')),
            'public_phone' => trim((string) ($state['public_phone'] ?? '')),
            'public_email' => trim((string) ($state['public_email'] ?? '')),
            'public_address' => trim((string) ($state['public_address'] ?? '')),
            'slider_enabled' => (bool) ($state['slider_enabled'] ?? true),
            'slider_autoplay_enabled' => (bool) ($state['slider_autoplay_enabled'] ?? true),
            'slider_autoplay_interval_ms' => max(4000, min((int) ($state['slider_autoplay_interval_ms'] ?? 7000), 20000)),
            'legacy_site_merge_enabled' => (bool) ($state['legacy_site_merge_enabled'] ?? true),
            'allow_public_sales_without_active_contracts' => (bool) ($state['allow_public_sales_without_active_contracts'] ?? false),
        ];

        $this->market->settings = $settings;
        $this->market->save();

        Notification::make()
            ->title('Настройки маркетплейса сохранены')
            ->success()
            ->send();
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
        $value = session('dashboard_market_id');

        if (! filled($value)) {
            $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';
            $value = session("filament.{$panelId}.selected_market_id");
        }

        if (! filled($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
    }

    protected static function canManageMarketplaceSettings(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        $hasMarket = (int) ($user->market_id ?? 0) > 0;

        if (! $hasMarket) {
            return false;
        }

        $hasRoleAccess = method_exists($user, 'hasRole') && $user->hasRole('market-admin');
        $hasPermissionAccess = method_exists($user, 'can') && (
            $user->can('marketplace.settings.view')
            || $user->can('marketplace.settings.update')
        );

        return $hasRoleAccess || $hasPermissionAccess;
    }
}
