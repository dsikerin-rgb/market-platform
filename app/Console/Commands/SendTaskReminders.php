<?php

// app/Console/Commands/SendTaskReminders.php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskReminderNotification;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders
        {--dry-run : Run in dry-run mode}
        {--execute : Send task reminders (default: dry-run)}';

    protected $description = 'Отправка напоминаний по задачам с дедлайном.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return Command::FAILURE;
        }

        $dryRun = ! $execute || (bool) $this->option('dry-run');
        $now = now();
        $soon = $now->copy()->addDay();

        $dueSoonTasks = Task::query()
            ->open()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$now, $soon])
            ->whereNotNull('assignee_id')
            ->get();

        $overdueTasks = Task::query()
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->whereNotNull('assignee_id')
            ->get();

        if ($dryRun) {
            $this->info(sprintf('Would send reminders: %d (soon), %d (overdue).', $dueSoonTasks->count(), $overdueTasks->count()));
            $this->info('DRY RUN: no task reminders were sent. Use --execute to apply.');

            return Command::SUCCESS;
        }

        foreach ($dueSoonTasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskReminderNotification($task, TaskReminderNotification::TYPE_DUE_SOON));
            }
        }

        foreach ($overdueTasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskReminderNotification($task, TaskReminderNotification::TYPE_OVERDUE));
            }
        }

        $this->info(sprintf('Напоминания отправлены: %d (soon), %d (overdue).', $dueSoonTasks->count(), $overdueTasks->count()));

        return Command::SUCCESS;
    }
}
