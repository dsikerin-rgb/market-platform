<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\MarketFeature;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketFeatureMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_market_feature_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 4321;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_market_feature_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 1234;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_market_feature_has_no_selected_context_when_session_is_empty(): void
    {
        self::assertNull($this->resolvedMarketId());
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketFeature::class, 'selectedMarketIdFromContext');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
