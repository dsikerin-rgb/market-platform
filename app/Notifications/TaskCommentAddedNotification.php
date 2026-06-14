<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Task;
use App\Models\TaskComment;
use App\Support\NotificationChannelDefaults;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaskCommentAddedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Task $task,
        private readonly TaskComment $comment,
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
            ->title('Новый комментарий к задаче')
            ->body($this->taskTitleWithPreview())
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
        $author = trim((string) ($this->comment->author?->name ?? ''));
        $author = $author !== '' ? $author : 'Сотрудник';

        return (new MailMessage())
            ->subject('Новый комментарий к задаче')
            ->greeting('Здравствуйте!')
            ->line('В задаче появился новый комментарий.')
            ->line('Задача: ' . (string) $this->task->title)
            ->line('Автор: ' . $author)
            ->line('Комментарий: ' . $this->commentPreview())
            ->action('Открыть задачу', $this->taskUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => (int) $this->task->id,
            'comment_id' => (int) $this->comment->id,
            'market_id' => (int) $this->task->market_id,
            'url' => $this->taskUrl(),
        ];
    }

    private function taskTitleWithPreview(): string
    {
        return (string) $this->task->title . ': ' . $this->commentPreview();
    }

    private function commentPreview(): string
    {
        $preview = trim(preg_replace('/\s+/u', ' ', (string) $this->comment->body) ?? '');

        if (mb_strlen($preview) > 240) {
            return mb_substr($preview, 0, 237) . '...';
        }

        return $preview !== '' ? $preview : 'Комментарий без текста';
    }

    private function taskUrl(): string
    {
        return url('/admin/tasks/' . (int) $this->task->id . '/edit');
    }
}
