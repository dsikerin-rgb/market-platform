<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\NotificationHealthAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckNotificationHealth extends Command
{
    protected $signature = 'notifications:health-check
        {--hours=1 : Window size in hours}
        {--market= : Filter by market_id}
        {--max-failed-deliveries=0 : Max allowed failed deliveries in window}
        {--max-failed-jobs=0 : Max allowed failed queue jobs in window}
        {--notify : Send in-app alert to admins when threshold exceeded}';

    protected $description = 'Checks notification delivery health and alerts admins on threshold breaches.';

    public function handle(): int
    {
        if (! Schema::hasTable('notification_deliveries')) {
            $this->warn('notification_deliveries table is missing. Run migrations first.');

            return Command::SUCCESS;
        }

        $hours = max(1, (int) $this->option('hours'));
        $marketId = $this->normalizeMarketId($this->option('market'));
        $maxFailedDeliveries = max(0, (int) $this->option('max-failed-deliveries'));
        $maxFailedJobs = max(0, (int) $this->option('max-failed-jobs'));
        $notify = (bool) $this->option('notify');

        $from = now()->subHours($hours);

        $base = NotificationDelivery::query()->where('created_at', '>=', $from);
        if ($marketId !== null) {
            $base->where('market_id', $marketId);
        }

        $failedDeliveries = (clone $base)
            ->where('status', NotificationDelivery::STATUS_FAILED)
            ->count();

        $failedJobs = 0;
        if (Schema::hasTable('failed_jobs')) {
            $failedJobsQuery = DB::table('failed_jobs')->where('failed_at', '>=', $from);
            if ($marketId !== null) {
                // failed_jobs не связана напрямую с market_id; фильтруем только по окну.
            }

            $failedJobs = $failedJobsQuery->count();
        }

        $isCritical = $failedDeliveries > $maxFailedDeliveries || $failedJobs > $maxFailedJobs;

        $scope = $marketId !== null ? "market_id={$marketId}" : 'all markets';
        $this->line('--- Notifications Health Check ---');
        $this->line("Window: last {$hours}h");
        $this->line("Scope: {$scope}");
        $this->line("failed_deliveries={$failedDeliveries} (threshold={$maxFailedDeliveries})");
        $this->line("failed_jobs={$failedJobs} (threshold={$maxFailedJobs})");
        $this->line('status=' . ($isCritical ? 'ALERT' : 'OK'));

        if ($isCritical && $notify) {
            $this->notifyAdmins(
                marketId: $marketId,
                hours: $hours,
                failedDeliveries: $failedDeliveries,
                failedJobs: $failedJobs
            );
        }

        return $isCritical ? Command::FAILURE : Command::SUCCESS;
    }

    private function normalizeMarketId(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $marketId = (int) $value;

        return $marketId > 0 ? $marketId : null;
    }

    private function notifyAdmins(int|null $marketId, int $hours, int $failedDeliveries, int $failedJobs): void
    {
        $recipients = $this->resolveRecipients($marketId);
        if ($recipients->isEmpty()) {
            $this->warn('No recipients resolved for health alert.');

            return;
        }

        $scope = $marketId !== null ? "рынок #{$marketId}" : 'все рынки';
        $title = 'Сбой доставки уведомлений';
        $body = "Окно: {$hours}ч, {$scope}. Ошибки доставки: {$failedDeliveries}, failed_jobs: {$failedJobs}.";
        $url = url('/admin');

        $fingerprint = md5(implode('|', [
            (string) $marketId,
            (string) $hours,
            (string) $failedDeliveries,
            (string) $failedJobs,
        ]));

        $cacheKey = 'notifications-health-alert:' . ($marketId ?? 'all');
        $lastFingerprint = Cache::get($cacheKey);
        if ($lastFingerprint === $fingerprint) {
            $this->line('Alert suppressed (same fingerprint already sent recently).');

            return;
        }

        foreach ($recipients as $recipient) {
            $recipient->notify(new NotificationHealthAlert($title, $body, $url));
        }

        Cache::put($cacheKey, $fingerprint, now()->addMinutes(30));
        $this->line('Alert sent to recipients: ' . $recipients->count());
    }

    /**
     * @return Collection<int, User>
     */
    private function resolveRecipients(?int $marketId): Collection
    {
        $users = User::query()
            ->role('super-admin')
            ->get();

        if ($marketId === null) {
            return $users->unique('id')->values();
        }

        $market = Market::query()->select(['id', 'settings'])->find($marketId);
        $settings = (array) ($market?->settings ?? []);

        $settingsRecipients = array_merge(
            (array) ($settings['holiday_notification_recipient_user_ids'] ?? []),
            (array) ($settings['request_notification_recipient_user_ids'] ?? []),
            (array) ($settings['request_repair_notification_recipient_user_ids'] ?? [])
        );

        $settingsRecipientIds = array_values(array_filter(
            $settingsRecipients,
            static fn ($value): bool => is_numeric($value)
        ));

        $marketAdmins = User::query()
            ->where('market_id', $marketId)
            ->role('market-admin')
            ->get();

        $explicitRecipients = $settingsRecipientIds === []
            ? collect()
            : User::query()
                ->where('market_id', $marketId)
                ->whereIn('id', $settingsRecipientIds)
                ->get();

        return $users
            ->merge($marketAdmins)
            ->merge($explicitRecipients)
            ->unique('id')
            ->values();
    }
}

