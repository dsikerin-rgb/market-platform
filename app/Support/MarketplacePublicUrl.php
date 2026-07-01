<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;
use Throwable;

final class MarketplacePublicUrl
{
    public function forMarket(?Market $market): ?string
    {
        if (! $market) {
            return null;
        }

        $routeKey = trim((string) ($market->slug ?? ''));
        if ($routeKey === '') {
            $marketId = (int) ($market->id ?? 0);
            $routeKey = $marketId > 0 ? (string) $marketId : '';
        }

        if ($routeKey === '') {
            return null;
        }

        try {
            return route('marketplace.home', ['marketSlug' => $routeKey]);
        } catch (Throwable) {
            return null;
        }
    }

    public function forCurrentAdmin(?User $user = null): string
    {
        $marketUrl = $this->forMarket(app(MarketContext::class)->currentMarket($user));

        if ($marketUrl !== null) {
            return $marketUrl;
        }

        return route('marketplace.entry');
    }
}
