<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MaintenanceReconcileCommandMarketContextSourceTest extends TestCase
{
    public function test_maintenance_reconcile_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ReconcileMaintenanceSpacesCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId > 0) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->reconcileMaintenanceSpaces(null, $apply);', $source);
    }
}
