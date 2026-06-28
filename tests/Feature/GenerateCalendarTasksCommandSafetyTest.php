<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class GenerateCalendarTasksCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_guards_and_scheduled_execute(): void
    {
        $source = file_get_contents(app_path('Console/Commands/GenerateCalendarTasks.php'));
        $schedule = file_get_contents(base_path('routes/console.php'));
        $editPage = file_get_contents(app_path('Filament/Resources/MarketHolidayResource/Pages/EditMarketHoliday.php'));

        self::assertIsString($source);
        self::assertIsString($schedule);
        self::assertIsString($editPage);
        self::assertStringContainsString('{--all-markets : Allow --execute to affect every market}', $source);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Generate or update tasks (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('if ($execute && $marketId === null && ! (bool) $this->option(\'all-markets\')) {', $source);
        self::assertStringContainsString('private function marketIdOption(): int|false|null', $source);
        self::assertStringContainsString('DRY RUN: no tasks were generated or updated. Use --execute --market_id=... or --execute --all-markets to apply.', $source);
        self::assertStringContainsString("Schedule::command('market:calendar:generate-tasks --execute --all-markets')", $schedule);
        self::assertStringContainsString("'--execute' => true,", $editPage);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('market:calendar:generate-tasks', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_requires_market_filter_or_all_markets_flag(): void
    {
        $this->artisan('market:calendar:generate-tasks', [
            '--execute' => true,
        ])->assertFailed();
    }

    public function test_execute_cannot_combine_market_filter_and_all_markets_flag(): void
    {
        $this->artisan('market:calendar:generate-tasks', [
            '--execute' => true,
            '--market_id' => '1',
            '--all-markets' => true,
        ])->assertFailed();
    }

    public function test_market_filter_must_be_positive_integer(): void
    {
        $this->artisan('market:calendar:generate-tasks', [
            '--market_id' => 'not-an-id',
        ])->assertFailed();
    }
}
