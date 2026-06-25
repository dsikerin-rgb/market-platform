<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MapReviewResults;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MapReviewResultsMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_map_review_results_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 141414;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_map_review_results_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 242424;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_map_review_results_keeps_legacy_filament_admin_selected_market_session_key(): void
    {
        $marketId = 343434;

        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_map_review_results_keeps_legacy_filament_underscore_market_session_key(): void
    {
        $marketId = 444444;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MapReviewResults::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
