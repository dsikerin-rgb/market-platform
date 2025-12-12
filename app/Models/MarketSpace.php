<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Models\MarketSpaceType;

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
        'area_sqm',
        'type',
        'status',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
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
        return $this->belongsTo(MarketSpaceType::class, 'type', 'code')
            ->whereColumn('market_space_types.market_id', 'market_spaces.market_id');
    }
}
