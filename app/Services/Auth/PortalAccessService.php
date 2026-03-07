<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Market;
use App\Models\TenantContract;
use App\Models\User;

class PortalAccessService
{
    public const SESSION_ACTIVE_MODE = 'portal.active_mode';

    public const MODE_BUYER = 'buyer';

    public const MODE_SELLER = 'seller';

    public function canUseSellerCabinet(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $hasRoleAccess = method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['merchant', 'merchant-user']);

        if (! $hasRoleAccess || ! $user->tenant_id) {
            return false;
        }

        $tenant = $user->tenant;
        if (! $tenant) {
            return false;
        }

        return ! ($user->market_id && $tenant->market_id && (int) $user->market_id !== (int) $tenant->market_id);
    }

    public function canUseMarketplaceBuyer(?User $user, ?Market $market = null): bool
    {
        if (! $user) {
            return false;
        }

        $hasBuyerRole = method_exists($user, 'hasRole') && $user->hasRole('buyer');
        $canUseSeller = $this->canUseSellerCabinet($user);

        if (! $hasBuyerRole && ! $canUseSeller) {
            return false;
        }

        if (! $market) {
            return true;
        }

        return $this->isInMarket($user, $market);
    }

    public function defaultMode(?User $user, ?Market $market = null): ?string
    {
        if (! $user) {
            return null;
        }

        if ($this->canUseSellerCabinet($user) && (! $market || $this->isInMarket($user, $market))) {
            return self::MODE_SELLER;
        }

        if ($this->canUseMarketplaceBuyer($user, $market)) {
            return self::MODE_BUYER;
        }

        return null;
    }

    public function canSellOnMarketplace(?User $user, ?Market $market = null): bool
    {
        if (! $this->canUseSellerCabinet($user) || ! $user) {
            return false;
        }

        $resolvedMarket = $market;
        if (! $resolvedMarket) {
            $resolvedMarket = $this->resolveUserMarket($user);
        }

        $tenantId = (int) ($user->tenant_id ?? 0);
        $marketId = (int) ($resolvedMarket?->id ?? 0);

        if ($tenantId <= 0 || $marketId <= 0) {
            return false;
        }

        if ($this->allowsPublicSalesWithoutActiveContract($resolvedMarket)) {
            return true;
        }

        return TenantContract::query()
            ->where('tenant_id', $tenantId)
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->exists();
    }

    public function allowsPublicSalesWithoutActiveContract(?Market $market): bool
    {
        $fallback = (bool) config('marketplace.contracts.allow_public_sales_without_active_contracts', false);

        if (! $market) {
            return $fallback;
        }

        $settings = (array) (($market->settings ?? [])['marketplace'] ?? []);

        if (! array_key_exists('allow_public_sales_without_active_contracts', $settings)) {
            return $fallback;
        }

        return (bool) $settings['allow_public_sales_without_active_contracts'];
    }

    public function resolveUserMarket(?User $user): ?Market
    {
        if (! $user) {
            return null;
        }

        $marketId = (int) ($user->market_id ?? 0);
        if ($marketId <= 0) {
            $marketId = (int) ($user->tenant?->market_id ?? 0);
        }

        if ($marketId <= 0) {
            return null;
        }

        return Market::query()
            ->select(['id', 'name', 'slug'])
            ->whereKey($marketId)
            ->first();
    }

    public function resolveUserMarketRouteKey(?User $user): ?string
    {
        $market = $this->resolveUserMarket($user);
        if (! $market) {
            return null;
        }

        return filled($market->slug) ? (string) $market->slug : (string) $market->id;
    }

    public function isDualCapable(?User $user, ?Market $market = null): bool
    {
        return $this->canUseSellerCabinet($user) && $this->canUseMarketplaceBuyer($user, $market);
    }

    public function isInMarket(?User $user, ?Market $market): bool
    {
        if (! $user || ! $market) {
            return false;
        }

        $userMarket = $this->resolveUserMarket($user);

        return $userMarket && (int) $userMarket->id === (int) $market->id;
    }
}
