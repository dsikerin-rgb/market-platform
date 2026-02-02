<?php

namespace App\Console\Commands;

use App\Models\MarketHoliday;
use App\Models\User;
use App\Notifications\MarketHolidayNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class NotifyMarketHolidays extends Command
{
    protected $signature = 'market:holidays:notify';

    protected $description = 'Отправка уведомлений о предстоящих праздниках рынка.';

    public function handle(): int
    {
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

        foreach ($holidays as $holiday) {
            $market = $holiday->market;

            if (! $market) {
                $holiday->forceFill(['notified_at' => $now])->save();
                continue;
            }

            $recipients = $this->resolveRecipients($market->id, $market->settings ?? []);

            if ($recipients->isNotEmpty()) {
                foreach ($recipients as $recipient) {
                    $recipient->notify(new MarketHolidayNotification($holiday, $market));
                }
            }

            $holiday->forceFill(['notified_at' => $now])->save();
        }

        $this->info(sprintf('Уведомления отправлены: %d.', $holidays->count()));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $settings
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
