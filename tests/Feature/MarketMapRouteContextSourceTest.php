<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketMapRouteContextSourceTest extends TestCase
{
    public function test_market_map_resolver_accepts_request_and_space_context(): void
    {
        $source = file_get_contents(base_path('routes/web.php'));

        self::assertIsString($source);
        self::assertStringContainsString('$resolveMarketForMap = function (Request|MarketSpace|null $context = null): Market', $source);
        self::assertStringContainsString('$context instanceof MarketSpace', $source);
        self::assertStringContainsString('$context instanceof Request', $source);
        self::assertStringContainsString("Route::get('/admin/market-map'", $source);
        self::assertStringContainsString('$market = $resolveMarketForMap($request);', $source);
        self::assertStringContainsString('syncSelectedMarketIdInSession((int) $market->id, \'admin\')', $source);
    }
}
