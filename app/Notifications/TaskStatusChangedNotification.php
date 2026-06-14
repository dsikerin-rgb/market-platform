<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Task;
use App\Support\NotificationChannelDefaults;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly string $oldStatus,
        private readonly string $newStatus,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return app(NotificationChannelDefaults::class)->resolveWithMailDefault(
            $notifiable,
            'tasks',
            (int) $this->task->market_id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Статус задачи изменён')
            ->body($this->taskTitleWithStatus())
            ->actions([
                Action::make('open')
                    ->label('Открыть')
                    ->url($this->taskUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Статус задачи изменён')
            ->greeting('Здравствуйте!')
            ->line('Изменён статус задачи в Market Platform.')
            ->line('Задача: ' . (string) $this->task->title)
            ->line('Было: ' . $this->statusLabel($this->oldStatus))
            ->line('Стало: ' . $this->statusLabel($this->newStatus))
            ->action('Открыть задачу', $this->taskUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => (int) $this->task->id,
            'market_id' => (int) $this->task->market_id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'url' => $this->taskUrl(),
        ];
    }

    private function taskTitleWithStatus(): string
    {
        return (string) $this->task->title . ': ' . $this->statusLabel($this->newStatus);
    }

    private function statusLabel(string $status): string
    {
        return Task::STATUS_LABELS[$status] ?? $status;
    }

    private function taskUrl(): string
    {
        return url('/admin/tasks/' . (int) $this->task->id . '/edit');
    }
}
