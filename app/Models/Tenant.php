<?php
# app/Models/Tenant.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use HasFactory;

    private const TYPE_LLC = 'llc';
    private const TYPE_SOLE_TRADER = 'sole_trader';
    private const TYPE_SELF_EMPLOYED = 'self_employed';
    private const TYPE_INDIVIDUAL = 'individual';

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
        'slug',
        'type',

        // идентификаторы / реквизиты
        'external_id',   // наш/внешний код (legacy)
        'one_c_uid',     // UUID из 1С (если есть)
        'inn',
        'kpp',
        'ogrn',

        // контакты
        'phone',
        'email',
        'contact_person',

        // статус/активность
        'status',
        'is_active',

        // данные/примечания
        'notes',
        'one_c_data',

        // долги
        'debt_status',
        'debt_status_updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'debt_status_updated_at' => 'datetime',
        'one_c_data' => 'array',
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
            $inferredType = self::inferTypeFromRequisites($tenant);
            if ($inferredType !== null) {
                $tenant->type = $inferredType;
            }

            if (! array_key_exists($tenant->debt_status, self::DEBT_STATUS_LABELS)) {
                $tenant->debt_status = null;
            }

            if ($tenant->isDirty('debt_status')) {
                $tenant->debt_status_updated_at = now();
            }
        });
    }

    private static function inferTypeFromRequisites(self $tenant): ?string
    {
        $name = mb_strtoupper(trim((string) ($tenant->name ?? '')), 'UTF-8');
        $shortName = mb_strtoupper(trim((string) ($tenant->short_name ?? '')), 'UTF-8');
        $source = trim($name . ' ' . $shortName);

        $inn = preg_replace('/\D+/u', '', (string) ($tenant->inn ?? '')) ?? '';
        $ogrn = preg_replace('/\D+/u', '', (string) ($tenant->ogrn ?? '')) ?? '';

        if (self::containsAny($source, ['САМОЗАНЯТ'])) {
            return self::TYPE_SELF_EMPLOYED;
        }

        if (self::containsAny($source, [' ИП', 'ИП ', 'ИНДИВИДУАЛЬНЫЙ ПРЕДПРИНИМАТЕЛЬ'])) {
            return self::TYPE_SOLE_TRADER;
        }

        if (strlen($ogrn) === 15) {
            return self::TYPE_SOLE_TRADER;
        }

        if (strlen($ogrn) === 13) {
            return self::TYPE_LLC;
        }

        if (strlen($inn) === 10) {
            return self::TYPE_LLC;
        }

        if (strlen($inn) === 12) {
            return self::containsAny($source, [' ИП', 'ИП '])
                ? self::TYPE_SOLE_TRADER
                : self::TYPE_INDIVIDUAL;
        }

        if (preg_match('/\b(ООО|АО|ПАО|ЗАО|ОАО|НАО)\b/u', $source) === 1) {
            return self::TYPE_LLC;
        }

        return null;
    }

    /**
     * @param array<int, string> $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
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

    public function documents(): HasMany
    {
        return $this->hasMany(TenantDocument::class);
    }

    public function showcase(): HasOne
    {
        return $this->hasOne(TenantShowcase::class);
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
