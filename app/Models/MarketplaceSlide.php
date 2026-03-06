<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $value = trim((string) ($this->image_path ?? ''));

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:', '/'])) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
