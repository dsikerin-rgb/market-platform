<?php
# app/Filament/Widgets/MarketAverageRentRateWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\User;
use App\Support\MarketContext;
use App\Support\MarketSpaces\MarketSpaceDashboardMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MarketAverageRentRateWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = [
        'md' => 1,
        'xl' => 1,
    ];

    protected ?string $pollingInterval = null;

    protected ?string $heading = null;

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return [$this->buildEmptyStat('Нет пользователя')];
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return [$this->buildEmptyStat('Выберите рынок')];
        }

        $metrics = MarketSpaceDashboardMetrics::summarize($marketId);
        $averageRate = $metrics['average_rent_rate_per_sqm'];
        $pricedArea = (float) ($metrics['priced_area_sqm'] ?? 0);
        $spacesUrl = MarketSpaceResource::getUrl('index');

        if (! is_numeric($averageRate) || $averageRate <= 0) {
            return [$this->buildEmptyStat('Нет данных по ставкам', $spacesUrl)];
        }

        $stat = Stat::make('Средняя ставка, ₽/м²', $this->formatMoney((float) $averageRate) . ' ₽')
            ->description('Взвешено по ' . $this->formatArea($pricedArea) . ' с заданной ставкой')
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->url($spacesUrl)
            ->extraAttributes([
                'class' => 'cursor-pointer',
                'title' => 'Открыть торговые места',
            ])
            ->descriptionIcon('heroicon-m-arrow-top-right-on-square');

        return [$stat];
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) $user->market_id
        );
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        return app(MarketContext::class)->currentMarketId($user instanceof User ? $user : null);
    }

    private function buildEmptyStat(string $description, ?string $url = null): Stat
    {
        $stat = Stat::make('Средняя ставка, ₽/м²', '—')
            ->description($description)
            ->icon('heroicon-o-banknotes')
            ->color('gray');

        if ($url !== null) {
            $stat
                ->url($url)
                ->extraAttributes([
                    'class' => 'cursor-pointer',
                    'title' => 'Открыть торговые места',
                ])
                ->descriptionIcon('heroicon-m-arrow-top-right-on-square');
        }

        return $stat;
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, abs($value - round($value)) < 0.01 ? 0 : 1, ',', ' ');
    }

    private function formatArea(float $value): string
    {
        $precision = abs($value - round($value)) < 0.01 ? 0 : 1;

        return number_format($value, $precision, ',', ' ') . ' м²';
    }
}
