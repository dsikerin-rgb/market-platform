<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketHolidayResource;
use App\Filament\Resources\MarketplaceSlideResource;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketContentResourcesMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_market_content_resources_read_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 13579;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(MarketHolidayResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(MarketplaceSlideResource::class));
    }

    public function test_market_content_resources_keep_legacy_filament_admin_selected_market_session_key(): void
    {
        $marketId = 97531;

        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(MarketHolidayResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(MarketplaceSlideResource::class));
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
