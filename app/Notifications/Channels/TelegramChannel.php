<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $token = trim((string) config('services.telegram.bot_token', ''));
        if ($token === '') {
            Log::warning('Telegram notification skipped: bot token is not configured.', [
                'notification' => $notification::class,
                'notifiable' => $notifiable::class,
            ]);

            return;
        }

        $chatId = trim((string) ($notifiable->telegram_chat_id ?? ''));
        if ($chatId === '') {
            Log::warning('Telegram notification skipped: telegram_chat_id is missing.', [
                'notification' => $notification::class,
                'notifiable' => $notifiable::class,
            ]);

            return;
        }

        $message = $this->resolveMessage($notification, $notifiable);
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            Log::warning('Telegram notification skipped: message text is empty.', [
                'notification' => $notification::class,
                'notifiable' => $notifiable::class,
            ]);

            return;
        }

        $apiBase = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/');
        $timeout = min(3, max(2, (int) config('services.telegram.timeout', 3)));

        try {
            $response = Http::timeout($timeout)
                ->asForm()
                ->post("{$apiBase}/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful() || (bool) $response->json('ok') !== true) {
                Log::warning('Telegram API returned a non-success response.', [
                    'notification' => $notification::class,
                    'notifiable' => $notifiable::class,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 1000),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('Telegram notification failed; request will continue without it.', [
                'notification' => $notification::class,
                'notifiable' => $notifiable::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
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
