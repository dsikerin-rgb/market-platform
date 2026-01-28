<?php
# app/Providers/Filament/AdminPanelProvider.php

declare(strict_types=1);

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
            ->sidebarCollapsibleOnDesktop()

            // ВАЖНО:
            // Не используем ->viteTheme() для мелких CSS-правок.
            // ->viteTheme() подменяет тему Filament целиком, и если theme.css не включает базовые стили Filament,
            // получаются "огромные" элементы и разваленная вёрстка.

            ->login()
            ->passwordReset()
            ->profile()

            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')

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

                        return url('/' . $path . '/requests');
                    })
                    ->openUrlInNewTab()
                    ->visible(function (): bool {
                        $user = Filament::auth()->user();

                        if (! ($user?->isSuperAdmin() ?? false)) {
                            return false;
                        }

                        if (! class_exists(\Laravel\Telescope\Telescope::class)) {
                            return false;
                        }

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

            // Блок с именем/ролью рядом с аватаром (после global search).
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn () => view('filament.components.topbar-user-info'),
            )

            // CSS-оверрайды админки (без Vite/Tailwind).
            // ВНИМАНИЕ: view должен существовать, иначе будет 500.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn () => view('filament.components.admin-overrides-css'),
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
