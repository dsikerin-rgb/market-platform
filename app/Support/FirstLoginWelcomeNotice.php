<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;

final class FirstLoginWelcomeNotice
{
    public const PREFERENCE_KEY = 'first_login_welcome';
    public const VERSION = 'testing-mode-2026-06-15';
    public const NEW_USER_SINCE = '2026-06-15 00:00:00';

    public function shouldShow(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $raw = (array) ($user->notification_preferences ?? []);
        $state = (array) ($raw[self::PREFERENCE_KEY] ?? []);

        if (($state['version'] ?? null) === self::VERSION && filled($state['seen_at'] ?? null)) {
            return false;
        }

        $createdAt = $user->created_at;
        if ($createdAt === null) {
            return true;
        }

        $newUserSince = CarbonImmutable::parse(self::NEW_USER_SINCE, date_default_timezone_get());

        return $createdAt->greaterThanOrEqualTo($newUserSince);
    }

    public function markSeen(User $user): void
    {
        $raw = (array) ($user->notification_preferences ?? []);
        $raw[self::PREFERENCE_KEY] = [
            'version' => self::VERSION,
            'seen_at' => now()->toDateTimeString(),
        ];

        $user->forceFill([
            'notification_preferences' => $raw,
        ])->save();
    }
}
