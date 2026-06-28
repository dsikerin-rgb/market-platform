<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SeedSeasonalPromotionsCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_guards_and_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/SeedSeasonalPromotionsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--all-markets : Allow --execute to affect every active market}', $source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Create or update promotions (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($execute && blank($this->option(\'market\')) && ! (bool) $this->option(\'all-markets\')) {', $source);
        self::assertStringContainsString('app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
        self::assertStringContainsString('DRY RUN: no changes applied. Use --execute --market=... or --execute --all-markets to apply.', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('market:holidays:seed-promotions', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_requires_market_filter_or_all_markets_flag(): void
    {
        $this->artisan('market:holidays:seed-promotions', [
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_cannot_combine_market_filter_and_all_markets_flag(): void
    {
        $this->artisan('market:holidays:seed-promotions', [
            '--execute' => true,
            '--market' => '1',
            '--all-markets' => true,
        ])->assertFailed();
    }
}
