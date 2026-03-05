<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Market;

class MarketplaceContextService
{
    public function resolveMarket(?string $marketSlug = null): ?Market
    {
        $marketSlug = trim((string) $marketSlug);

        if ($marketSlug !== '') {
            $bySlug = Market::query()
                ->where('slug', $marketSlug)
                ->where('is_active', true)
                ->first();

            if ($bySlug) {
                return $bySlug;
            }

            if (is_numeric($marketSlug)) {
                return Market::query()
                    ->whereKey((int) $marketSlug)
                    ->where('is_active', true)
                    ->first();
            }

            return null;
        }

        return Market::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }
}
