<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MarketplaceAnnouncement extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
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

    public function getCoverImageUrlAttribute(): ?string
    {
        $value = trim((string) ($this->cover_image ?? ''));

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:', '/'])) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
