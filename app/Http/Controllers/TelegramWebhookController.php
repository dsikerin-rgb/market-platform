<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController
{
    public function __invoke(Request $request)
    {
        $expected = (string) env('TELEGRAM_WEBHOOK_SECRET', '');

        if ($expected !== '' && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expected) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $update = $request->all();
        Log::info('telegram.webhook', $update);

        $token = (string) env('TELEGRAM_BOT_TOKEN', '');

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $chatId  = $message['chat']['id'] ?? null;
        $text    = trim((string) ($message['text'] ?? ''));

        if ($token !== '' && $chatId && in_array($text, ['/start', '/id'], true)) {
            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "Ваш telegram_chat_id:\n{$chatId}\n\nСкопируйте и вставьте в профиль пользователя в админке.",
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
