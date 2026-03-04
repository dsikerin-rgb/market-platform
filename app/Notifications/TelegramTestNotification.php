<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Notification;

class TelegramTestNotification extends Notification
{
    public function __construct(
        private readonly string $initiatorName = 'System',
        private readonly ?string $environmentTag = null,
    ) {
    }

    public function via(object $notifiable): array
    {
        return [TelegramChannel::class];
    }

    /**
     * @return array<string, string>
     */
    public function toTelegram(object $notifiable): array
    {
        $tag = $this->environmentTag;
        if ($tag === null) {
            $tag = trim((string) config('app.env'));
        }

        $prefix = $tag !== '' ? '[' . strtoupper($tag) . '] ' : '';

        return [
            'text' => $prefix . 'Тест Telegram: канал подключен. Отправитель: ' . $this->initiatorName,
        ];
    }
}

