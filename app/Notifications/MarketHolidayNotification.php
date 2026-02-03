<?php

namespace App\Notifications;

use App\Filament\Resources\TaskResource;
use App\Models\Market;
use App\Models\MarketHoliday;
use Illuminate\Notifications\Notification;

class MarketHolidayNotification extends Notification
{
    public function __construct(
        private readonly MarketHoliday $holiday,
        private readonly Market $market
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $start = $this->holiday->starts_at?->toDateString();
        $end = $this->holiday->ends_at?->toDateString();

        $range = $end && $end !== $start
            ? sprintf('%s — %s', $start, $end)
            : (string) $start;

        $diffDays = now()->startOfDay()->diffInDays($this->holiday->starts_at?->startOfDay() ?? now()->startOfDay(), false);
        $diffDays = max(0, $diffDays);

        $message = sprintf('Через %d дней: %s (%s)', $diffDays, $this->holiday->title, $range);

        return [
            'market_id' => $this->market->id,
            'holiday_id' => $this->holiday->id,
            'title' => $this->holiday->title,
            'starts_at' => $start,
            'ends_at' => $end,
            'message' => $message,
            'url' => TaskResource::getUrl('calendar', [
                'date' => $start,
                'holidays' => 1,
            ]),
        ];
    }
}
