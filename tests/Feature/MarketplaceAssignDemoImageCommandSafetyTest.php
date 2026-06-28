<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceAssignDemoImageCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MarketplaceAssignDemoImageCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Apply image assignment (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('Option --market is required.', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('fn (): int => $this->assignDemoImages($market, $profile, $path, $limit, $dryRun),', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:assign-demo-image', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_market_option_is_required_before_lookup(): void
    {
        $this->artisan('marketplace:assign-demo-image', [
            '--profile' => 'default',
            '--path' => 'marketplace/demo/example.jpg',
        ])->assertFailed();
    }

    public function test_profile_option_is_required_before_lookup(): void
    {
        $this->artisan('marketplace:assign-demo-image', [
            '--market' => '1',
            '--path' => 'marketplace/demo/example.jpg',
        ])->assertFailed();
    }

    public function test_path_option_is_required_before_lookup(): void
    {
        $this->artisan('marketplace:assign-demo-image', [
            '--market' => '1',
            '--profile' => 'default',
        ])->assertFailed();
    }
}
