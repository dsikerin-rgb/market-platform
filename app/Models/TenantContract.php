<?php
# app/Models/TenantContract.php

declare(strict_types=1);

namespace App\Models;

use App\Services\MarketSpaces\MarketSpaceTenantBindingRecorder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class TenantContract extends Model
{
    use HasFactory;

    public const SPACE_MAPPING_MODE_AUTO = 'auto';
    public const SPACE_MAPPING_MODE_MANUAL = 'manual';
    public const SPACE_MAPPING_MODE_EXCLUDED = 'excluded';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'external_id',      // contract_external_id из 1С (ключ связки)
        'market_id',
        'tenant_id',
        'market_space_id',
        'space_mapping_mode',
        'space_mapping_updated_at',
        'space_mapping_updated_by_user_id',
        'number',
        'status',
        'starts_at',
        'ends_at',
        'signed_at',
        'monthly_rent',
        'currency',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'ends_at' => 'date',
        'signed_at' => 'date',
        'monthly_rent' => 'decimal:2',
        'is_active' => 'boolean',
        'space_mapping_updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $contract): void {
            if (! Schema::hasTable('market_space_tenant_bindings')) {
                return;
            }

            if (! $contract->wasRecentlyCreated && ! $contract->wasChanged([
                'tenant_id',
                'market_space_id',
                'space_mapping_mode',
                'status',
                'is_active',
                'starts_at',
                'ends_at',
            ])) {
                return;
            }

            app(MarketSpaceTenantBindingRecorder::class)->syncFromContract($contract);
        });
    }

    /**
     * @return list<string>
     */
    public static function spaceMappingModes(): array
    {
        return [
            self::SPACE_MAPPING_MODE_AUTO,
            self::SPACE_MAPPING_MODE_MANUAL,
            self::SPACE_MAPPING_MODE_EXCLUDED,
        ];
    }

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

    public function spaceMappingUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'space_mapping_updated_by_user_id');
    }

    public function tenantBindings(): HasMany
    {
        return $this->hasMany(MarketSpaceTenantBinding::class);
    }

    public function effectiveSpaceMappingMode(): string
    {
        $mode = trim((string) ($this->space_mapping_mode ?? ''));

        return in_array($mode, self::spaceMappingModes(), true)
            ? $mode
            : self::SPACE_MAPPING_MODE_AUTO;
    }

    public function usesManualSpaceMapping(): bool
    {
        return $this->effectiveSpaceMappingMode() === self::SPACE_MAPPING_MODE_MANUAL;
    }

    public function excludesFromSpaceMapping(): bool
    {
        return $this->effectiveSpaceMappingMode() === self::SPACE_MAPPING_MODE_EXCLUDED;
    }

    public function usesLockedSpaceMapping(): bool
    {
        return in_array($this->effectiveSpaceMappingMode(), [
            self::SPACE_MAPPING_MODE_MANUAL,
            self::SPACE_MAPPING_MODE_EXCLUDED,
        ], true);
    }
}
