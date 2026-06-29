<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\MarketWriteGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class MarketplaceCategory extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->assertParentBelongsToCategoryMarket();
        });
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
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(MarketplaceProduct::class, 'category_id');
    }

    private function assertParentBelongsToCategoryMarket(): void
    {
        if (! $this->market_id || ! $this->parent_id || ! Schema::hasTable('marketplace_categories')) {
            return;
        }

        $parentMarketId = self::query()
            ->whereKey((int) $this->parent_id)
            ->value('market_id');

        if ($parentMarketId === null) {
            return;
        }

        app(MarketWriteGuard::class)->assertSameMarketId(
            $this->market_id,
            $parentMarketId,
            'parent_id',
            'Marketplace category parent belongs to another market.',
        );
    }
}
