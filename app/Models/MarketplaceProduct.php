<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class MarketplaceProduct extends Model
{
    use HasFactory;

    protected static ?bool $hasIsDemoColumn = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'market_space_id',
        'category_id',
        'title',
        'slug',
        'description',
        'price',
        'currency',
        'stock_qty',
        'sku',
        'unit',
        'images',
        'attributes',
        'views_count',
        'favorites_count',
        'is_active',
        'is_featured',
        'is_demo',
        'published_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'images' => 'array',
        'attributes' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'is_demo' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function marketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MarketplaceCategory::class, 'category_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(MarketplaceFavorite::class, 'product_id');
    }

    public function scopePubliclyVisibleInMarket(Builder $query, int $marketId, bool $allowWithoutActiveContracts = false, bool $includeDemoContent = true): Builder
    {
        $query = $query
            ->where('market_id', $marketId)
            ->where('is_active', true);

        if (! $includeDemoContent && self::hasDemoFlagColumn()) {
            $query->where('is_demo', false);
        }

        if ($allowWithoutActiveContracts) {
            return $query;
        }

        return $query->whereHas('tenant.contracts', function (Builder $contracts) use ($marketId): void {
            $contracts
                ->where('market_id', $marketId)
                ->where('is_active', true);
        });
    }

    public function scopeWithoutDemoContent(Builder $query, bool $includeDemoContent): Builder
    {
        if ($includeDemoContent || ! self::hasDemoFlagColumn()) {
            return $query;
        }

        return $query->where('is_demo', false);
    }

    protected static function hasDemoFlagColumn(): bool
    {
        if (static::$hasIsDemoColumn === null) {
            static::$hasIsDemoColumn = Schema::hasColumn('marketplace_products', 'is_demo');
        }

        return static::$hasIsDemoColumn;
    }
}
