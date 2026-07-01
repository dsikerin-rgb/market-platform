<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketMapRouteMarketContextTest extends TestCase
{
    public function test_market_map_route_resolver_uses_shared_market_context_session_lookup(): void
    {
        $source = (string) file_get_contents(base_path('routes/web.php'));

        $start = strpos($source, '$resolveMarketForMap = function (');
        self::assertIsInt($start);

        $end = strpos($source, '$bindingRiskWarnings', $start);
        self::assertIsInt($end);

        $resolverSource = substr($source, $start, $end - $start);

        self::assertStringContainsString(
            '$marketContext = app(MarketContext::class);',
            $resolverSource,
        );
        self::assertStringContainsString(
            '$selectedMarketId = $marketContext->selectedMarketIdFromSession();',
            $resolverSource,
        );
        self::assertStringNotContainsString('Filament::getCurrentPanel()?->getId()', $resolverSource);
        self::assertStringNotContainsString('session("filament.{$panelId}.selected_market_id")', $resolverSource);
        self::assertStringNotContainsString("session('filament.admin.selected_market_id')", $resolverSource);
    }
}
