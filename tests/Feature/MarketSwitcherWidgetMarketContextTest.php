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

    public function test_widget_syncs_selected_market_through_market_context_session_keys(): void
    {
        $this->syncSelectedMarketId(789);

        foreach ([
            'dashboard_market_id',
            'filament.admin.selected_market_id',
            'filament_admin_market_id',
            'selected_market_id',
        ] as $key) {
            self::assertSame(789, session($key));
        }
    }

    public function test_widget_no_longer_syncs_selected_market_with_direct_session_writes(): void
    {
        $source = (string) file_get_contents(base_path('app/Filament/Widgets/MarketSwitcherWidget.php'));

        self::assertStringContainsString('app(MarketContext::class)->syncSelectedMarketIdInSession($marketId)', $source);
        self::assertStringNotContainsString("session(['dashboard_market_id' => \$this->selectedMarketId])", $source);
        self::assertStringNotContainsString("session(['dashboard_market_id' => \$value])", $source);
        self::assertStringNotContainsString("session(['filament.admin.selected_market_id' => \$this->selectedMarketId])", $source);
        self::assertStringNotContainsString("session(['filament.admin.selected_market_id' => \$value])", $source);
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketSwitcherWidget::class, 'resolveSelectedMarketIdFromContext');
        $method->setAccessible(true);

        return $method->invoke(new MarketSwitcherWidget);
    }

    private function syncSelectedMarketId(?int $marketId): void
    {
        $method = new ReflectionMethod(MarketSwitcherWidget::class, 'syncSelectedMarketId');
        $method->setAccessible(true);

        $method->invoke(new MarketSwitcherWidget, $marketId);
    }
}
