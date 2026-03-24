<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TenantSpaceShowcase extends Model
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
        'title',
        'description',
        'assortment',
        'phone',
        'telegram',
        'website',
        'photos',
        'is_active',
        'is_demo',
    ];

    protected $casts = [
        'photos' => 'array',
        'is_active' => 'boolean',
        'is_demo' => 'boolean',
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
            static::$hasIsDemoColumn = Schema::hasColumn('tenant_space_showcases', 'is_demo');
        }

        return static::$hasIsDemoColumn;
    }
}
