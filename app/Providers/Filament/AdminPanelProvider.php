<?php
# app/Providers/Filament/AdminPanelProvider.php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login as AdminLogin;
use App\Filament\Pages\AiAgentSettingsPage;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MailDiagnostics;
use App\Filament\Pages\MapReviewResults;
use App\Filament\Pages\MarketSettings;
use App\Filament\Pages\MarketplaceSettings;
use App\Filament\Pages\OpsDiagnostics;
use App\Filament\Pages\OneCReconciliation;
use App\Filament\Pages\OneCDebtDecisionPreview;
use App\Filament\Pages\OneCSettlements;
use App\Filament\Pages\ReportsHub;
use App\Filament\Pages\Requests;
use App\Filament\Pages\SettingsHub;
use App\Filament\Pages\UserNotificationSettings;
use App\Filament\Pages\UserProfile;
use App\Filament\Pages\WorkProgress;
use App\Filament\Widgets\ExpiringContractsWidget;
use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use App\Filament\Widgets\RecentTenantRequestsWidget;
use App\Filament\Widgets\TenantActivityStatsWidget;
use App\Http\Middleware\RestoreAdminFromImpersonation;
use App\Http\Middleware\TrackAdminUserPresence;
use App\Models\Market;
use App\Support\MarketContext;
use App\Support\MarketplacePublicUrl;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Saade\FilamentFullCalendar\FilamentFullCalendarPlugin;

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

            ->login(AdminLogin::class)
            ->passwordReset()
            ->profile(UserProfile::class)

            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')

            ->userMenuItems([
                'notification_settings' => MenuItem::make()
                    ->label('Кабинет уведомлений')
                    ->icon('heroicon-o-bell-alert')
                    ->url(fn (): string => UserNotificationSettings::getUrl())
                    ->visible(fn (): bool => (bool) Filament::auth()->user()),

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
            // super-admin -> название выбранного рынка
            // остальные -> название рынка пользователя
            ->brandName(function (): string {
                $user = Filament::auth()->user();

                if (! $user) {
                    return 'Управление рынком';
                }

                if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
                    $marketId = app(MarketContext::class)->currentMarketId($user);

                    if ($marketId) {
                        return (string) (Market::query()->whereKey($marketId)->value('name') ?: 'Управление рынком');
                    }

                    return 'Управление рынком';
                }

                return $user->market?->name
                    ?? (string) ($user->market_name ?? null)
                    ?? 'Рынок';
            })

            ->colors([
                'primary' => Color::Sky,
            ])

            // Favicon (нативный способ Filament).
            // PNG поддерживается всеми современными браузерами.
            ->favicon(url('favicon.png'))

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')

            ->pages([
                Dashboard::class,
                SettingsHub::class,
                MarketSettings::class,
                MarketplaceSettings::class,
                AiAgentSettingsPage::class,
                MapReviewResults::class,
                OneCReconciliation::class,
                OneCSettlements::class,
                OneCDebtDecisionPreview::class,
                ReportsHub::class,
                Requests::class,
                MailDiagnostics::class,
                OpsDiagnostics::class,
                WorkProgress::class,
                UserNotificationSettings::class,
            ])

            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                MarketOverviewStatsWidget::class,
                TenantActivityStatsWidget::class,
                MarketSpacesStatusChartWidget::class,
                ExpiringContractsWidget::class,
                RecentTenantRequestsWidget::class,
            ])

            ->plugins([
                FilamentFullCalendarPlugin::make()->selectable(),
            ])

            // Кнопка слева от поля global search (в topbar). НЕ сдвигает контент дашборда вниз.
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn () => view('filament.components.session-expiry-guard'),
            )

            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                function () {
                    $user = Filament::auth()->user();

                    if (! $user) {
                        return null;
                    }

                    $mapUrl = route('filament.admin.market-map', [
                        'return_url' => request()->fullUrl(),
                    ]);
                    $marketplaceUrl = app(MarketplacePublicUrl::class)->forCurrentAdmin($user);

                    return view('filament.components.topbar-quick-links', [
                        'mapUrl' => $mapUrl,
                        'marketplaceUrl' => $marketplaceUrl,
                    ]);
                },
            )

            // Блок с именем/ролью рядом с аватаром (после global search).
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_AFTER,
                fn () => view('filament.components.topbar-user-info'),
            )

            // CSS-оверрайды админки (без Vite/Tailwind).
            // ВНИМАНИЕ: view должен существовать, иначе будет 500.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(
                    view('filament.components.pwa-meta')->render()
                    . view('filament.components.admin-overrides-css')->render(),
                ),
            )

            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): HtmlString => new HtmlString(
                    view('filament.components.online-staff-rail-hook')->render()
                    . view('filament.components.learning-mode-overlay')->render(),
                ),
            )

            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                RestoreAdminFromImpersonation::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                TrackAdminUserPresence::class,
            ]);
    }
}
