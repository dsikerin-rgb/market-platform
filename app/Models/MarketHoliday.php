<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketplaceMediaStorage;
use App\Support\MarketHolidayAnnouncementSync;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

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
        'public_payload',
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
        'public_payload' => 'array',
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

    /**
     * @return array{
     *   summary:string,
     *   details:string,
     *   time_note:string,
     *   location_title:string,
     *   location_note:string,
     *   special_hours:string,
     *   primary_cta_label:string,
     *   primary_cta_url:string,
     *   schedule_items:list<array{time:string,title:string,description:string}>,
     *   promo_items:list<array{badge:string,title:string,description:string,link_label:string,link_url:string}>
     * }
     */
    public function publicCardPayload(): array
    {
        $payload = is_array($this->public_payload ?? null) ? $this->public_payload : [];

        return [
            'summary' => $this->cleanText($payload['summary'] ?? null),
            'details' => $this->cleanText($payload['details'] ?? null),
            'time_note' => $this->cleanText($payload['time_note'] ?? null),
            'location_title' => $this->cleanText($payload['location_title'] ?? null),
            'location_note' => $this->cleanText($payload['location_note'] ?? null),
            'special_hours' => $this->cleanText($payload['special_hours'] ?? null),
            'primary_cta_label' => $this->cleanText($payload['primary_cta_label'] ?? null),
            'primary_cta_url' => $this->cleanText($payload['primary_cta_url'] ?? null),
            'schedule_items' => $this->normalizeScheduleItems($payload['schedule_items'] ?? []),
            'promo_items' => $this->normalizePromoItems($payload['promo_items'] ?? []),
        ];
    }

    public function announcementExcerptText(): ?string
    {
        $payload = $this->publicCardPayload();

        foreach ([$payload['summary'], $this->description, $payload['details']] as $value) {
            $text = $this->cleanText($value);
            if ($text !== '') {
                return Str::limit($text, 220);
            }
        }

        return null;
    }

    public function announcementContentText(): ?string
    {
        $payload = $this->publicCardPayload();

        foreach ([$payload['details'], $this->description, $payload['summary']] as $value) {
            $text = $this->cleanText($value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function cleanText(mixed $value): string
    {
        return trim((string) $value);
    }

    /**
     * @param  mixed  $items
     * @return list<array{time:string,title:string,description:string}>
     */
    private function normalizeScheduleItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [
                'time' => $this->cleanText($item['time'] ?? null),
                'title' => $this->cleanText($item['title'] ?? null),
                'description' => $this->cleanText($item['description'] ?? null),
            ];

            if ($row['time'] === '' && $row['title'] === '' && $row['description'] === '') {
                continue;
            }

            $normalized[] = $row;
        }

        return array_values($normalized);
    }

    /**
     * @param  mixed  $items
     * @return list<array{badge:string,title:string,description:string,link_label:string,link_url:string}>
     */
    private function normalizePromoItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $row = [
                'badge' => $this->cleanText($item['badge'] ?? null),
                'title' => $this->cleanText($item['title'] ?? null),
                'description' => $this->cleanText($item['description'] ?? null),
                'link_label' => $this->cleanText($item['link_label'] ?? null),
                'link_url' => $this->cleanText($item['link_url'] ?? null),
            ];

            if (
                $row['badge'] === ''
                && $row['title'] === ''
                && $row['description'] === ''
                && $row['link_label'] === ''
                && $row['link_url'] === ''
            ) {
                continue;
            }

            $normalized[] = $row;
        }

        return array_values($normalized);
    }
}
