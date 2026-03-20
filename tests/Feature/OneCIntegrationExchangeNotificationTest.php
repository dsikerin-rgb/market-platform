<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\OneCIntegrationExchangeNotification;
use App\Support\UserNotificationPreferences;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OneCIntegrationExchangeNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_receives_notification_when_one_c_exchange_finishes(): void
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

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-1c@example.test',
            'telegram_chat_id' => '123456',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database', 'telegram'],
                'topics' => UserNotificationPreferences::TOPICS,
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        $exchange = IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contract_debts',
            'status' => IntegrationExchange::STATUS_IN_PROGRESS,
            'payload' => [
                'endpoint' => '/api/1c/contract-debts',
            ],
            'started_at' => now()->subSeconds(2),
        ]);

        Notification::assertNothingSent();

        $exchange->forceFill([
            'status' => IntegrationExchange::STATUS_OK,
            'finished_at' => now(),
            'payload' => [
                'endpoint' => '/api/1c/contract-debts',
                'received' => 39,
                'inserted' => 39,
                'skipped' => 0,
            ],
        ])->save();

        Notification::assertSentTo(
            $superAdmin,
            OneCIntegrationExchangeNotification::class,
            function (OneCIntegrationExchangeNotification $notification) use ($superAdmin): bool {
                $payload = $notification->toArray($superAdmin);

                return in_array(TelegramChannel::class, $notification->via($superAdmin), true)
                    && ($payload['entity_type'] ?? null) === 'contract_debts'
                    && ($payload['status'] ?? null) === IntegrationExchange::STATUS_OK
                    && ((int) (($payload['counters']['received'] ?? null) ?? 0)) === 39
                    && ((int) (($payload['counters']['inserted'] ?? null) ?? 0)) === 39;
            }
        );
    }

    public function test_one_c_notification_uses_market_timezone_for_finished_at(): void
    {
        config([
            'app.timezone' => 'Asia/Omsk',
            'services.telegram.enabled' => true,
            'services.telegram.bot_token' => 'test-token',
        ]);

        $market = new Market([
            'id' => 1,
            'name' => 'Тестовый рынок',
            'timezone' => 'Asia/Barnaul',
            'is_active' => true,
        ]);

        $exchange = new IntegrationExchange();
        $exchange->setRawAttributes([
            'market_id' => 1,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contract_debts',
            'status' => IntegrationExchange::STATUS_OK,
            'payload' => json_encode([
                'endpoint' => '/api/1c/contract-debts',
                'received' => 39,
                'inserted' => 0,
                'skipped' => 39,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'finished_at' => '2026-03-07 09:54:21',
        ], true);
        $exchange->setRelation('market', $market);

        $notification = new OneCIntegrationExchangeNotification($exchange);
        $reflection = new \ReflectionClass($notification);
        $method = $reflection->getMethod('formatDateTimeForMarket');
        $method->setAccessible(true);

        $this->assertSame(
            '2026-03-07 10:54:21',
            $method->invoke($notification, CarbonImmutable::create(2026, 3, 7, 9, 54, 21, 'Asia/Omsk')),
        );
    }

    public function test_super_admin_can_disable_one_c_notifications_via_topics(): void
    {
        Notification::fake();

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-1c-off@example.test',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database'],
                'topics' => ['tasks'],
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        $exchange = IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'contracts',
            'status' => IntegrationExchange::STATUS_IN_PROGRESS,
            'payload' => [
                'endpoint' => '/api/1c/contracts',
            ],
            'started_at' => now()->subSecond(),
        ]);

        $exchange->forceFill([
            'status' => IntegrationExchange::STATUS_OK,
            'finished_at' => now(),
            'payload' => [
                'endpoint' => '/api/1c/contracts',
                'received' => 5,
                'created' => 2,
                'updated' => 3,
                'skipped' => 0,
            ],
        ])->save();

        Notification::assertNotSentTo($superAdmin, OneCIntegrationExchangeNotification::class);
    }

    public function test_non_one_c_exchange_does_not_trigger_notification(): void
    {
        Notification::fake();

        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'super-admin-non-1c@example.test',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['database'],
                'topics' => UserNotificationPreferences::TOPICS,
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        $exchange = IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'direction' => IntegrationExchange::DIRECTION_OUT,
            'entity_type' => 'market_space',
            'status' => IntegrationExchange::STATUS_IN_PROGRESS,
            'payload' => [
                'endpoint' => '/api/internal/snapshot-rebuild',
            ],
            'started_at' => now()->subSecond(),
        ]);

        $exchange->forceFill([
            'status' => IntegrationExchange::STATUS_OK,
            'finished_at' => now(),
        ])->save();

        Notification::assertNotSentTo($superAdmin, OneCIntegrationExchangeNotification::class);
    }

    public function test_notification_channel_failure_does_not_break_exchange_update(): void
    {
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

        $superAdmin = User::factory()->create([
            'market_id' => (int) $market->id,
            'telegram_chat_id' => '123456',
            'notification_preferences' => [
                'self_manage' => true,
                'channels' => ['telegram'],
                'topics' => UserNotificationPreferences::TOPICS,
            ],
        ]);
        $superAdmin->assignRole('super-admin');

        app()->bind(TelegramChannel::class, function (): object {
            return new class {
                public function send(object $notifiable, object $notification): void
                {
                    throw new \RuntimeException('simulated telegram timeout');
                }
            };
        });

        $exchange = IntegrationExchange::query()->create([
            'market_id' => (int) $market->id,
            'direction' => IntegrationExchange::DIRECTION_IN,
            'entity_type' => 'accruals',
            'status' => IntegrationExchange::STATUS_IN_PROGRESS,
            'payload' => [
                'endpoint' => '/api/1c/accruals',
            ],
            'started_at' => now()->subSecond(),
        ]);

        $exchange->forceFill([
            'status' => IntegrationExchange::STATUS_OK,
            'finished_at' => now(),
            'payload' => [
                'endpoint' => '/api/1c/accruals',
                'received' => 10,
                'inserted' => 10,
                'skipped' => 0,
            ],
        ])->save();

        $exchange->refresh();
        $this->assertSame(IntegrationExchange::STATUS_OK, $exchange->status);
        $this->assertNotNull($exchange->finished_at);
    }
}
