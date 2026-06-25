<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MarketSettings;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketSettingsMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_market_settings_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 47474;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_market_settings_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 74747;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_market_settings_keeps_legacy_filament_admin_selected_market_session_key(): void
    {
        $marketId = 94949;

        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketSettings::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(app(MarketSettings::class));
    }
}
