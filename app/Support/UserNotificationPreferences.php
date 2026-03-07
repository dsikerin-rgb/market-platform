<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

class UserNotificationPreferences
{
    public const TOPIC_SECURITY = 'security';
    public const TOPIC_ONE_C_INTEGRATIONS = 'one_c_integrations';

    /**
     * @var list<string>
     */
    public const TOPICS = [
        'calendar',
        'requests',
        'messages',
        'tasks',
        'reminders',
        self::TOPIC_SECURITY,
        self::TOPIC_ONE_C_INTEGRATIONS,
    ];

    /**
     * @var list<string>
     */
    public const CHANNELS = [
        'database',
        'mail',
        'telegram',
    ];

    /**
     * @return array<string, string>
     */
    public static function topicLabels(): array
    {
        return [
            'calendar' => 'Календарь',
            'requests' => 'Обращения',
            'messages' => 'Сообщения',
            'tasks' => 'Назначения задач',
            'reminders' => 'Напоминания',
            self::TOPIC_SECURITY => 'Безопасность и входы',
            self::TOPIC_ONE_C_INTEGRATIONS => 'Интеграции 1С',
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaultTopicsForUser(User $user): array
    {
        return $user->isSuperAdmin()
            ? self::TOPICS
            : array_values(array_diff(self::TOPICS, [
                self::TOPIC_SECURITY,
                self::TOPIC_ONE_C_INTEGRATIONS,
            ]));
    }

    /**
     * @param list<string> $roleNames
     * @return list<string>
     */
    public static function defaultTopicsForRoleNames(array $roleNames): array
    {
        return in_array('super-admin', $roleNames, true)
            ? self::TOPICS
            : array_values(array_diff(self::TOPICS, [
                self::TOPIC_SECURITY,
                self::TOPIC_ONE_C_INTEGRATIONS,
            ]));
    }

    /**
     * @return list<string>
     */
    public static function visibleTopicsForUser(User $user): array
    {
        return $user->isSuperAdmin()
            ? self::TOPICS
            : array_values(array_diff(self::TOPICS, [self::TOPIC_ONE_C_INTEGRATIONS]));
    }

    /**
     * @return array<string, string>
     */
    public static function channelLabels(): array
    {
        return [
            'database' => 'В кабинете',
            'mail' => 'Email',
            'telegram' => 'Telegram',
        ];
    }

    public function canSelfManage(User $user): bool
    {
        if ($user->isSuperAdmin() || $user->isMarketAdmin()) {
            return true;
        }

        $raw = (array) ($user->notification_preferences ?? []);

        return (bool) ($raw['self_manage'] ?? false);
    }

    /**
     * @return list<string>|null Null means "no personal override".
     */
    public function channelsOverride(User $user): ?array
    {
        $raw = (array) ($user->notification_preferences ?? []);

        if (! array_key_exists('channels', $raw)) {
            return null;
        }

        $channels = $this->normalizeChannels($raw['channels']);

        return $channels === [] ? null : $channels;
    }

    public function isTopicEnabled(User $user, string $topic): bool
    {
        if (! in_array($topic, self::TOPICS, true)) {
            return true;
        }

        $raw = (array) ($user->notification_preferences ?? []);

        if (! array_key_exists('topics', $raw)) {
            return in_array($topic, self::defaultTopicsForUser($user), true);
        }

        $topics = $this->normalizeTopics($raw['topics']);

        return in_array($topic, $topics, true);
    }

    /**
     * @return array{self_manage:bool,channels:list<string>,topics:list<string>}
     */
    public function normalizeForStorage(
        mixed $value,
        bool $fallbackSelfManage = false,
        ?array $defaultTopics = null,
    ): array {
        $raw = is_array($value) ? $value : [];
        $defaultTopics ??= self::TOPICS;

        return [
            'self_manage' => (bool) ($raw['self_manage'] ?? $fallbackSelfManage),
            'channels' => $this->normalizeChannels($raw['channels'] ?? []),
            'topics' => $this->normalizeTopics($raw['topics'] ?? $defaultTopics),
        ];
    }

    /**
     * @return list<string>
     */
    public function normalizeChannels(mixed $channels): array
    {
        if (! is_array($channels)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item): string => is_string($item) ? trim(mb_strtolower($item)) : '', $channels),
            static fn (string $item): bool => in_array($item, self::CHANNELS, true),
        )));
    }

    /**
     * @return list<string>
     */
    public function normalizeTopics(mixed $topics): array
    {
        if (! is_array($topics)) {
            return self::TOPICS;
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($item): string => is_string($item) ? trim(mb_strtolower($item)) : '', $topics),
            static fn (string $item): bool => in_array($item, self::TOPICS, true),
        )));
    }

    /**
     * @return list<string>
     */
    public function defaultChannelsForUser(User $user): array
    {
        $channels = ['database'];

        if (filled($user->email)) {
            $channels[] = 'mail';
        }

        if (filled($user->telegram_chat_id ?? null)) {
            $channels[] = 'telegram';
        }

        return array_values(array_unique($channels));
    }
}
