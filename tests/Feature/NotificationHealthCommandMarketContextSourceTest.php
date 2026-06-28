<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class NotificationHealthCommandMarketContextSourceTest extends TestCase
{
    public function test_notification_health_check_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/CheckNotificationHealth.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->checkHealth($hours, null, $maxFailedDeliveries, $maxFailedJobs, $notify);', $source);
    }
}
