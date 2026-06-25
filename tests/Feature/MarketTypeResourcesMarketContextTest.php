<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketLocationTypeResource;
use App\Filament\Resources\MarketSpaceTypeResource;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketTypeResourcesMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_type_resources_read_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 24680;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(MarketLocationTypeResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(MarketSpaceTypeResource::class));
    }

    public function test_type_resources_keep_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 86420;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(MarketLocationTypeResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(MarketSpaceTypeResource::class));
    }

    /**
     * @param class-string $resourceClass
     */
    private function resolvedMarketId(string $resourceClass): ?int
    {
        $method = new ReflectionMethod($resourceClass, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
