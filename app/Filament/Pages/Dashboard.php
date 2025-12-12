<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ExpiringContractsWidget;
use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\MarketSpacesStatusChartWidget;
use App\Filament\Widgets\MarketSwitcherWidget;
use App\Filament\Widgets\RecentTenantRequestsWidget;
use App\Filament\Widgets\TenantActivityStatsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
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
        return [
            // только для super-admin, фильтр по рынку (не в правом верхнем углу)
            MarketSwitcherWidget::class,

            MarketOverviewStatsWidget::class,
            TenantActivityStatsWidget::class,
            MarketSpacesStatusChartWidget::class,

            // таблицы: без колонки "Рынок"
            ExpiringContractsWidget::class,
            RecentTenantRequestsWidget::class,
        ];
    }
}
