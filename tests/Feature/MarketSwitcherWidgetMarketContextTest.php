<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\MarketSwitcherWidget;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketSwitcherWidgetMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_widget_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 123;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_widget_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 456;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_widget_has_no_selected_context_when_session_is_empty(): void
    {
        self::assertNull($this->resolvedMarketId());
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketSwitcherWidget::class, 'resolveSelectedMarketIdFromContext');
        $method->setAccessible(true);

        return $method->invoke(new MarketSwitcherWidget);
    }
}
