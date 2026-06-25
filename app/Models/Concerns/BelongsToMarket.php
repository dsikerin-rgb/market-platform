<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Market;
use App\Models\Scopes\MarketScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

trait BelongsToMarket
{
    protected static function bootBelongsToMarket(): void
    {
        static::addGlobalScope(new MarketScope);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class, $this->marketScopeColumn());
    }

    public function scopeForMarket(Builder $query, Market|int $market): Builder
    {
        $marketId = $market instanceof Market ? (int) $market->getKey() : (int) $market;

        if ($marketId <= 0) {
            throw new InvalidArgumentException('Market id must be a positive integer.');
        }

        return $query->where($this->qualifyColumn($this->marketScopeColumn()), $marketId);
    }

    public function scopeWithoutMarketScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(MarketScope::class);
    }

    public function marketScopeColumn(): string
    {
        return 'market_id';
    }
}
