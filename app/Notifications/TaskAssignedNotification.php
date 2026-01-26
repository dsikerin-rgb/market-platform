<?php

# app/Notifications/TaskAssignedNotification.php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Task;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Notifications\Notification as LaravelNotification;

class TaskAssignedNotification extends LaravelNotification
{
    public function __construct(private readonly Task $task)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Filament: чтобы уведомления отображались в колокольчике панели,
     * сохраняем payload в формате Filament database message.
     *
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $taskId = $this->task->getKey();

        // Панель у нас на /admin (см. AdminPanelProvider->path('admin'))
        $url = url("/admin/tasks/{$taskId}/edit");

        return Notification::make()
            ->title('Назначена задача')
            ->body((string) $this->task->title)
            ->icon('heroicon-o-clipboard-document-check')
            ->actions([
                Action::make('view')
                    ->label('Открыть')
                    ->url($url)
                    ->button()
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    /**
     * Fallback (не используется Filament-колокольчиком, но полезно для отладки).
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->task->getKey(),
        ];
    }
}
