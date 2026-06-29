<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MergeMarketSpacesCommandSafetyTest extends TestCase
{
    public function test_space_merge_is_dry_run_by_default(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MergeMarketSpacesCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('{--execute : Apply changes (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('DRY RUN: nothing changed', $source);
    }

    public function test_space_merge_rejects_execute_and_dry_run_together(): void
    {
        $this->artisan('market:spaces-merge', [
            'from' => 1,
            'to' => 2,
            '--execute' => true,
            '--dry-run' => true,
        ])
            ->expectsOutput('Use either --execute or --dry-run, not both.')
            ->assertExitCode(1);
    }
}
