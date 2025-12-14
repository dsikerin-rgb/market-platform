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

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) $user->market_id
        );
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->zeroStats('Нет пользователя');
        }

        $requestQuery = TenantRequest::query();
        $contractQuery = TenantContract::query();

        if (! method_exists($user, 'isSuperAdmin') || ! $user->isSuperAdmin()) {
            if (! $user->market_id) {
                return $this->zeroStats('Нет привязки к рынку');
            }

            $requestQuery->where('market_id', $user->market_id);
            $contractQuery->where('market_id', $user->market_id);
        }

        $from7 = Carbon::now()->subDays(7);
        $from30 = Carbon::now()->subDays(30);

        $requestsLastWeek = (clone $requestQuery)
            ->whereDate('created_at', '>=', $from7)
            ->count();

        $openRequests = (clone $requestQuery)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        $resolvedLastWeek = (clone $requestQuery)
            ->whereIn('status', ['resolved', 'closed'])
            ->whereDate('resolved_at', '>=', $from7)
            ->count();

        $contractsLastMonth = (clone $contractQuery)
            ->whereDate('created_at', '>=', $from30)
            ->count();

        $contractsFinished = (clone $contractQuery)
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [$from30, Carbon::now()])
            ->count();

        return [
            Stat::make('Новых обращений за 7 дней', $requestsLastWeek),
            Stat::make('Открытых обращений', $openRequests),
            Stat::make('Решённых обращений за 7 дней', $resolvedLastWeek),
            Stat::make('Новых договоров за 30 дней', $contractsLastMonth),
            Stat::make('Завершённых договоров за 30 дней', $contractsFinished),
        ];
    }

    /**
     * @return array<int, Stat>
     */
    private function zeroStats(?string $note = null): array
    {
        $stats = [
            Stat::make('Новых обращений за 7 дней', 0),
            Stat::make('Открытых обращений', 0),
            Stat::make('Решённых обращений за 7 дней', 0),
            Stat::make('Новых договоров за 30 дней', 0),
            Stat::make('Завершённых договоров за 30 дней', 0),
        ];

        if ($note) {
            $stats[0] = $stats[0]->description($note);
        }

        return $stats;
    }
}
