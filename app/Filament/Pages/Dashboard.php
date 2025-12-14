<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpiringContractsWidget;
use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use App\Filament\Widgets\MarketSwitcherWidget;
use App\Filament\Widgets\RecentTenantRequestsWidget;
use App\Filament\Widgets\TenantActivityStatsWidget;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Панель управления';
    protected static ?string $title = 'Панель управления';

    // УБИРАЕМ группу, чтобы не было отдельного заголовка "Панель управления" в меню
    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static ?int $navigationSort = 1;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-home';

    public function getHeading(): string
    {
        return '';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getWidgets(): array
    {
        $user = Filament::auth()->user();

        $widgets = [];

        // Переключатель рынка — только для super-admin
        if ($user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            $widgets[] = MarketSwitcherWidget::class;
        }

        return [
            ...$widgets,

            MarketOverviewStatsWidget::class,
            TenantActivityStatsWidget::class,
            MarketSpacesStatusChartWidget::class,

            ExpiringContractsWidget::class,
            RecentTenantRequestsWidget::class,
        ];
    }
}
