<?php
# app/Models/MarketSpaceGroupEpisode.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketSpaceGroupEpisode extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'parent_market_space_id',
        'valid_from',
        'valid_to',
        'source',
        'source_contract_id',
        'notes',
        'meta',
        'created_by_user_id',
    ];

    protected $casts = [
        'valid_from' => 'date',
        'valid_to' => 'date',
        'meta' => 'array',
    ];

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function parentMarketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class, 'parent_market_space_id');
    }

    public function sourceContract(): BelongsTo
    {
        return $this->belongsTo(TenantContract::class, 'source_contract_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MarketSpaceGroupEpisodeChild::class);
    }
}
