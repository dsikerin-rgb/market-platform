<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class SendTaskRemindersCommandSafetyTest extends TestCase
{
    public function test_command_source_uses_explicit_execute_and_scheduled_execute(): void
    {
        $source = file_get_contents(app_path('Console/Commands/SendTaskReminders.php'));
        $schedule = file_get_contents(base_path('routes/console.php'));

        self::assertIsString($source);
        self::assertIsString($schedule);
        self::assertStringContainsString('{--dry-run : Run in dry-run mode}', $source);
        self::assertStringContainsString('{--execute : Send task reminders (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('DRY RUN: no task reminders were sent. Use --execute to apply.', $source);
        self::assertStringContainsString('if ($dryRun) {', $source);
        self::assertStringContainsString('$task->assignee->notify(new TaskReminderNotification($task, TaskReminderNotification::TYPE_DUE_SOON));', $source);
        self::assertStringContainsString('$task->assignee->notify(new TaskReminderNotification($task, TaskReminderNotification::TYPE_OVERDUE));', $source);
        self::assertStringContainsString("Schedule::command('tasks:send-reminders --execute')", $schedule);
    }

    public function test_execute_and_dry_run_options_cannot_be_combined(): void
    {
        $this->artisan('tasks:send-reminders', [
            '--dry-run' => true,
            '--execute' => true,
        ])->assertFailed();
    }
}
