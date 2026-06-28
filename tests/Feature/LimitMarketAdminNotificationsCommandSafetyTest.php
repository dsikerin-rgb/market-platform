<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class LimitMarketAdminNotificationsCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_market_filter_and_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/LimitMarketAdminNotificationsToMessages.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--market= : Market ID}', $source);
        self::assertStringContainsString('{--execute : Apply changes (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($execute && $marketId === null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('fn (): int => $this->limitNotifications($preferences, $marketId, $dryRun),', $source);
        self::assertStringContainsString('$query->where(\'market_id\', $marketId);', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('notifications:limit-market-admins-to-messages', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_requires_market_filter(): void
    {
        $this->artisan('notifications:limit-market-admins-to-messages', [
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_market_filter_must_be_positive_integer(): void
    {
        $this->artisan('notifications:limit-market-admins-to-messages', [
            '--market' => 'not-an-id',
        ])->assertFailed();
    }
}
