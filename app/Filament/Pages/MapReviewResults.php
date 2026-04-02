<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\Market;
use App\Services\MarketMap\MapReviewResultsService;
use Filament\Facades\Filament;
use Filament\Pages\Page;

class MapReviewResults extends Page
{
    protected static ?string $title = 'Результаты ревизии';
    protected static ?string $navigationLabel = 'Результаты ревизии';
    protected static ?string $slug = 'map-review-results';

    protected static \UnitEnum|string|null $navigationGroup = null;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.map-review-results';

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && $user->isSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable|null
    {
        return null;
    }

    protected function getViewData(): array
    {
        $marketId = $this->marketId();
        $market = $marketId ? Market::query()->find($marketId) : null;
        /** @var MapReviewResultsService $service */
        $service = app(MapReviewResultsService::class);

        $needsAttention = $marketId ? $service->needsAttention($marketId, 50) : [];
        $appliedChanges = $marketId ? $service->appliedChanges($marketId, 50) : [];

        return [
            'market' => $market,
            'marketName' => $market?->name ?? 'Выберите рынок',
            'hasSelectedMarket' => $market !== null,
            'progress' => $marketId ? $service->buildProgress($marketId) : [
                'total' => 0,
                'reviewed' => 0,
                'remaining' => 0,
                'percent' => 0,
                'counts' => [],
                'labels' => [],
            ],
            'needsAttention' => array_map(
                fn (array $row): array => $row + [
                    'map_url' => $this->mapUrl((int) $row['space_id']),
                    'space_url' => $this->spaceUrl((int) $row['space_id']),
                ],
                $needsAttention
            ),
            'appliedChanges' => array_map(
                fn (array $row): array => $row + [
                    'map_url' => $this->mapUrl((int) $row['space_id']),
                    'space_url' => $this->spaceUrl((int) $row['space_id']),
                ],
                $appliedChanges
            ),
        ];
    }

    protected static function selectedMarketIdFromSession(): ?int
    {
        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value = session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        return filled($value) ? (int) $value : null;
    }

    protected function marketId(): ?int
    {
        return static::selectedMarketIdFromSession()
            ?? Filament::auth()->user()?->market_id;
    }

    private function mapUrl(int $spaceId): string
    {
        return route('filament.admin.market-map', [
            'mode' => 'review',
            'market_space_id' => $spaceId,
        ]);
    }

    private function spaceUrl(int $spaceId): string
    {
        return MarketSpaceResource::getUrl('edit', ['record' => $spaceId]);
    }
}
