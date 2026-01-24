<?php
# app/Notifications/TaskReminderNotification.php

namespace App\Notifications;

use App\Models\Task;
use Illuminate\Notifications\Notification;

class TaskReminderNotification extends Notification
{
    public const TYPE_DUE_SOON = 'due_soon';
    public const TYPE_OVERDUE = 'overdue';

    public function __construct(
        private readonly Task $task,
        private readonly string $type
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->id,
            'title' => $this->task->title,
            'market_id' => $this->task->market_id,
            'assignee_id' => $this->task->assignee_id,
            'status' => $this->task->status,
            'priority' => $this->task->priority,
            'due_at' => $this->task->due_at?->toDateTimeString(),
            'type' => $this->type,
        ];
    }
}
