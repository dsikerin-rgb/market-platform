<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class TelegramChatLinkService
{
    private const CACHE_KEY_PREFIX = 'telegram:link:';
    private const TOKEN_PREFIX = 'mplink_';

    /**
     * @return array{token:string,expires_at:string,command:string,deep_link:?string,bot_username:?string}
     */
    public function issue(User $user, int $ttlMinutes = 20): array
    {
        $ttlMinutes = max(1, $ttlMinutes);
        $token = self::TOKEN_PREFIX . Str::lower(Str::random(32));
        $expiresAt = now()->addMinutes($ttlMinutes);

        Cache::put(
            $this->cacheKey($token),
            ['user_id' => (int) $user->id],
            $expiresAt
        );

        $botUsername = $this->botUsername();
        $deepLink = $botUsername !== null
            ? 'https://t.me/' . $botUsername . '?start=' . $token
            : null;

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toDateTimeString(),
            'command' => '/start ' . $token,
            'deep_link' => $deepLink,
            'bot_username' => $botUsername,
        ];
    }

    public function consumeAndLink(string $token, string $chatId): ?User
    {
        $token = trim($token);
        $chatId = trim($chatId);

        if ($token === '' || $chatId === '') {
            return null;
        }

        $payload = Cache::pull($this->cacheKey($token));
        if (! is_array($payload)) {
            return null;
        }

        $userId = $payload['user_id'] ?? null;
        if (! is_numeric($userId)) {
            return null;
        }

        $user = User::query()->find((int) $userId);
        if (! $user instanceof User) {
            return null;
        }

        $user->forceFill([
            'telegram_chat_id' => $chatId,
        ])->save();

        return $user;
    }

    public function botUsername(): ?string
    {
        $value = trim((string) config('services.telegram.bot_username', ''));

        return $value !== '' ? ltrim($value, '@') : null;
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_KEY_PREFIX . $token;
    }
}

