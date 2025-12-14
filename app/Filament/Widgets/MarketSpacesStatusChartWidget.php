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

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) $user->market_id
        );
    }

    protected function getData(): array
    {
        $user = Filament::auth()->user();

        // На всякий случай (хотя canView уже отсекает)
        if (! $user) {
            return $this->emptyChart();
        }

        $query = MarketSpace::query();

        $marketId = $this->resolveMarketIdForWidget($user);

        // Для market-admin рынок обязателен, иначе данных нет
        if (! (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) && ! $marketId) {
            return $this->emptyChart();
        }

        // Если marketId найден — фильтруем, если нет (super-admin без выбора) — считаем по всем рынкам
        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        /** @var Collection<string|int, int> $counts */
        $counts = $query
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $ordered = $this->orderedStatuses($counts);
        $data = $ordered->values()->map(fn ($v) => (int) $v)->toArray();

        // Chart.js не рисует pie, если сумма = 0
        if (array_sum($data) === 0) {
            return $this->emptyChart();
        }

        return [
            'labels' => ['Свободно', 'Занято', 'Зарезервировано', 'На обслуживании'],
            'datasets' => [
                [
                    'data' => $data,
                ],
            ],
        ];
    }

    /**
     * super-admin: рынок из переключателя (если выбран), иначе null (все рынки)
     * market-admin: всегда user->market_id
     */
    protected function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        // новый ключ
        $key = "filament_{$panelId}_market_id";
        $value = session($key);

        // запасной старый ключ
        if (blank($value)) {
            $value = session('filament.admin.selected_market_id');
        }

        return filled($value) ? (int) $value : null;
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

    private function emptyChart(): array
    {
        return [
            'labels' => ['Нет данных'],
            'datasets' => [
                [
                    'data' => [1],
                ],
            ],
        ];
    }
}
