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

class TaskParticipantAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly string $role,
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
            ->title('Вас добавили в задачу')
            ->body($this->roleLabel() . ': ' . (string) $this->task->title)
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
            ->subject('Вас добавили в задачу')
            ->greeting('Здравствуйте!')
            ->line('Вас добавили в задачу в Market Platform.')
            ->line('Роль: ' . $this->roleLabel())
            ->line('Задача: ' . (string) $this->task->title)
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
            'role' => $this->role,
            'url' => $this->taskUrl(),
        ];
    }

    private function roleLabel(): string
    {
        return Task::PARTICIPANT_ROLE_LABELS[$this->role] ?? $this->role;
    }

    private function taskUrl(): string
    {
        return url('/admin/tasks/' . (int) $this->task->id . '/edit');
    }
}
