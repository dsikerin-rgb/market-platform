<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceWarmDemoAssetsCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_dry_run_guards(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MarketplaceWarmDemoAssetsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Download and localize demo assets (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
        self::assertStringContainsString('MarketplaceDemoAssetLocalizer::localize($source, \'products/\'.$profile, $force);', $source);
        self::assertStringContainsString('MarketplaceMediaStorage::normalizeLocalPublicTreePermissions', $source);
        self::assertStringContainsString('DRY RUN: no assets were downloaded or localized. Use --execute to apply.', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:warm-demo-assets', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_default_dry_run_counts_all_demo_asset_banks_without_writes(): void
    {
        $this->artisan('marketplace:warm-demo-assets', [
            '--limit' => 1,
        ])
            ->expectsOutput('Would warm 10 demo assets.')
            ->expectsOutput('DRY RUN: no assets were downloaded or localized. Use --execute to apply.')
            ->assertSuccessful();
    }

    public function test_profile_dry_run_counts_selected_profile_without_writes(): void
    {
        $this->artisan('marketplace:warm-demo-assets', [
            '--profile' => 'ready_food',
            '--limit' => 2,
            '--dry-run' => true,
        ])
            ->expectsOutput('Would warm 2 demo assets.')
            ->assertSuccessful();
    }
}
