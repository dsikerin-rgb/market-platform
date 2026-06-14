<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\StaffInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly StaffInvitation $invitation,
        private readonly string $acceptUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $marketName = trim((string) ($this->invitation->market?->name ?? 'Market Platform'));
        $expiresAt = $this->invitation->expires_at?->timezone(config('app.timezone'))->format('d.m.Y H:i');

        $message = (new MailMessage())
            ->subject('Приглашение в Market Platform')
            ->greeting('Здравствуйте!')
            ->line('Вас пригласили в Market Platform.')
            ->line('Рынок: ' . $marketName)
            ->action('Принять приглашение', $this->acceptUrl)
            ->line('Если вы не ожидали это письмо, просто проигнорируйте его.');

        if ($expiresAt !== null) {
            $message->line('Ссылка действует до: ' . $expiresAt);
        }

        return $message;
    }
}
