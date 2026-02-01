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

    public const DEBT_STATUS_LABELS = [
        'green' => 'Нет задолженности',
        'orange' => 'Задолженность до 3 месяцев',
        'red' => 'Задолженность свыше 3 месяцев',
    ];

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
        'debt_status',
        'debt_status_note',
        'debt_status_updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'debt_status_updated_at' => 'datetime',
    ];

    /**
     * Чтобы в JSON/карточках можно было брать tenant.display_name.
     *
     * @var list<string>
     */
    protected $appends = [
        'display_name',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tenant $tenant): void {
            if (! array_key_exists($tenant->debt_status, self::DEBT_STATUS_LABELS)) {
                $tenant->debt_status = null;
            }

            if ($tenant->isDirty('debt_status')) {
                $tenant->debt_status_updated_at = now();
            }
        });
    }

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

    public function getDebtStatusLabelAttribute(): string
    {
        $status = $this->debt_status;

        return self::DEBT_STATUS_LABELS[$status] ?? 'Не указано';
    }

    /**
     * Удобный scope (пригодится для карты/выборок).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
