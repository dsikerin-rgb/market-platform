<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MarketSettings;
use App\Filament\Pages\OpsDiagnostics;
use App\Filament\Pages\Requests;
use App\Filament\Widgets\ExpiringContractsWidget;
use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use App\Filament\Widgets\RecentTenantRequestsWidget;
use App\Filament\Widgets\TenantActivityStatsWidget;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Важно: не подключаем тему через Vite, чтобы Filament не тянул resources/css/filament/admin/theme.css
            // и не поднимал Vite/PostCSS overlay при проблемах сборки.
            ->login()
            ->passwordReset()
            ->profile()

            ->userMenuItems([
                'horizon' => MenuItem::make()
                    ->label('Horizon (очереди)')
                    ->url(fn (): string => Route::has('horizon.index')
                        ? route('horizon.index', ['view' => 'dashboard'])
                        : '#')
                    ->openUrlInNewTab()
                    ->visible(function (): bool {
                        $user = Filament::auth()->user();

                        if (! ($user?->isSuperAdmin() ?? false)) {
                            return false;
                        }

                        return Route::has('horizon.index');
                    }),

                'telescope' => MenuItem::make()
                    ->label('Telescope (диагностика)')
                    ->url(function (): string {
                        $path = trim((string) config('telescope.path', 'telescope'), '/');

                        // Наиболее полезный стартовый экран.
                        return url('/' . $path . '/requests');
                    })
                    ->openUrlInNewTab()
                    ->visible(function (): bool {
                        $user = Filament::auth()->user();

                        if (! ($user?->isSuperAdmin() ?? false)) {
                            return false;
                        }

                        // Если пакет не установлен (например, окружение без Telescope) — пункт не показываем.
                        if (! class_exists(\Laravel\Telescope\Telescope::class)) {
                            return false;
                        }

                        // Если Telescope выключен через конфиг/.env — пункт не показываем.
                        return (bool) config('telescope.enabled', true);
                    }),
            ])

            // ВАЖНО: динамически, на каждый запрос.
            // super-admin -> "Управление рынком"
            // остальные -> название рынка пользователя
            ->brandName(function (): string {
                $user = Filament::auth()->user();

                if (! $user) {
                    return 'Управление рынком';
                }

                if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                    return 'Управление рынком';
                }

                return $user->market?->name
                    ?? (string) ($user->market_name ?? null)
                    ?? 'Рынок';
            })

            ->colors([
                'primary' => Color::Amber,
            ])

            ->navigationGroups([
                'Рынки',
                'Рынок',
                'Оперативная работа',
                'Ops',
            ])

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')

            ->pages([
                Dashboard::class,
                MarketSettings::class,
                Requests::class,

                // Ops-инструменты
                OpsDiagnostics::class,
            ])

            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                MarketOverviewStatsWidget::class,
                TenantActivityStatsWidget::class,
                MarketSpacesStatusChartWidget::class,
                ExpiringContractsWidget::class,
                RecentTenantRequestsWidget::class,
            ])

            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn () => view('filament.components.topbar-user-info')->render(),
            )

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
