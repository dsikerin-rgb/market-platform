<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StaffConversation;
use App\Models\User;
use App\Support\NotificationChannelDefaults;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly StaffConversation $conversation,
        private readonly User $author,
        private readonly string $title,
        private readonly string $body,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsForStaffEmailDefault($notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title)
            ->body($this->body)
            ->actions([
                Action::make('open')
                    ->label('Открыть')
                    ->url($this->conversationUrl())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $preview = trim(preg_replace('/\s+/u', ' ', $this->body) ?? '');
        if (mb_strlen($preview) > 240) {
            $preview = mb_substr($preview, 0, 237) . '...';
        }

        return (new MailMessage())
            ->subject($this->title)
            ->greeting('Здравствуйте!')
            ->line('Вам отправили внутреннее сообщение в Market Platform.')
            ->line('Отправитель: ' . ((string) $this->author->name ?: (string) $this->author->email))
            ->line('Тема: ' . $this->conversationSubject())
            ->line('Сообщение: ' . $preview)
            ->action('Открыть сообщение', $this->conversationUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'staff_conversation_id' => (int) $this->conversation->id,
            'author_user_id' => (int) $this->author->id,
            'market_id' => (int) ($this->conversation->market_id ?? 0),
            'title' => $this->title,
            'message' => $this->body,
            'url' => $this->conversationUrl(),
        ];
    }

    private function conversationUrl(): string
    {
        return url('/admin/requests?' . http_build_query([
            'channel' => 'staff',
            'conversation_id' => (int) $this->conversation->id,
        ]));
    }

    private function conversationSubject(): string
    {
        $subject = trim((string) $this->conversation->subject);

        return $subject !== '' ? $subject : 'Внутренний диалог';
    }

    /**
     * @return list<string>
     */
    private function channelsForStaffEmailDefault(object $notifiable): array
    {
        return app(NotificationChannelDefaults::class)->resolveWithMailDefault(
            $notifiable,
            'messages',
            (int) ($this->conversation->market_id ?? 0),
        );
    }
}
