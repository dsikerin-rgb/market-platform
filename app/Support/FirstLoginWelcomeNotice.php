<?php
# app/Support/FirstLoginWelcomeNotice.php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class FirstLoginWelcomeNotice
{
    public const PREFERENCE_KEY = 'first_login_welcome';
    public const VERSION = 'testing-mode-2026-06-15';
    public const SESSION_KEY = 'testing_mode_welcome_notice_seen';

    public function __construct(
        private readonly TestingModeNoticeSettings $settings = new TestingModeNoticeSettings(),
    ) {}

    public function shouldShow(?User $user, bool $seenInCurrentSession = false): bool
    {
        if (! $user) {
            return false;
        }

        if ($seenInCurrentSession) {
            return false;
        }

        if ($this->isAcknowledged($user)) {
            return false;
        }

        return $this->settings->enabledForUser($user);
    }

    public function isAcknowledged(User $user): bool
    {
        $preferences = (array) ($user->notification_preferences ?? []);
        $notice = $preferences[self::PREFERENCE_KEY] ?? null;

        if (is_string($notice)) {
            return $notice === self::VERSION;
        }

        if (! is_array($notice)) {
            return false;
        }

        return (string) ($notice['version'] ?? '') === self::VERSION;
    }
}
