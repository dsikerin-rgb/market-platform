<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class TenantShowcase extends Model
{
    use HasFactory;

    protected static ?bool $hasIsDemoColumn = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'title',
        'description',
        'phone',
        'telegram',
        'website',
        'photos',
        'is_demo',
    ];

    protected $casts = [
        'photos' => 'array',
        'is_demo' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
            static::$hasIsDemoColumn = Schema::hasColumn('tenant_showcases', 'is_demo');
        }

        return static::$hasIsDemoColumn;
    }
}
