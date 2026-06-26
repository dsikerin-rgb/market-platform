<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AppServiceProviderMarketContextTest extends TestCase
{
    public function test_map_review_routes_read_selected_market_through_market_context(): void
    {
        $source = (string) file_get_contents(app_path('Providers/AppServiceProvider.php'));

        $this->assertRouteClosureUsesMarketContext(
            $source,
            "/admin/map-review-results/duplicate-space-search",
            "->name('filament.admin.map-review-results.duplicate-space-search')",
        );

        $this->assertRouteClosureUsesMarketContext(
            $source,
            "/admin/map-review-results/retire-space",
            "->name('filament.admin.map-review-results.retire-space')",
        );
    }

    private function assertRouteClosureUsesMarketContext(string $source, string $routeUri, string $routeName): void
    {
        $start = strpos($source, $routeUri);
        self::assertIsInt($start);

        $end = strpos($source, $routeName, $start);
        self::assertIsInt($end);

        $routeSource = substr($source, $start, $end - $start);

        self::assertStringContainsString(
            'app(MarketContext::class)->selectedMarketIdFromSession($panelId)',
            $routeSource,
        );
        self::assertStringNotContainsString("session('dashboard_market_id')", $routeSource);
        self::assertStringNotContainsString('session("filament.{$panelId}.selected_market_id")', $routeSource);
        self::assertStringNotContainsString('session("filament_{$panelId}_market_id")', $routeSource);
        self::assertStringNotContainsString("session('filament.admin.selected_market_id')", $routeSource);
    }
}
