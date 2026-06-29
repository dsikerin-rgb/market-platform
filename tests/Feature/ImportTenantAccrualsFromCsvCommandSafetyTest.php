<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ImportTenantAccrualsFromCsvCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_guard(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ImportTenantAccrualsFromCsv.php'));

        self::assertIsString($source);
        self::assertStringContainsString('{--dry-run : Parse only (transaction rollback), do not write into DB}', $source);
        self::assertStringContainsString('{--execute : Import rows into DB (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('DRY RUN: transaction will be rolled back (no data will be written).', $source);
        self::assertStringContainsString('DB::rollBack();', $source);
        self::assertStringContainsString('DB::transaction(function () use ($runner) {', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('market:import-tenant-accruals', [
            'file' => 'missing.csv',
            '--dry-run' => true,
            '--execute' => true,
        ])
            ->expectsOutput('Use either --execute or --dry-run, not both.')
            ->assertExitCode(1);
    }
}
