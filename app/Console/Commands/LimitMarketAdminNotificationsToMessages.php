<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\MarketContext;
use App\Support\UserNotificationPreferences;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LimitMarketAdminNotificationsToMessages extends Command
{
    protected $signature = 'notifications:limit-market-admins-to-messages
        {--market= : Market ID}
        {--dry-run : Run in dry-run mode}
        {--execute : Apply changes (default: dry-run)}';

    protected $description = 'Limit market-admin notification topics to messages and mark existing unread notifications as read.';

    public function handle(UserNotificationPreferences $preferences): int
    {
        $marketId = $this->marketIdOption();
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($marketId === false) {
            $this->error('Market ID must be a positive integer.');

            return self::FAILURE;
        }

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        if ($execute && $marketId === null) {
            $this->error('Market ID is required with --execute. Use --market=1.');

            return self::FAILURE;
        }

        if ($marketId !== null) {
            return app(MarketContext::class)->withMarket(
                $marketId,
                fn (): int => $this->limitNotifications($preferences, $marketId, $dryRun),
            );
        }

        return $this->limitNotifications($preferences, null, $dryRun);
    }

    private function limitNotifications(UserNotificationPreferences $preferences, ?int $marketId, bool $dryRun): int
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'notification_preferences')) {
            $this->error('users.notification_preferences is not available.');

            return self::FAILURE;
        }

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            $this->error('Spatie permission tables are not available.');

            return self::FAILURE;
        }

        $users = $this->marketAdmins($marketId);
        $userIds = $users->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $unreadNotifications = $this->countUnreadNotifications($userIds);

        if ($dryRun) {
            $this->info(sprintf(
                'Would update %d market-admin users and mark %d unread notifications as read.',
                $users->count(),
                $unreadNotifications,
            ));

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            $raw = (array) ($user->notification_preferences ?? []);
            $channels = $preferences->normalizeChannels($raw['channels'] ?? []);

            if ($channels === []) {
                $channels = $preferences->defaultChannelsForUser($user);
            }

            $channels = array_values(array_unique(array_merge(['database'], $channels)));

            $user->forceFill([
                'notification_preferences' => $preferences->normalizeForStorage([
                    'self_manage' => (bool) ($raw['self_manage'] ?? true),
                    'channels' => $channels,
                    'topics' => [UserNotificationPreferences::TOPIC_MESSAGES],
                ], true, [UserNotificationPreferences::TOPIC_MESSAGES]),
            ])->save();
        }

        $markedRead = $this->markUnreadNotificationsRead($userIds);

        $this->info(sprintf(
            'Updated %d market-admin users. Marked %d unread notifications as read.',
            $users->count(),
            $markedRead,
        ));

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, User>
     */
    private function marketAdmins(?int $marketId)
    {
        $query = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', 'market-admin'))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super-admin'));

        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function countUnreadNotifications(array $userIds): int
    {
        if ($userIds === [] || ! Schema::hasTable('notifications')) {
            return 0;
        }

        return (int) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->whereNull('read_at')
            ->count();
    }

    /**
     * @param  list<int>  $userIds
     */
    private function markUnreadNotificationsRead(array $userIds): int
    {
        if ($userIds === [] || ! Schema::hasTable('notifications')) {
            return 0;
        }

        return (int) DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->whereIn('notifiable_id', $userIds)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function marketIdOption(): int|false|null
    {
        $value = $this->option('market');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $marketId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($marketId) ? $marketId : false;
    }
}
