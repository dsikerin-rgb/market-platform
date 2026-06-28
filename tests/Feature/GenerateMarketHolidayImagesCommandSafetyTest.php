<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class GenerateMarketHolidayImagesCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/GenerateMarketHolidayImages.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Generate files and update holidays (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($execute && trim((string) $this->option(\'market\')) === \'\') {', $source);
        self::assertStringContainsString('app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('fn (): array => $this->generateImagesForMarket($market, $from, $to, $overwrite, $dryRun),', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
        self::assertStringContainsString('Storage::disk(\'public\')->put($fileName, $svg);', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('market:holidays:generate-images', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_requires_market_filter(): void
    {
        $this->artisan('market:holidays:generate-images', [
            '--execute' => true,
        ])->assertFailed();
    }
}
