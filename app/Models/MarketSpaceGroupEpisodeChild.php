<?php
# app/Models/MarketSpaceGroupEpisodeChild.php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketSpaceGroupEpisodeChild extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_space_group_episode_id',
        'child_market_space_id',
        'slot',
        'sort_order',
        'area_sqm',
        'meta',
    ];

    protected $casts = [
        'area_sqm' => 'decimal:2',
        'meta' => 'array',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(MarketSpaceGroupEpisode::class, 'market_space_group_episode_id');
    }

    public function childMarketSpace(): BelongsTo
    {
        return $this->belongsTo(MarketSpace::class, 'child_market_space_id');
    }
}
