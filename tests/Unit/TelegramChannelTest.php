<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Notifications\Channels\TelegramChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramChannelTest extends TestCase
{
    public function test_it_retries_with_fallback_connect_to_ips(): void
    {
        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.api_base' => 'https://api.telegram.org',
            'services.telegram.timeout' => 5,
            'services.telegram.connect_to_ips' => ['149.154.167.220'],
        ]);

        Http::fakeSequence()
            ->push(['ok' => false, 'description' => 'timeout'], 500)
            ->push(['ok' => true], 200);

        $notifiable = new class {
            public string $telegram_chat_id = '123456';
        };

        $notification = new class extends Notification {
            public function toTelegram(object $notifiable): array
            {
                return ['text' => 'Telegram transport fallback test'];
            }
        };

        app(TelegramChannel::class)->send($notifiable, $notification);

        Http::assertSentCount(2);
    }
}
