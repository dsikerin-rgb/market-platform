<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSpace extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'location_id',
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

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MarketLocation::class, 'location_id');
    }
}
