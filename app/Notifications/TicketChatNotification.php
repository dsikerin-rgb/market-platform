<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Ticket;
use App\Support\NotificationChannelResolver;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketChatNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENT_REQUEST_CREATED = 'request_created';
    public const EVENT_MESSAGE_CREATED = 'message_created';

    public function __construct(
        private readonly Ticket $ticket,
        private readonly string $eventType,
        private readonly string $title,
        private readonly string $body,
        private readonly ?string $url = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        $topic = $this->eventType === self::EVENT_REQUEST_CREATED
            ? 'requests'
            : 'messages';

        return app(NotificationChannelResolver::class)->resolve(
            $notifiable,
            $topic,
            (int) $this->ticket->market_id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->title)
            ->body($this->body);

        if (filled($this->url)) {
            $notification->actions([
                Action::make('open')
                    ->label('Открыть')
                    ->url((string) $this->url)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'ticket_id' => (int) $this->ticket->id,
            'tenant_id' => (int) ($this->ticket->tenant_id ?? 0),
            'market_id' => (int) ($this->ticket->market_id ?? 0),
            'event_type' => $this->eventType,
            'title' => $this->title,
            'message' => $this->body,
            'url' => $this->url,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage())
            ->subject($this->title)
            ->line($this->body)
            ->line('Заявка #' . (int) $this->ticket->id);

        if (filled($this->url)) {
            $mail->action('Открыть', (string) $this->url);
        }

        return $mail;
    }
}
