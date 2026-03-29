<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketplaceMediaStorage;
use App\Support\MarketHolidayAnnouncementSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketHoliday extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'description',
        'notify_before_days',
        'notify_at',
        'notified_at',
        'source',
        'cover_image',
        'audience_scope',
        'audience_payload',
    ];

    protected $casts = [
        'market_id' => 'integer',
        'starts_at' => 'date',
        'ends_at' => 'date',
        'all_day' => 'boolean',
        'notify_before_days' => 'integer',
        'notify_at' => 'datetime',
        'notified_at' => 'datetime',
        'audience_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $holiday): void {
            $notifyBeforeDays = $holiday->notify_before_days;

            if ($notifyBeforeDays === null) {
                $notifyBeforeDays = $holiday->resolveDefaultNotifyDays();
            }

            if ($holiday->starts_at && $notifyBeforeDays !== null) {
                $holiday->notify_at = $holiday->starts_at->copy()->startOfDay()->subDays((int) $notifyBeforeDays);
            } else {
                $holiday->notify_at = null;
            }
        });

        static::saved(function (self $holiday): void {
            app(MarketHolidayAnnouncementSync::class)->sync($holiday);
        });

        static::deleted(function (self $holiday): void {
            app(MarketHolidayAnnouncementSync::class)->delete($holiday);
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function announcement(): HasOne
    {
        return $this->hasOne(MarketplaceAnnouncement::class, 'market_holiday_id');
    }

    public function resolveDefaultNotifyDays(): ?int
    {
        if (! $this->market_id) {
            return null;
        }

        $market = Market::query()->select(['id', 'settings'])->find($this->market_id);

        if (! $market) {
            return null;
        }

        $settings = (array) ($market->settings ?? []);
        $value = $settings['holiday_default_notify_before_days'] ?? null;

        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        return 7;
    }

    public function getCoverImageUrlAttribute(): ?string
    {
        return MarketplaceMediaStorage::url($this->cover_image);
    }

    public function getCoverImagePreviewUrlAttribute(): ?string
    {
        return MarketplaceMediaStorage::previewUrl($this->cover_image);
    }
}
