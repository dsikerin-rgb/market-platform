<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MarketplaceSettings;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketplaceSettingsMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_marketplace_settings_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 57575;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_marketplace_settings_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 85858;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_marketplace_settings_keeps_legacy_filament_admin_selected_market_session_key(): void
    {
        $marketId = 96969;

        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_marketplace_settings_keeps_legacy_filament_underscore_market_session_key(): void
    {
        $marketId = 97979;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketplaceSettings::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(app(MarketplaceSettings::class));
    }
}
