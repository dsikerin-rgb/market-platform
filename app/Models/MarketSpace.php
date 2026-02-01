<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class MarketSpace extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'location_id',
        'tenant_id',
        'number',
        'code',
        'display_name',
        'activity_type',
        'area_sqm',
        'rent_rate_value',
        'rent_rate_unit',
        'rent_rate_updated_at',
        'type',
        'status',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
        'rent_rate_value' => 'decimal:2',
        'rent_rate_updated_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $space): void {
            $space->ensureCode();
        });

        static::updating(function (self $space): void {
            // если код пустой — восстановим; если задан руками — не трогаем
            if (blank($space->code)) {
                $space->ensureCode();
            }
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
}
