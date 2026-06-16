<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Support\FirstLoginWelcomeNotice;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class FirstLoginWelcomeNoticeTest extends TestCase
{
    public function test_it_shows_for_authenticated_user_without_seen_session_state(): void
    {
        $user = $this->makeUser([], '2026-06-15 12:00:00');

        self::assertTrue((new FirstLoginWelcomeNotice())->shouldShow($user));
    }

    public function test_it_does_not_show_after_user_has_seen_it_in_current_session(): void
    {
        $user = $this->makeUser([
            FirstLoginWelcomeNotice::PREFERENCE_KEY => [
                'version' => FirstLoginWelcomeNotice::VERSION,
                'seen_at' => '2026-06-15 12:01:00',
            ],
        ], '2026-06-15 12:00:00');

        self::assertFalse((new FirstLoginWelcomeNotice())->shouldShow($user, true));
    }

    public function test_it_shows_for_existing_users_created_before_rollout(): void
    {
        $user = $this->makeUser([], '2026-06-14 23:59:59');

        self::assertTrue((new FirstLoginWelcomeNotice())->shouldShow($user));
    }

    /**
     * @param  array<string, mixed>  $notificationPreferences
     */
    private function makeUser(array $notificationPreferences, string $createdAt): User
    {
        $user = new User();
        $user->setRawAttributes([
            'notification_preferences' => json_encode($notificationPreferences, JSON_THROW_ON_ERROR),
            'created_at' => Carbon::parse($createdAt),
        ], true);

        return $user;
    }
}
