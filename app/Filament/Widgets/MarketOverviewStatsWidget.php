<?php

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Card;

class MarketOverviewStatsWidget extends StatsOverviewWidget
{
    protected static ?string $heading = 'Сводка по рынку';

    /**
     * @return array<int, Card>
     */
    protected function getCards(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [
                Card::make('Рынков доступно', 0),
                Card::make('Арендаторов на рынке', 0),
                Card::make('Торговых мест всего', 0),
                Card::make('Торговых мест занято', 0),
                Card::make('Торговых мест свободно', 0),
                Card::make('Активных договоров', 0),
            ];
        }

        $marketQuery = Market::query();
        $tenantQuery = Tenant::query();
        $spaceQuery = MarketSpace::query();
        $contractQuery = TenantContract::query()->where('status', 'active');

        if (! $user->isSuperAdmin()) {
            if (! $user->market_id) {
                return [
                    Card::make('Рынков доступно', 0),
                    Card::make('Арендаторов на рынке', 0),
                    Card::make('Торговых мест всего', 0),
                    Card::make('Торговых мест занято', 0),
                    Card::make('Торговых мест свободно', 0),
                    Card::make('Активных договоров', 0),
                ];
            }

            $marketQuery->where('id', $user->market_id);
            $tenantQuery->where('market_id', $user->market_id);
            $spaceQuery->where('market_id', $user->market_id);
            $contractQuery->where('market_id', $user->market_id);

            return [
                Card::make('Рынков доступно', $marketQuery->count()),
                Card::make('Арендаторов на рынке', $tenantQuery->count()),
                Card::make('Торговых мест всего', $spaceQuery->count()),
                Card::make('Торговых мест занято', (clone $spaceQuery)->where('status', 'occupied')->count()),
                Card::make('Торговых мест свободно', (clone $spaceQuery)->where('status', 'free')->count()),
                Card::make('Активных договоров на рынке', $contractQuery->count()),
            ];
        }

        return [
            Card::make('Всего рынков', $marketQuery->count()),
            Card::make('Всего арендаторов', $tenantQuery->count()),
            Card::make('Торговых мест всего', $spaceQuery->count()),
            Card::make('Торговых мест занято', (clone $spaceQuery)->where('status', 'occupied')->count()),
            Card::make('Торговых мест свободно', (clone $spaceQuery)->where('status', 'free')->count()),
            Card::make('Активных договоров', $contractQuery->count()),
        ];
    }
}
