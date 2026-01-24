<?php
# app/Console/Commands/SendTaskReminders.php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskReminderNotification;
use Illuminate\Console\Command;

class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders';

    protected $description = 'Отправка напоминаний по задачам с дедлайном.';

    public function handle(): int
    {
        $now = now();
        $soon = $now->copy()->addDay();

        $dueSoonTasks = Task::query()
            ->open()
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [$now, $soon])
            ->whereNotNull('assignee_id')
            ->get();

        foreach ($dueSoonTasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskReminderNotification($task, TaskReminderNotification::TYPE_DUE_SOON));
            }
        }

        $overdueTasks = Task::query()
            ->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', $now)
            ->whereNotNull('assignee_id')
            ->get();

        foreach ($overdueTasks as $task) {
            if ($task->assignee) {
                $task->assignee->notify(new TaskReminderNotification($task, TaskReminderNotification::TYPE_OVERDUE));
            }
        }

        $this->info(sprintf('Напоминания отправлены: %d (soon), %d (overdue).', $dueSoonTasks->count(), $overdueTasks->count()));

        return Command::SUCCESS;
    }
}
