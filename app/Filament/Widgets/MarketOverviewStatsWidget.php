<?php

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MarketOverviewStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Сводка по рынку';

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [
                Stat::make('Арендаторов', 0),
                Stat::make('Торговых мест всего', 0),
                Stat::make('Торговых мест занято', 0),
                Stat::make('Торговых мест свободно', 0),
                Stat::make('Активных договоров', 0),
            ];
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $marketId = $isSuperAdmin
            ? session('dashboard_market_id')
            : $user->market_id;

        $marketsQuery = Market::query();
        $tenantsQuery = Tenant::query();
        $spacesQuery  = MarketSpace::query();
        $contractsQuery = TenantContract::query()->where('status', 'active');

        if ($marketId) {
            $tenantsQuery->where('market_id', $marketId);
            $spacesQuery->where('market_id', $marketId);
            $contractsQuery->where('market_id', $marketId);
        }

        $stats = [];

        if ($isSuperAdmin) {
            $stats[] = Stat::make('Всего рынков', $marketsQuery->count());
        }

        $stats[] = Stat::make('Арендаторов', $tenantsQuery->count());
        $stats[] = Stat::make('Торговых мест всего', $spacesQuery->count());
        $stats[] = Stat::make('Торговых мест занято', (clone $spacesQuery)->where('status', 'occupied')->count());
        $stats[] = Stat::make('Торговых мест свободно', (clone $spacesQuery)->where('status', 'free')->count());
        $stats[] = Stat::make('Активных договоров', $contractsQuery->count());

        return $stats;
    }
}
