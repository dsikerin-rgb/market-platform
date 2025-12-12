<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class MarketLocation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'name',
        'code',
        'type',
        'parent_id',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $location): void {
            $location->ensureCode();
        });

        static::updating(function (self $location): void {
            // Если меняли market_id или code — обязаны перепроверить уникальность.
            // Если code пустой — восстановим.
            if (
                blank($location->code)
                || $location->isDirty('code')
                || $location->isDirty('market_id')
            ) {
                $location->ensureCode();
            }
        });
    }

    private function ensureCode(): void
    {
        $marketId = $this->market_id;

        // Без market_id мы не сможем гарантировать уникальность в рамках рынка.
        if (blank($marketId)) {
            return;
        }

        $requested = trim((string) $this->code);

        // В любом случае делаем slug, чтобы код был безопасным
        $base = $requested !== ''
            ? Str::slug($requested, '-')
            : Str::slug((string) $this->name, '-');

        $base = Str::lower($base);

        if ($base === '') {
            $base = 'location';
        }

        $code = $base;
        $i = 1;

        while (
            self::query()
                ->where('market_id', $marketId)
                ->where('code', $code)
                ->when($this->exists, fn ($q) => $q->whereKeyNot($this->getKey()))
                ->exists()
        ) {
            $i++;
            $code = $base . '-' . $i;
        }

        $this->code = $code;
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(MarketSpace::class);
    }
}
