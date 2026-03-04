<?php

declare(strict_types=1);

namespace App\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TelegramConnectLinkNotification extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        private readonly string $recipientLabel,
        private readonly string $issuedBy,
        private readonly string $deepLink,
        private readonly string $command,
        private readonly ?string $expiresAt,
        private readonly ?string $shareLink,
        private readonly array $channels,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = array_values(array_unique(array_filter(
            $this->channels,
            static fn (mixed $channel): bool => is_string($channel) && in_array($channel, ['database', 'mail'], true),
        )));

        if (in_array('mail', $channels, true) && blank($notifiable->email ?? null)) {
            $channels = array_values(array_filter(
                $channels,
                static fn (string $channel): bool => $channel !== 'mail',
            ));
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lines = [
            'Ссылка для подключения Telegram готова.',
            'Команда: ' . $this->command,
        ];

        if ($this->expiresAt !== null && $this->expiresAt !== '') {
            $lines[] = 'Действует до: ' . $this->expiresAt;
        }

        $notification = FilamentNotification::make()
            ->title('Подключение Telegram')
            ->body(implode("\n", $lines))
            ->icon('heroicon-o-link')
            ->success();

        if ($this->deepLink !== '') {
            $notification->actions([
                Action::make('open')
                    ->label('Открыть бота')
                    ->url($this->deepLink)
                    ->markAsRead(),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject('Подключение Telegram в Market Platform')
            ->line('Получатель: ' . $this->recipientLabel)
            ->line('Ссылка выдана: ' . $this->issuedBy)
            ->line('Команда: ' . $this->command);

        if ($this->expiresAt !== null && $this->expiresAt !== '') {
            $message->line('Действует до: ' . $this->expiresAt);
        }

        if ($this->deepLink !== '') {
            $message->action('Открыть Telegram-бота', $this->deepLink);
        }

        if ($this->shareLink !== null && $this->shareLink !== '') {
            $message->line('Быстрая ссылка для отправки в Telegram: ' . $this->shareLink);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'recipient' => $this->recipientLabel,
            'issued_by' => $this->issuedBy,
            'command' => $this->command,
            'deep_link' => $this->deepLink,
            'expires_at' => $this->expiresAt,
            'share_link' => $this->shareLink,
        ];
    }
}
