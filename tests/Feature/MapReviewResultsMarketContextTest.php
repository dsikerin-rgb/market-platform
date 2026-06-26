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

    public function test_map_review_blade_partials_read_selected_market_through_market_context(): void
    {
        foreach ([
            'resources/views/filament/partials/map-review-results-tab-controller.blade.php',
            'resources/views/filament/partials/map-review-historical-group-actions.blade.php',
        ] as $path) {
            $source = (string) file_get_contents(base_path($path));

            self::assertStringContainsString('use App\Support\MarketContext;', $source);
            self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $source);
            self::assertStringNotContainsString("session('dashboard_market_id')", $source);
            self::assertStringNotContainsString('session("filament.{$panelId}.selected_market_id")', $source);
            self::assertStringNotContainsString('session("filament_{$panelId}_market_id")', $source);
            self::assertStringNotContainsString("session('filament.admin.selected_market_id')", $source);
        }
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MapReviewResults::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
