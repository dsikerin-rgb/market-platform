<?php
# app/Notifications/TaskReminderNotification.php

namespace App\Notifications;

use App\Models\Task;
use App\Support\NotificationChannelResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const TYPE_DUE_SOON = 'due_soon';
    public const TYPE_OVERDUE = 'overdue';

    public function __construct(
        private readonly Task $task,
        private readonly string $type
    ) {
    }

    public function via(object $notifiable): array
    {
        return app(NotificationChannelResolver::class)->resolve(
            $notifiable,
            'reminders',
            (int) $this->task->market_id,
        );
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

    public function toMail(object $notifiable): MailMessage
    {
        $taskId = (int) $this->task->id;
        $url = url("/admin/tasks/{$taskId}/edit");
        $dueAt = $this->task->due_at?->format('d.m.Y H:i') ?? 'не указан';

        $subject = $this->type === self::TYPE_OVERDUE
            ? 'Просроченная задача'
            : 'Напоминание по задаче';

        return (new MailMessage())
            ->subject($subject)
            ->line('Задача: ' . (string) $this->task->title)
            ->line('Срок: ' . $dueAt)
            ->action('Открыть задачу', $url);
    }
}
