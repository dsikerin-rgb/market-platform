<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class PurgeMarketDocumentTrashCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_dry_run_guards(): void
    {
        $source = file_get_contents(app_path('Console/Commands/PurgeMarketDocumentTrashCommand.php'));
        $schedule = file_get_contents(base_path('routes/console.php'));

        self::assertIsString($source);
        self::assertIsString($schedule);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Delete documents and files (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('DRY RUN: no documents or files were deleted. Use --execute to apply.', $source);
        self::assertStringContainsString('if ($path !== \'\' && $storage->exists($path)) {', $source);
        self::assertStringContainsString('$storage->delete($path);', $source);
        self::assertStringContainsString('$document->delete();', $source);
        self::assertStringContainsString("Schedule::command('market-documents:purge-trash --execute')", $schedule);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('market-documents:purge-trash', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_retention_days_must_be_positive(): void
    {
        $this->artisan('market-documents:purge-trash', [
            '--days' => '0',
        ])->assertFailed();
    }
}
