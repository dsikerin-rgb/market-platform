<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketplaceMediaStorage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceSlide extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'title',
        'badge',
        'description',
        'image_path',
        'theme',
        'cta_label',
        'cta_url',
        'placement',
        'audience',
        'sort_order',
        'starts_at',
        'ends_at',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        return MarketplaceMediaStorage::url($this->image_path);
    }

    public function getImagePreviewUrlAttribute(): ?string
    {
        return MarketplaceMediaStorage::previewUrl($this->image_path);
    }
}
