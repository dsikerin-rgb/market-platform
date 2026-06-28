<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Models\User;
use App\Notifications\MarketHolidayNotification;
use App\Support\MarketContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NotifyMarketHolidays extends Command
{
    protected $signature = 'market:holidays:notify
        {--dry-run : Run in dry-run mode}
        {--execute : Send notifications and mark holidays as notified (default: dry-run)}';

    protected $description = 'Отправка уведомлений о предстоящих праздниках рынка.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return Command::FAILURE;
        }

        $dryRun = ! $execute || (bool) $this->option('dry-run');
        $now = now();

        $holidays = MarketHoliday::query()
            ->whereNull('notified_at')
            ->whereNotNull('notify_at')
            ->where('notify_at', '<=', $now)
            ->with('market')
            ->get();

        if ($holidays->isEmpty()) {
            $this->info('Нет праздников для уведомления.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Would notify holidays: %d.', $holidays->count()));
            $this->info('DRY RUN: no notifications were sent and no holidays were marked notified. Use --execute to apply.');

            return Command::SUCCESS;
        }

        foreach ($holidays as $holiday) {
            $market = $holiday->market;

            if (! $market) {
                $holiday->forceFill(['notified_at' => $now])->save();

                continue;
            }

            app(MarketContext::class)->withMarket((int) $market->id, function () use ($holiday, $market, $now): void {
                $recipients = $this->resolveRecipients((int) $market->id, $market->settings ?? []);

                if ($recipients->isNotEmpty()) {
                    foreach ($recipients as $recipient) {
                        $recipient->notify(new MarketHolidayNotification($holiday, $market));
                    }
                }

                $holiday->forceFill(['notified_at' => $now])->save();
            });
        }

        $this->info(sprintf('Уведомления отправлены: %d.', $holidays->count()));

        return Command::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveRecipients(int $marketId, array $settings): Collection
    {
        $recipientIds = $settings['holiday_notification_recipient_user_ids'] ?? [];

        $recipientIds = array_values(array_filter((array) $recipientIds, static function ($value): bool {
            return is_numeric($value);
        }));

        if (! empty($recipientIds)) {
            return User::query()
                ->whereIn('id', $recipientIds)
                ->where('market_id', $marketId)
                ->get();
        }

        return User::query()
            ->where('market_id', $marketId)
            ->role('market-admin')
            ->get();
    }
}
