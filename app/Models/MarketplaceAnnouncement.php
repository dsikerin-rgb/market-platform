<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketWriteGuard;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class MarketplaceAnnouncement extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'market_holiday_id',
        'author_user_id',
        'kind',
        'title',
        'slug',
        'excerpt',
        'content',
        'cover_image',
        'starts_at',
        'ends_at',
        'is_active',
        'published_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $announcement): void {
            $announcement->assertHolidayBelongsToAnnouncementMarket();
            $announcement->assertAuthorBelongsToAnnouncementMarket();
        });
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    public function marketHoliday(): BelongsTo
    {
        return $this->belongsTo(MarketHoliday::class, 'market_holiday_id');
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
        return $this->marketHoliday?->publicCardPayload() ?? [
            'summary' => '',
            'details' => '',
            'time_note' => '',
            'location_title' => '',
            'location_note' => '',
            'special_hours' => '',
            'primary_cta_label' => '',
            'primary_cta_url' => '',
            'schedule_items' => [],
            'promo_items' => [],
        ];
    }

    private function assertHolidayBelongsToAnnouncementMarket(): void
    {
        if (! $this->market_id || ! $this->market_holiday_id || ! Schema::hasTable('market_holidays')) {
            return;
        }

        $holidayMarketId = MarketHoliday::query()
            ->whereKey((int) $this->market_holiday_id)
            ->value('market_id');

        if ($holidayMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $holidayMarketId,
            'market_holiday_id',
            'Marketplace announcement holiday belongs to another market.',
        );
    }

    private function assertAuthorBelongsToAnnouncementMarket(): void
    {
        if (! $this->market_id || ! $this->author_user_id || ! Schema::hasTable('users')) {
            return;
        }

        $author = User::query()->find((int) $this->author_user_id, ['id', 'market_id']);

        if (! $author || $author->isSuperAdmin() || $author->market_id === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $author->market_id,
            'author_user_id',
            'Marketplace announcement author belongs to another market.',
        );
    }
}
