<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\MarketSpaceResource;
use App\Models\Market;
use App\Services\Ai\AiReviewService;
use App\Services\MarketMap\MapReviewResultsService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

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

        // AI summaries для спорных мест (read-only, кешировано через shared service)
        $aiSummaries = $marketId ? $this->buildAiSummaries($marketId, $needsAttention) : [];

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
            'aiSummaries' => $aiSummaries,
        ];
    }

    /**
     * Собрать AI reviews для первых 5 спорных мест.
     * Делегирует shared AiReviewService (policy, validation, caching).
     *
     * Quick cooldown: только на connectivity_fail (5 мин).
     * Policy/semantic fail НЕ включает cooldown — это единичный случай.
     *
     * @param  list<array{space_id:int}>  $needsAttention
     * @return array<int, array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}|null>
     */
    protected function buildAiSummaries(int $marketId, array $needsAttention): array
    {
        if (empty($needsAttention)) {
            return [];
        }

        $reviewService = app(AiReviewService::class);

        if (! $reviewService->isAvailable()) {
            return [];
        }

        // Quick cooldown: только если GigaChat недоступен на уровне сети/auth
        $downKey = 'gigachat_connectivity_down';
        if (Cache::get($downKey)) {
            return [];
        }

        // Лимит: максимум 5 первых кейсов за один рендер страницы
        $limited = array_slice($needsAttention, 0, AiReviewService::MAX_REVIEWS_PER_BATCH);

        $results = [];
        $connectivityFails = 0;

        foreach ($limited as $row) {
            $spaceId = (int) $row['space_id'];
            $fetchResult = $reviewService->getReviewForSpace($spaceId, $marketId);
            $results[$spaceId] = $fetchResult['review'];

            // connectivity_fail → candidate для cooldown
            if ($fetchResult['error_type'] === 'connectivity') {
                $connectivityFails++;
            }
            // policy_fail → НЕ включаем в cooldown (единичный случай)
        }

        // Если все запросы — connectivity_fail — блокируем на 5 мин
        if ($connectivityFails === count($limited)) {
            Cache::put($downKey, true, now()->addMinutes(5));
        }

        return $results;
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
