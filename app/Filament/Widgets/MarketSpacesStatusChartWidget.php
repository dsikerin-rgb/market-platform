<?php
# app/Filament/Widgets/MarketSpacesStatusChartWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\MarketSpace;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class MarketSpacesStatusChartWidget extends ChartWidget
{
    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Заполняемость торговых мест';

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


    public function getDescription(): string|Htmlable|null
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return null;
        }

        $sourceUrl = e(MarketSpaceResource::getUrl('index'));

        return new HtmlString(
            "\u{0418}\u{0441}\u{0442}\u{043e}\u{0447}\u{043d}\u{0438}\u{043a}: "
            . "<a href=\"" . $sourceUrl . "\" class=\"font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300\">\u{0444}\u{043e}\u{043d}\u{0434} \u{043c}\u{0435}\u{0441}\u{0442}</a>"
        );
    }

    protected function getOptions(): array
    {
        return [
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    'labels' => [
                        'usePointStyle' => true,
                        'pointStyle' => 'rectRounded',
                        'boxWidth' => 12,
                        'boxHeight' => 12,
                        'padding' => 16,
                    ],
                ],
            ],
        ];
    }

    protected function getData(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->emptyChart('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return $this->emptyChart('Выберите рынок');
        }

        $baseQuery = MarketSpace::query()
            ->where('market_id', $marketId);

        $totalSpaces = (clone $baseQuery)->count();

        if ($totalSpaces <= 0) {
            return $this->emptyChart('Нет торговых мест');
        }

        $occupiedSpaces = (clone $baseQuery)
            ->where('status', 'occupied')
            ->count();

        $occupiedSpaces = max($occupiedSpaces, 0);
        $freeSpaces = max($totalSpaces - $occupiedSpaces, 0);

        return [
            'labels' => [
                'Свободно (' . $freeSpaces . ')',
                'Занято (' . $occupiedSpaces . ')',
            ],
            'datasets' => [
                [
                    'data' => [$freeSpaces, $occupiedSpaces],
                    'backgroundColor' => [
                        '#94A3B8',
                        '#22C55E',
                    ],
                    'borderColor' => [
                        '#FFFFFF',
                        '#FFFFFF',
                    ],
                    'borderWidth' => 2,
                    'hoverOffset' => 6,
                ],
            ],
        ];
    }

    protected function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        return filled($value) ? (int) $value : null;
    }

    private function emptyChart(string $label = 'Нет данных'): array
    {
        return [
            'labels' => [$label],
            'datasets' => [
                [
                    'data' => [1],
                    'backgroundColor' => ['#64748B'],
                    'borderColor' => ['#FFFFFF'],
                    'borderWidth' => 2,
                ],
            ],
        ];
    }
}
