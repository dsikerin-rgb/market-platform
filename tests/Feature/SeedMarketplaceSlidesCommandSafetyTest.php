<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SeedMarketplaceSlidesCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_market_filter_and_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/SeedMarketplaceSlidesCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--market= : Market ID}', $source);
        self::assertStringContainsString('{--execute : Apply changes (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($execute && $marketId === null) {', $source);
        self::assertStringContainsString('app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('fn (): array => $this->seedDefaultsForMarket($market, $overwrite, $dryRun),', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:slides:seed-defaults', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_requires_market_filter(): void
    {
        $this->artisan('marketplace:slides:seed-defaults', [
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_market_filter_must_be_positive_integer(): void
    {
        $this->artisan('marketplace:slides:seed-defaults', [
            '--market' => 'not-an-id',
        ])->assertFailed();
    }
}
