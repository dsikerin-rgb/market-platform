<?php
# app/Models/MarketSpace.php

namespace App\Models;

use App\Services\MarketSpaces\MarketSpaceTenantBindingRecorder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketSpace extends Model
{
    use HasFactory;

    public const SPACE_GROUP_ROLE_NONE = 'none';
    public const SPACE_GROUP_ROLE_PARENT = 'parent';
    public const SPACE_GROUP_ROLE_CHILD = 'child';

    public const SPACE_GROUP_ROLES = [
        self::SPACE_GROUP_ROLE_NONE,
        self::SPACE_GROUP_ROLE_PARENT,
        self::SPACE_GROUP_ROLE_CHILD,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'location_id',
        'tenant_id',
        'number',
        'code',
        'space_group_token',
        'space_group_slot',
        'space_group_role',
        'space_group_parent_id',
        'display_name',
        'activity_type',
        'area_sqm',
        'rent_rate_value',
        'rent_rate_unit',
        'rent_rate_updated_at',
        'type',
        'status',
        'map_review_status',
        'map_reviewed_at',
        'map_reviewed_by',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
        'rent_rate_value' => 'decimal:2',
        'rent_rate_updated_at' => 'datetime',
        'map_reviewed_at' => 'datetime',
        'is_active' => 'boolean',
        'space_group_role' => 'string',
        'space_group_parent_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $space): void {
            $space->ensureCode();
            $space->normalizeSpaceGroup();
        });

        static::updating(function (self $space): void {
            // если код пустой — восстановим; если задан руками — не трогаем
            if (blank($space->code)) {
                $space->ensureCode();
            }

            $space->normalizeSpaceGroup();
        });

        static::updating(function (self $space): void {
            $now = now();
            $userId = Auth::id();

            if ($space->isDirty('tenant_id') && Schema::hasTable('market_space_tenant_histories')) {
                DB::table('market_space_tenant_histories')->insert([
                    'market_space_id' => $space->id,
                    'old_tenant_id' => $space->getOriginal('tenant_id'),
                    'new_tenant_id' => $space->tenant_id,
                    'changed_at' => $now,
                    'changed_by_user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $rentRateChanged = $space->isDirty('rent_rate_value') || $space->isDirty('rent_rate_unit');

            if ($rentRateChanged && Schema::hasTable('market_space_rent_rate_histories')) {
                $unit = $space->rent_rate_unit ?? $space->getOriginal('rent_rate_unit');

                DB::table('market_space_rent_rate_histories')->insert([
                    'market_space_id' => $space->id,
                    'old_value' => $space->getOriginal('rent_rate_value'),
                    'new_value' => $space->rent_rate_value,
                    'unit' => $unit,
                    'changed_at' => $now,
                    'changed_by_user_id' => $userId,
                    'note' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($rentRateChanged && Schema::hasColumn('market_spaces', 'rent_rate_updated_at')) {
                $space->rent_rate_updated_at = $now;
            }
        });

        static::saved(function (self $space): void {
            if (! Schema::hasTable('market_space_tenant_bindings')) {
                return;
            }

            app(MarketSpaceTenantBindingRecorder::class)->syncFromSpaceSnapshot($space);
        });
    }

    private function ensureCode(): void
    {
        $marketId = $this->market_id;

        // Без market_id уникальность в рамках рынка не гарантируем
        if (blank($marketId)) {
            return;
        }

        $requested = trim((string) $this->code);

        // Приоритет: number (человеческий идентификатор), иначе code, иначе дефолт
        $baseSource = trim((string) ($this->number ?: $requested));

        $base = $baseSource !== ''
            ? Str::slug($baseSource, '-')
            : '';

        $base = Str::lower($base);

        if ($base === '') {
            $base = 'space';
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

    private function normalizeSpaceGroup(): void
    {
        $token = trim((string) ($this->space_group_token ?? ''));
        $slot = trim((string) ($this->space_group_slot ?? ''));

        $token = mb_strtoupper($token, 'UTF-8');
        $token = preg_replace('/\s+/u', '', $token) ?? $token;
        $token = str_replace(['-', '/'], '', $token);

        $slot = preg_replace('/\s*([,-])\s*/u', '$1', $slot) ?? $slot;
        $slot = preg_replace('/\s+/u', ' ', $slot) ?? $slot;

        $role = $this->space_group_role ?? null;

        if (! in_array($role, self::SPACE_GROUP_ROLES, true)) {
            $role = self::SPACE_GROUP_ROLE_NONE;
        }

        if ($role === self::SPACE_GROUP_ROLE_NONE) {
            // none: очистить всё
            $this->space_group_token = null;
            $this->space_group_slot = null;
            $this->space_group_parent_id = null;
        } elseif ($role === self::SPACE_GROUP_ROLE_PARENT) {
            // parent: очистить slot и parent_id, но НЕ очищать token (legacy)
            $this->space_group_slot = null;
            $this->space_group_parent_id = null;
            // space_group_token оставляем как legacy-поле, не трогаем
        } elseif ($role === self::SPACE_GROUP_ROLE_CHILD) {
            // child: нормализовать slot, НЕ трогать token (legacy) и parent_id
            $this->space_group_slot = $slot !== '' ? $slot : null;
            // space_group_token оставляем как legacy-поле, не трогаем
            // space_group_parent_id не трогаем — он устанавливается через Select в UI
        }

        $this->space_group_role = $role;
    }

    public function effectiveOccupancySourceSpace(): ?self
    {
        if (filled($this->tenant_id)) {
            return $this;
        }

        if ($this->space_group_role !== self::SPACE_GROUP_ROLE_CHILD) {
            return null;
        }

        $parent = $this->spaceGroupParent;
        if (! $parent instanceof self) {
            return null;
        }

        return filled($parent->tenant_id) ? $parent : null;
    }

    public function effectiveTenant(): ?Tenant
    {
        $sourceSpace = $this->effectiveOccupancySourceSpace();

        return $sourceSpace?->tenant instanceof Tenant ? $sourceSpace->tenant : null;
    }

    public function effectiveTenantId(): ?int
    {
        $tenant = $this->effectiveTenant();

        return $tenant?->getKey() ? (int) $tenant->getKey() : null;
    }

    public function effectiveTenantName(): ?string
    {
        $tenant = $this->effectiveTenant();
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $name = trim((string) ($tenant->display_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) ($tenant->short_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = trim((string) ($tenant->name ?? ''));

        return $name !== '' ? $name : null;
    }

    public function effectiveOccupancySource(): string
    {
        if (filled($this->tenant_id)) {
            return 'direct';
        }

        if ($this->effectiveOccupancySourceSpace() instanceof self) {
            return $this->space_group_role === self::SPACE_GROUP_ROLE_CHILD ? 'parent' : 'direct';
        }

        return 'none';
    }

    public function isEffectivelyOccupied(): bool
    {
        return $this->effectiveOccupancySource() !== 'none';
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MarketLocation::class, 'location_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function spaceType(): BelongsTo
    {
        // ВАЖНО: whereColumn ломает eager-loading в sqlite (отдельный запрос к market_space_types
        // не может ссылаться на market_spaces.market_id).
        return $this->belongsTo(MarketSpaceType::class, 'type', 'code');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function tenantBindings(): HasMany
    {
        return $this->hasMany(MarketSpaceTenantBinding::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(TenantReview::class);
    }

    public function showcaseProfiles(): HasMany
    {
        return $this->hasMany(TenantSpaceShowcase::class);
    }

    public function marketplaceProducts(): HasMany
    {
        return $this->hasMany(MarketplaceProduct::class);
    }

    public function marketplaceChats(): HasMany
    {
        return $this->hasMany(MarketplaceChat::class);
    }

    public function cabinetUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user_market_spaces', 'market_space_id', 'user_id')
            ->withTimestamps();
    }

    public function mapShapes(): HasMany
    {
        return $this->hasMany(MarketSpaceMapShape::class, 'market_space_id', 'id');
    }

    public function spaceGroupParent(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class, 'space_group_parent_id');
    }

    public function spaceGroupChildren(): HasMany
    {
        return $this->hasMany(MarketSpace::class, 'space_group_parent_id');
    }
}
