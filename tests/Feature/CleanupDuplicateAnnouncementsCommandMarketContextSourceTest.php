<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class CleanupDuplicateAnnouncementsCommandMarketContextSourceTest extends TestCase
{
    public function test_cleanup_duplicate_announcements_wraps_market_execution_and_defaults_to_dry_run(): void
    {
        $source = file_get_contents(app_path('Console/Commands/CleanupDuplicateAnnouncements.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--execute : Apply changes (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
        self::assertStringContainsString('$deleted = $dryRun ? $duplicatesQuery->count() : $duplicatesQuery->delete();', $source);
    }
}
