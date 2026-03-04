<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\NotificationDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Schema;

class NotificationDeliveryLogger
{
    private static ?bool $tableExists = null;

    public function logSent(NotificationSent $event): void
    {
        $this->safeLog(function () use ($event): void {
            NotificationDelivery::query()->create([
                'notification_id' => $this->extractNotificationId($event->notification),
                'notification_type' => $event->notification::class,
                'channel' => (string) $event->channel,
                'status' => NotificationDelivery::STATUS_SENT,
                'notifiable_type' => $event->notifiable::class,
                'notifiable_id' => $this->extractNumericId($event->notifiable),
                'market_id' => $this->extractMarketId($event->notifiable),
                'queued' => $event->notification instanceof ShouldQueue,
                'payload' => $this->normalizeValue($event->response),
                'error' => null,
                'sent_at' => now(),
            ]);
        });
    }

    public function logFailed(NotificationFailed $event): void
    {
        $this->safeLog(function () use ($event): void {
            NotificationDelivery::query()->create([
                'notification_id' => $this->extractNotificationId($event->notification),
                'notification_type' => $event->notification::class,
                'channel' => (string) $event->channel,
                'status' => NotificationDelivery::STATUS_FAILED,
                'notifiable_type' => $event->notifiable::class,
                'notifiable_id' => $this->extractNumericId($event->notifiable),
                'market_id' => $this->extractMarketId($event->notifiable),
                'queued' => $event->notification instanceof ShouldQueue,
                'payload' => $this->normalizeValue($event->data),
                'error' => $this->truncate((string) ($event->exception?->getMessage() ?? 'Notification failed')),
                'sent_at' => now(),
            ]);
        });
    }

    private function safeLog(\Closure $callback): void
    {
        if (! $this->hasDeliveryTable()) {
            return;
        }

        try {
            $callback();
        } catch (\Throwable) {
            // Logging must not break main notification flow.
        }
    }

    private function hasDeliveryTable(): bool
    {
        if (self::$tableExists !== null) {
            return self::$tableExists;
        }

        try {
            self::$tableExists = Schema::hasTable('notification_deliveries');
        } catch (\Throwable) {
            self::$tableExists = false;
        }

        return self::$tableExists;
    }

    private function extractNotificationId(object $notification): ?string
    {
        $id = $notification->id ?? null;

        if (! is_string($id) || $id === '') {
            return null;
        }

        return $id;
    }

    private function extractNumericId(object $model): ?int
    {
        $id = $model->id ?? null;

        return is_numeric($id) ? (int) $id : null;
    }

    private function extractMarketId(object $model): ?int
    {
        $marketId = $model->market_id ?? null;

        return is_numeric($marketId) ? (int) $marketId : null;
    }

    /**
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    private function normalizeValue(mixed $value): mixed
    {
        if (is_null($value) || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof \JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize());
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if (is_object($value) && method_exists($value, 'toArray')) {
            try {
                return $this->normalizeValue($value->toArray());
            } catch (\Throwable) {
                // continue
            }
        }

        if ($value instanceof \Throwable) {
            return $this->truncate($value->getMessage());
        }

        return $this->truncate(get_debug_type($value));
    }

    private function truncate(string $value, int $max = 2000): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max - 3) . '...';
    }
}

