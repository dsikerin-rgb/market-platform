<?php

namespace App\Filament\Widgets;

use App\Models\TenantContract;
use App\Models\TenantRequest;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class TenantActivityStatsWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Активность арендаторов';

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [];
        }

        $requestQuery = TenantRequest::query();
        $contractQuery = TenantContract::query();

        if (! $user->isSuperAdmin()) {
            if (! $user->market_id) {
                return [];
            }

            $requestQuery->where('market_id', $user->market_id);
            $contractQuery->where('market_id', $user->market_id);
        }

        $requestsLastWeek = (clone $requestQuery)
            ->whereDate('created_at', '>=', Carbon::now()->subDays(7))
            ->count();

        $openRequests = (clone $requestQuery)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $resolvedLastWeek = (clone $requestQuery)
            ->whereIn('status', ['resolved', 'closed'])
            ->whereDate('resolved_at', '>=', Carbon::now()->subDays(7))
            ->count();

        $contractsLastMonth = (clone $contractQuery)
            ->whereDate('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        $contractsFinished = (clone $contractQuery)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [Carbon::now()->subDays(30), Carbon::now()])
            ->count();

        return [
            Stat::make('Новых обращений за 7 дней', $requestsLastWeek),
            Stat::make('Открытых обращений', $openRequests),
            Stat::make('Решённых обращений за 7 дней', $resolvedLastWeek),
            Stat::make('Новых договоров за 30 дней', $contractsLastMonth),
            Stat::make('Завершённых договоров за 30 дней', $contractsFinished),
        ];
    }
}
