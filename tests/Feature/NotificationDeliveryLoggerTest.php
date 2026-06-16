<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Support\NotificationDeliveryLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class NotificationDeliveryLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sent_delivery_logging_is_idempotent_for_same_notification_channel(): void
    {
        $user = $this->makeNotifiableUser();
        $notification = new class extends Notification {};
        $notification->id = '11111111-1111-4111-8111-111111111111';

        $event = new NotificationSent($user, $notification, 'database', ['stored' => true]);
        $logger = app(NotificationDeliveryLogger::class);

        $logger->logSent($event);
        $logger->logSent($event);

        $this->assertSame(1, NotificationDelivery::query()->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => '11111111-1111-4111-8111-111111111111',
            'channel' => 'database',
            'status' => NotificationDelivery::STATUS_SENT,
            'notifiable_id' => $user->getKey(),
        ]);
    }

    public function test_failed_delivery_logging_uses_exception_from_event_data(): void
    {
        $user = $this->makeNotifiableUser();
        $notification = new class extends Notification {};
        $notification->id = '22222222-2222-4222-8222-222222222222';

        app(NotificationDeliveryLogger::class)->logFailed(new NotificationFailed(
            $user,
            $notification,
            'mail',
            ['exception' => new \RuntimeException('SMTP rejected the message')],
        ));

        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => '22222222-2222-4222-8222-222222222222',
            'channel' => 'mail',
            'status' => NotificationDelivery::STATUS_FAILED,
            'notifiable_id' => $user->getKey(),
            'error' => 'SMTP rejected the message',
        ]);
    }

    private function makeNotifiableUser(): User
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        return User::factory()->create([
            'market_id' => $market->getKey(),
        ]);
    }
}
