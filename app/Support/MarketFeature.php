<?php

namespace App\Support;

use App\Models\Market;
use Filament\Facades\Filament;

class MarketFeature
{
    public static function enabled(string $feature, ?int $marketId = null): bool
    {
        $marketId = $marketId ?? static::resolveMarketId();

        if (! $marketId) {
            return true;
        }

        $market = Market::query()->find($marketId);

        if (! $market) {
            return false;
        }

        $features = $market->features ?? [];

        if (! is_array($features)) {
            return false;
        }

        if (! array_key_exists($feature, $features)) {
            return true;
        }

        return (bool) $features[$feature];
    }

    private static function resolveMarketId(): ?int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            $selected = session('filament.admin.selected_market_id');

            return filled($selected) ? (int) $selected : null;
        }

        return $user->market_id ? (int) $user->market_id : null;
    }
}
