<?php

namespace App\Http\Controllers;

use App\Support\TelegramChatLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController
{
    public function __invoke(Request $request, TelegramChatLinkService $chatLinkService)
    {
        $expected = (string) config('services.telegram.webhook_secret', '');

        if ($expected !== '' && $request->header('X-Telegram-Bot-Api-Secret-Token') !== $expected) {
            return response()->json(['ok' => false, 'error' => 'forbidden'], 403);
        }

        $update = $request->all();

        $token = (string) config('services.telegram.bot_token', '');
        $message = $update['message'] ?? $update['edited_message'] ?? null;
        $chatId = $message['chat']['id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($token === '' || ! $chatId || $text === '') {
            return response()->json(['ok' => true]);
        }

        [$command, $argument] = $this->parseCommand($text);
        Log::info('telegram.webhook', [
            'update_id' => $update['update_id'] ?? null,
            'chat_id' => $chatId,
            'command' => $command,
            'has_argument' => $argument !== '',
        ]);

        if (! in_array($command, ['/start', '/id'], true)) {
            return response()->json(['ok' => true]);
        }

        if ($command === '/start' && $argument !== '') {
            $linkedUser = $chatLinkService->consumeAndLink($argument, (string) $chatId);
            if ($linkedUser !== null) {
                $this->sendMessage(
                    token: $token,
                    chatId: (string) $chatId,
                    text: "Telegram connected to user:\n{$linkedUser->name}\n\nNotifications can be delivered to this chat."
                );

                return response()->json(['ok' => true]);
            }

            $this->sendMessage(
                token: $token,
                chatId: (string) $chatId,
                text: "Link is invalid or expired.\n\nGenerate a new link in notification settings and try again."
            );

            return response()->json(['ok' => true]);
        }

        $this->sendMessage(
            token: $token,
            chatId: (string) $chatId,
            text: "Your telegram_chat_id:\n{$chatId}\n\nCopy and paste it into user profile in admin panel."
        );

        return response()->json(['ok' => true]);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function parseCommand(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $text, 2) ?: [];
        $rawCommand = strtolower((string) ($parts[0] ?? ''));
        $command = preg_replace('/@[\w_]+$/', '', $rawCommand) ?: '';
        $argument = trim((string) ($parts[1] ?? ''));

        return [$command, $argument];
    }

    private function sendMessage(string $token, string $chatId, string $text): void
    {
        $apiBase = rtrim((string) config('services.telegram.api_base', 'https://api.telegram.org'), '/');
        $timeout = max(2, (int) config('services.telegram.timeout', 10));

        Http::timeout($timeout)->post("{$apiBase}/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
