<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketplaceMediaStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
