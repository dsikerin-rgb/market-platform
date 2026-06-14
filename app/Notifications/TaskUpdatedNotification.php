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

class TaskUpdatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    public function __construct(
        private readonly Task $task,
        private readonly array $changes,
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
            ->title('Задача обновлена')
            ->body((string) $this->task->title . ': ' . $this->changesSummary())
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
        $mail = (new MailMessage())
            ->subject('Задача обновлена')
            ->greeting('Здравствуйте!')
            ->line('В задаче изменились данные.')
            ->line('Задача: ' . (string) $this->task->title);

        foreach ($this->changes as $field => $change) {
            $mail->line($this->fieldLabel($field) . ': ' . $this->valueLabel($field, $change['old'] ?? null) . ' → ' . $this->valueLabel($field, $change['new'] ?? null));
        }

        return $mail->action('Открыть задачу', $this->taskUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => (int) $this->task->id,
            'market_id' => (int) $this->task->market_id,
            'changes' => $this->changes,
            'url' => $this->taskUrl(),
        ];
    }

    private function changesSummary(): string
    {
        return implode(', ', array_map(
            fn (string $field): string => $this->fieldLabel($field),
            array_keys($this->changes),
        ));
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'title' => 'Название',
            'description' => 'Описание',
            'priority' => 'Приоритет',
            'due_at' => 'Срок',
            default => $field,
        };
    }

    private function valueLabel(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'не указано';
        }

        if ($field === 'priority') {
            return Task::PRIORITY_LABELS[(string) $value] ?? (string) $value;
        }

        if ($field === 'description') {
            return 'изменено';
        }

        if ($field === 'due_at') {
            try {
                return \Illuminate\Support\Carbon::parse($value)->format('d.m.Y H:i');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return (string) $value;
    }

    private function taskUrl(): string
    {
        return url('/admin/tasks/' . (int) $this->task->id . '/edit');
    }
}
