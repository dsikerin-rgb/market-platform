<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceBootstrapCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_guard(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MarketplaceBootstrapCommand.php'));
        $setupView = file_get_contents(resource_path('views/marketplace/setup-required.blade.php'));

        self::assertIsString($source);
        self::assertIsString($setupView);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Apply marketplace bootstrap changes (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('DRY RUN: no marketplace data was written. Use --execute to apply.', $source);
        self::assertStringContainsString('Role::findOrCreate(\'buyer\', \'web\');', $source);
        self::assertStringContainsString('$this->ensureGlobalCategories();', $source);
        self::assertStringContainsString('MarketplaceProduct::query()->create([', $source);
        self::assertStringContainsString('php artisan marketplace:bootstrap --execute --refresh-announcements --seed-products=10 --force', $setupView);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:bootstrap', [
            '--execute' => true,
            '--dry-run' => true,
        ])
            ->expectsOutput('Use either --execute or --dry-run, not both.')
            ->assertExitCode(1);
    }
}
