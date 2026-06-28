<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SpaceReviewReconcileCommandMarketContextSourceTest extends TestCase
{
    public function test_space_review_reconcile_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/SpaceReviewReconcileCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->reconcileSpaceReviews(null, $limit, $json, $apply, $maxAutoCloses);', $source);
    }
}
