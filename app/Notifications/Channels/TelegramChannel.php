<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $token = trim((string) config('services.telegram.bot_token', ''));
        if ($token === '') {
            throw new \RuntimeException('Telegram bot token is not configured.');
        }

        $chatId = trim((string) ($notifiable->telegram_chat_id ?? ''));
        if ($chatId === '') {
            throw new \RuntimeException('telegram_chat_id is missing for notifiable user.');
        }

        $message = $this->resolveMessage($notification, $notifiable);
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Telegram message is empty.');
        }

        $apiBase = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/');
        $timeout = max(2, (int) config('services.telegram.timeout', 10));

        $response = Http::timeout($timeout)
            ->asForm()
            ->post("{$apiBase}/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'disable_web_page_preview' => true,
            ]);

        if (! $response->successful() || (bool) $response->json('ok') !== true) {
            $body = mb_substr((string) $response->body(), 0, 1000);
            throw new \RuntimeException('Telegram API error: ' . $body);
        }
    }

    /**
     * @return array{text:string}
     */
    private function resolveMessage(Notification $notification, object $notifiable): array
    {
        $text = '';

        if (method_exists($notification, 'toTelegram')) {
            $payload = $notification->toTelegram($notifiable);

            if (is_string($payload)) {
                $text = $payload;
            } elseif (is_array($payload)) {
                $base = (string) ($payload['text'] ?? $payload['message'] ?? '');
                $url = trim((string) ($payload['url'] ?? ''));
                $text = $base;

                if ($url !== '') {
                    $text = trim($base) . PHP_EOL . $url;
                }
            }
        }

        if ($text === '' && method_exists($notification, 'toArray')) {
            $arr = $notification->toArray($notifiable);
            if (is_array($arr)) {
                $base = (string) ($arr['message'] ?? $arr['title'] ?? '');
                $url = trim((string) ($arr['url'] ?? ''));
                $text = $base;

                if ($url !== '') {
                    $text = trim($base) . PHP_EOL . $url;
                }
            }
        }

        $text = trim($text);
        if ($text === '') {
            $text = 'Новое уведомление';
        }

        return ['text' => $text];
    }
}
