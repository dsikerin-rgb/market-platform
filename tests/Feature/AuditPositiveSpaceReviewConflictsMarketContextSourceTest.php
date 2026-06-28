<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AuditPositiveSpaceReviewConflictsMarketContextSourceTest extends TestCase
{
    public function test_positive_space_review_conflict_audit_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/AuditPositiveSpaceReviewConflicts.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->auditPositiveConflicts(null, $limit, $apply, $maxAutoCloses, $json);', $source);
    }
}
