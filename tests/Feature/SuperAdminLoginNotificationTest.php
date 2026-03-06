<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\UserLoggedInNotification;
use App\Support\UserNotificationPreferences;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SuperAdminLoginNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_receives_notification_when_any_user_logs_in(): void
    {
        Notification::fake();
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-token',
        ]);

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('market-admin', 'web');

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin@example.test',
            'telegram_chat_id' => '123456',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database', 'telegram'],
                'topics' => UserNotificationPreferences::TOPICS,
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        $actor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'staff@example.test',
        ]);
        $actor->assignRole('market-admin');

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'PHPUnit Login Test',
        ]);
        $this->app->instance('request', Request::create('/admin/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'PHPUnit Login Test',
        ]));

        Event::dispatch(new Login('web', $actor, false));

        Notification::assertSentTo(
            $superAdmin,
            UserLoggedInNotification::class,
            function (UserLoggedInNotification $notification) use ($superAdmin, $actor): bool {
                $payload = $notification->toArray($superAdmin);

                return in_array(TelegramChannel::class, $notification->via($superAdmin), true)
                    && ($payload['actor_name'] ?? null) === (string) $actor->name
                    && ($payload['actor_email'] ?? null) === (string) $actor->email
                    && in_array('market-admin', (array) ($payload['actor_roles'] ?? []), true)
                    && ! empty($payload['logged_in_at']);
            }
        );
        Notification::assertNotSentTo($actor, UserLoggedInNotification::class);
    }

    public function test_super_admin_receives_notification_for_filament_livewire_login_request(): void
    {
        Notification::fake();
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-token',
        ]);

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('market-admin', 'web');

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-livewire@example.test',
            'telegram_chat_id' => '123456',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database', 'telegram'],
                'topics' => UserNotificationPreferences::TOPICS,
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        $actor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'staff-livewire@example.test',
        ]);
        $actor->assignRole('market-admin');

        $this->app->instance('request', Request::create('/livewire/update', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.14',
            'HTTP_USER_AGENT' => 'PHPUnit Livewire Login Test',
            'HTTP_REFERER' => 'https://market.example.test/admin/login',
        ]));

        Event::dispatch(new Login('web', $actor, false));

        Notification::assertSentTo($superAdmin, UserLoggedInNotification::class);
    }

    public function test_regular_user_can_receive_notification_about_login_to_own_account_when_security_enabled(): void
    {
        Notification::fake();
        $this->app->instance('request', Request::create('/admin/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.11',
            'HTTP_USER_AGENT' => 'PHPUnit Own Login Test',
        ]));

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $actor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'self@example.test',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database'],
                'topics' => [UserNotificationPreferences::TOPIC_SECURITY],
            ],
        ]);
        $actor->assignRole('market-admin');

        Event::dispatch(new Login('web', $actor, false));

        Notification::assertSentTo($actor, UserLoggedInNotification::class);
    }

    public function test_regular_user_does_not_receive_login_notification_by_default(): void
    {
        Notification::fake();
        $this->app->instance('request', Request::create('/admin/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.12',
            'HTTP_USER_AGENT' => 'PHPUnit Default Off Test',
        ]));

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('market-admin', 'web');

        $actor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'default-off@example.test',
        ]);
        $actor->assignRole('market-admin');

        Event::dispatch(new Login('web', $actor, false));

        Notification::assertNotSentTo($actor, UserLoggedInNotification::class);
    }

    public function test_super_admin_can_disable_login_notifications_via_topics(): void
    {
        Notification::fake();
        config([
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-token',
        ]);
        $this->app->instance('request', Request::create('/admin/login', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.13',
            'HTTP_USER_AGENT' => 'PHPUnit Super Admin Off Test',
        ]));

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('market-admin', 'web');

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-off@example.test',
            'telegram_chat_id' => '123456',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database', 'telegram'],
                'topics' => ['tasks'],
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        $actor = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'staff-off@example.test',
        ]);
        $actor->assignRole('market-admin');

        Event::dispatch(new Login('web', $actor, false));

        Notification::assertNotSentTo($superAdmin, UserLoggedInNotification::class);
    }
}
