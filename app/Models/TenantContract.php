<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantContract extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'tenant_id',
        'market_space_id',
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
}
