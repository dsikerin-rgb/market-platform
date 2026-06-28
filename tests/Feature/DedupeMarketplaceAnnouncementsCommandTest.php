<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class DedupeMarketplaceAnnouncementsCommandTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/DedupeMarketplaceAnnouncementsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--execute : Delete duplicate rows (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($execute && $marketId === null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('fn (): int => $this->dedupeAnnouncements($marketId, $dryRun),', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:announcements:dedupe', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_requires_market_filter(): void
    {
        $this->artisan('marketplace:announcements:dedupe', [
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_market_filter_must_be_positive_integer(): void
    {
        $this->artisan('marketplace:announcements:dedupe', [
            '--market' => 'not-an-id',
        ])->assertFailed();
    }
}
