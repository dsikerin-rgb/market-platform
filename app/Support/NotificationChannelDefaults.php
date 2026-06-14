<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

class NotificationChannelDefaults
{
    /**
     * @return list<string>
     */
    public function resolveWithMailDefault(object $notifiable, string $topic, ?int $marketId = null): array
    {
        $channels = app(NotificationChannelResolver::class)->resolve($notifiable, $topic, $marketId);

        if (! $notifiable instanceof User || blank($notifiable->email)) {
            return $channels;
        }

        $preferences = app(UserNotificationPreferences::class);
        if (! $preferences->isTopicEnabled($notifiable, $topic)) {
            return [];
        }

        $override = $preferences->channelsOverride($notifiable);
        if ($override === null && ! in_array('mail', $channels, true)) {
            $channels[] = 'mail';
        }

        return array_values(array_unique($channels));
    }
}
