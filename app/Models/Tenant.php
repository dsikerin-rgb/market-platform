<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'name',
        'short_name',
        'type',
        'inn',
        'ogrn',
        'phone',
        'email',
        'contact_person',
        'status',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Чтобы в JSON/карточках можно было брать tenant.display_name.
     *
     * @var list<string>
     */
    protected $appends = [
        'display_name',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(MarketSpace::class, 'tenant_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(TenantContract::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    /**
     * Каноническое имя для UI:
     * short_name (если есть) -> name -> "Арендатор"
     */
    public function getDisplayNameAttribute(): string
    {
        $short = trim((string) ($this->short_name ?? ''));
        if ($short !== '') {
            return $short;
        }

        $name = trim((string) ($this->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'Арендатор';
    }

    /**
     * Удобный scope (пригодится для карты/выборок).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
