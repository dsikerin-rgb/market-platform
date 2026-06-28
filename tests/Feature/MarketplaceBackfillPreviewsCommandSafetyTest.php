<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketplaceBackfillPreviewsCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_dry_run_guards(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MarketplaceBackfillPreviewsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Generate preview files (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('$this->backfillPaths((array) ($record->images ?? []), $seen, $checked, $generated, $force, $dryRun);', $source);
        self::assertStringContainsString('if ($dryRun) {', $source);
        self::assertStringContainsString('continue;', $source);
        self::assertStringContainsString('MarketplaceMediaStorage::ensurePreview($value, $force)', $source);
        self::assertStringContainsString('DRY RUN: no preview files were generated. Use --execute to apply.', $source);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('marketplace:backfill-previews', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_unknown_model_key_fails_before_processing(): void
    {
        $this->artisan('marketplace:backfill-previews', [
            '--model' => ['unknown-model'],
            '--dry-run' => true,
        ])
            ->expectsOutput('Unknown model keys: unknown-model')
            ->assertFailed();
    }
}
