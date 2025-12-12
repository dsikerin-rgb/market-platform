<?php

namespace App\Filament\Widgets;

use App\Models\MarketSpace;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Collection;

class MarketSpacesStatusChartWidget extends ChartWidget
{
    protected ?string $heading = 'Статусы торговых мест';

    protected function getType(): string
    {
        return 'pie';
    }

    protected function getData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [
                'labels' => ['Свободно', 'Занято', 'Зарезервировано', 'На обслуживании'],
                'datasets' => [['data' => [0, 0, 0, 0]]],
            ];
        }

        $query = MarketSpace::query();

        if (! $user->isSuperAdmin()) {
            if (! $user->market_id) {
                return [
                    'labels' => ['Свободно', 'Занято', 'Зарезервировано', 'На обслуживании'],
                    'datasets' => [['data' => [0, 0, 0, 0]]],
                ];
            }

            $query->where('market_id', $user->market_id);
        }

        $counts = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $ordered = $this->orderedStatuses($counts);

        return [
            'labels' => ['Свободно', 'Занято', 'Зарезервировано', 'На обслуживании'],
            'datasets' => [['data' => $ordered->values()->toArray()]],
        ];
    }

    private function orderedStatuses(Collection $counts): Collection
    {
        return collect([
            'free' => 0,
            'occupied' => 0,
            'reserved' => 0,
            'maintenance' => 0,
        ])->map(fn (int $default, string $status): int => (int) ($counts[$status] ?? 0));
    }
}
