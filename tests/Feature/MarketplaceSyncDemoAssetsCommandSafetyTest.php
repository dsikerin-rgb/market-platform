<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceSyncDemoAssetsCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_dry_run_guards(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MarketplaceSyncDemoAssetsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Sync files into the marketplace media disk (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($dryRun) {', $source);
        self::assertStringContainsString('Storage::disk($targetDisk)->put($directory.\'/\'.$relativePath, $binary, $options);', $source);
        self::assertStringContainsString('if (! $dryRun) {', $source);
        self::assertStringContainsString('MarketplaceMediaStorage::normalizeLocalPublicTreePermissions($directory);', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:sync-demo-assets', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_missing_source_directory_is_non_destructive_in_dry_run(): void
    {
        $this->artisan('marketplace:sync-demo-assets', [
            '--directory' => 'missing-demo-assets-for-dry-run-test',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Source directory not found:')
            ->assertSuccessful();
    }
}
