<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;

class NotificationChannelResolver
{
    private const SUPPORTED = ['database', 'mail', 'telegram'];

    /**
     * @return list<string>
     */
    public function resolve(object $notifiable, string $topic = 'default', ?int $marketId = null): array
    {
        if ($notifiable instanceof User) {
            $preferences = app(UserNotificationPreferences::class);

            if (! $preferences->isTopicEnabled($notifiable, $topic)) {
                return [];
            }
        }

        $channels = $this->preferredChannels($notifiable);

        if ($notifiable instanceof User) {
            $preferences = app(UserNotificationPreferences::class);
            $override = $preferences->channelsOverride($notifiable);

            if ($override !== null) {
                $channels = array_values(array_intersect($channels, $override));
            }
        }

        $channels = $this->applyMarketPolicy($channels, $topic, $marketId);

        // Telegram transport is not connected yet.
        $channels = array_values(array_filter(
            $channels,
            static fn (string $channel): bool => $channel !== 'telegram'
        ));

        return $channels !== [] ? $channels : ['database'];
    }

    /**
     * @return list<string>
     */
    private function preferredChannels(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable instanceof User) {
            if (filled($notifiable->email)) {
                $channels[] = 'mail';
            }

            if (filled($notifiable->telegram_chat_id ?? null)) {
                $channels[] = 'telegram';
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function applyMarketPolicy(array $channels, string $topic, ?int $marketId): array
    {
        $settingKey = $this->settingKeyForTopic($topic);
        if ($settingKey === null || ! $marketId || $marketId <= 0) {
            return $channels;
        }

        $market = Market::query()
            ->select(['id', 'settings'])
            ->find($marketId);

        if (! $market) {
            return $channels;
        }

        $settings = (array) ($market->settings ?? []);
        $allowed = $this->normalizeChannels($settings[$settingKey] ?? ['database']);

        if ($allowed === []) {
            $allowed = ['database'];
        }

        $intersected = array_values(array_intersect($channels, $allowed));

        if ($intersected !== []) {
            return $intersected;
        }

        return in_array('database', $allowed, true) ? ['database'] : [$allowed[0]];
    }

    private function settingKeyForTopic(string $topic): ?string
    {
        return match ($topic) {
            'calendar' => 'notification_channels_calendar',
            'requests' => 'notification_channels_requests',
            'messages' => 'notification_channels_messages',
            'tasks' => 'notification_channels_tasks',
            'reminders' => 'notification_channels_reminders',
            default => null,
        };
    }

    /**
     * @param  mixed  $channels
     * @return list<string>
     */
    private function normalizeChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($ch) => is_string($ch) ? trim(mb_strtolower($ch)) : '', $channels),
            static fn (string $ch): bool => in_array($ch, self::SUPPORTED, true),
        )));
    }
}
