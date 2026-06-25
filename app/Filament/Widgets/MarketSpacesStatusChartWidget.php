<?php
# app/Filament/Widgets/MarketSpacesStatusChartWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\User;
use App\Support\MarketContext;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class MarketSpacesStatusChartWidget extends ChartWidget
{
    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 2,
    ];

    protected ?string $pollingInterval = null;

    protected ?string $heading = 'Площадь по статусам';

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

        $metrics = MarketSpaceDashboardMetrics::summarize($marketId);
        $totalArea = (float) $metrics['total_area_sqm'];

        if ($totalArea <= 0) {
            return $this->emptyChart('Нет торговых мест');
        }

        $occupiedArea = max((float) $metrics['occupied_area_sqm'], 0.0);
        $vacantArea = max((float) $metrics['vacant_area_sqm'], 0.0);
        $maintenanceArea = max((float) $metrics['maintenance_area_sqm'], 0.0);

        $labels = [];
        $data = [];
        $backgroundColor = [];
        $borderColor = [];

        if ($vacantArea > 0) {
            $labels[] = $this->makeStatusLabel('Свободно', $vacantArea, $totalArea);
            $data[] = round($vacantArea, 2);
            $backgroundColor[] = '#94A3B8';
            $borderColor[] = '#FFFFFF';
        }

        if ($occupiedArea > 0) {
            $labels[] = $this->makeStatusLabel('Сдано арендаторам', $occupiedArea, $totalArea);
            $data[] = round($occupiedArea, 2);
            $backgroundColor[] = '#22C55E';
            $borderColor[] = '#FFFFFF';
        }

        if ($maintenanceArea > 0) {
            $labels[] = $this->makeStatusLabel('Служебная площадь УК', $maintenanceArea, $totalArea);
            $data[] = round($maintenanceArea, 2);
            $backgroundColor[] = '#A855F7';
            $borderColor[] = '#FFFFFF';
        }

        if ($data === []) {
            return $this->emptyChart('Нет данных по статусам');
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'data' => $data,
                    'backgroundColor' => $backgroundColor,
                    'borderColor' => $borderColor,
                    'borderWidth' => 2,
                    'hoverOffset' => 6,
                ],
            ],
        ];
    }

    protected function resolveMarketIdForWidget($user): ?int
    {
        return app(MarketContext::class)->currentMarketId($user instanceof User ? $user : null);
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

    private function formatAreaLabel(float $value): string
    {
        $precision = abs($value - round($value)) < 0.01 ? 0 : 1;

        return number_format($value, $precision, ',', ' ') . ' м²';
    }

    private function makeStatusLabel(string $label, float $area, float $totalArea): string
    {
        return $label
            . ' — '
            . $this->formatPercentLabel($area, $totalArea)
            . ' ('
            . $this->formatAreaLabel($area)
            . ')';
    }

    private function formatPercentLabel(float $value, float $total): string
    {
        if ($total <= 0.0) {
            return '0 %';
        }

        $percent = ($value / $total) * 100;
        $precision = abs($percent - round($percent)) < 0.05 ? 0 : 1;

        return number_format($percent, $precision, ',', ' ') . ' %';
    }
}
