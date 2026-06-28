<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class NotifyMarketHolidaysCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_scheduled_execute(): void
    {
        $source = file_get_contents(app_path('Console/Commands/NotifyMarketHolidays.php'));
        $schedule = file_get_contents(base_path('routes/console.php'));

        self::assertIsString($source);
        self::assertIsString($schedule);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Send notifications and mark holidays as notified (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('DRY RUN: no notifications were sent and no holidays were marked notified. Use --execute to apply.', $source);
        self::assertStringContainsString('if ($dryRun) {', $source);
        self::assertStringContainsString('$recipient->notify(new MarketHolidayNotification($holiday, $market));', $source);
        self::assertStringContainsString('$holiday->forceFill([\'notified_at\' => $now])->save();', $source);
        self::assertStringContainsString("Schedule::command('market:holidays:notify --execute')", $schedule);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('market:holidays:notify', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }
}
