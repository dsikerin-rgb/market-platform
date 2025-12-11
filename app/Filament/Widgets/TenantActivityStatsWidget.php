<?php

namespace App\Filament\Widgets;

use App\Models\TenantContract;
use App\Models\TenantRequest;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Card;
use Illuminate\Support\Carbon;

class TenantActivityStatsWidget extends StatsOverviewWidget
{
    protected static ?string $heading = 'Активность арендаторов';

    /**
     * @return array<int, Card>
     */
    protected function getCards(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [
                Card::make('Новых обращений за 7 дней', 0),
                Card::make('Открытых обращений', 0),
                Card::make('Решённых обращений за 7 дней', 0),
                Card::make('Новых договоров за 30 дней', 0),
                Card::make('Завершённых договоров за 30 дней', 0),
            ];
        }

        $requestQuery = TenantRequest::query();
        $contractQuery = TenantContract::query();

        if (! $user->isSuperAdmin()) {
            if (! $user->market_id) {
                return [
                    Card::make('Новых обращений за 7 дней', 0),
                    Card::make('Открытых обращений', 0),
                    Card::make('Решённых обращений за 7 дней', 0),
                    Card::make('Новых договоров за 30 дней', 0),
                    Card::make('Завершённых договоров за 30 дней', 0),
                ];
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
            Card::make('Новых обращений за 7 дней', $requestsLastWeek),
            Card::make('Открытых обращений', $openRequests),
            Card::make('Решённых обращений за 7 дней', $resolvedLastWeek),
            Card::make('Новых договоров за 30 дней', $contractsLastMonth),
            Card::make('Завершённых договоров за 30 дней', $contractsFinished),
        ];
    }
}
