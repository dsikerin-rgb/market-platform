<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Market;

class MarketplaceDemoContentService
{
    public function isEnabled(?Market $market = null): bool
    {
        $fallback = (bool) config('marketplace.demo_content_enabled', false);

        if (! $market) {
            return $fallback;
        }

        $settings = (array) (($market->settings ?? [])['marketplace'] ?? []);

        if (! array_key_exists('demo_content_enabled', $settings)) {
            return $fallback;
        }

        return (bool) $settings['demo_content_enabled'];
    }
}
