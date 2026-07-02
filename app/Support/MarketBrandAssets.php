<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MarketBrandAssets
{
    public function defaultMarketplaceLogoUrl(): string
    {
        return asset('marketplace/brand/eko-fair-logo.svg');
    }

    public function defaultFaviconUrl(): string
    {
        return asset('favicon.png');
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function logoUrlForSettings(array $settings): string
    {
        return $this->urlForPath(
            MarketplaceSettingsValue::string($settings['logo_path'] ?? null),
            $this->defaultMarketplaceLogoUrl(),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function faviconUrlForSettings(array $settings): string
    {
        $path = MarketplaceSettingsValue::string($settings['favicon_path'] ?? null);

        if ($path === '') {
            $path = MarketplaceSettingsValue::string($settings['logo_path'] ?? null);
        }

        return $this->urlForPath($path, $this->defaultFaviconUrl());
    }

    public function faviconUrlForMarket(?Market $market): string
    {
        if (! $market) {
            return $this->defaultFaviconUrl();
        }

        return $this->faviconUrlForSettings((array) data_get($market->settings, 'marketplace', []));
    }

    public function faviconUrlForCurrentAdmin(?User $user = null): string
    {
        return $this->faviconUrlForMarket(app(MarketContext::class)->currentMarket($user));
    }

    private function urlForPath(string $path, string $defaultUrl): string
    {
        $value = trim($path);

        if ($value === '') {
            return $defaultUrl;
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:', '/'])) {
            return $value;
        }

        if (! str_contains($value, '..') && ! str_contains($value, '\\') && is_file(public_path($value))) {
            return asset($value);
        }

        return Storage::disk('public')->url($value);
    }
}
