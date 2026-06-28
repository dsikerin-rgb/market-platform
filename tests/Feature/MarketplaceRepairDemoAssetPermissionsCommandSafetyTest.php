<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceRepairDemoAssetPermissionsCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_dry_run_guards(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MarketplaceRepairDemoAssetPermissionsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Normalize local public storage permissions (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('$normalized = $this->countLocalPublicTreePaths($directory);', $source);
        self::assertStringContainsString('MarketplaceMediaStorage::normalizeLocalPublicTreePermissions($directory);', $source);
        self::assertStringContainsString('DRY RUN: no permissions were changed. Use --execute to apply.', $source);
    }

    public function test_scheduled_repair_keeps_explicit_execute(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        self::assertIsString($source);
        self::assertStringContainsString("Schedule::command('marketplace:repair-demo-asset-permissions --execute')", $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:repair-demo-asset-permissions', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_missing_directory_is_non_destructive_in_dry_run(): void
    {
        $this->artisan('marketplace:repair-demo-asset-permissions', [
            '--directory' => 'missing-demo-asset-permissions-dry-run-test',
            '--dry-run' => true,
        ])
            ->expectsOutput('Would normalize 0 paths under missing-demo-asset-permissions-dry-run-test.')
            ->expectsOutput('DRY RUN: no permissions were changed. Use --execute to apply.')
            ->assertSuccessful();
    }
}
