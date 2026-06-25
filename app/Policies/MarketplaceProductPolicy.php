<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MarketplaceProduct;
use App\Models\User;
use App\Support\AdminCapabilities;

class MarketplaceProductPolicy
{
    public function viewAny(User $user): bool
    {
        return AdminCapabilities::canViewMarketplaceContent($user);
    }

    public function view(User $user, MarketplaceProduct $product): bool
    {
        return AdminCapabilities::canViewMarketplaceContent($user, (int) $product->market_id);
    }

    public function create(User $user): bool
    {
        return AdminCapabilities::canManageMarketplaceContent($user);
    }

    public function update(User $user, MarketplaceProduct $product): bool
    {
        return AdminCapabilities::canManageMarketplaceContent($user, (int) $product->market_id);
    }

    public function updateContent(User $user, MarketplaceProduct $product): bool
    {
        return $this->update($user, $product);
    }

    public function updateMedia(User $user, MarketplaceProduct $product): bool
    {
        return $this->update($user, $product);
    }

    public function publish(User $user, MarketplaceProduct $product): bool
    {
        return $this->update($user, $product);
    }

    public function delete(User $user, MarketplaceProduct $product): bool
    {
        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return method_exists($user, 'isMarketAdmin')
            && $user->isMarketAdmin()
            && (int) ($user->market_id ?? 0) === (int) $product->market_id;
    }
}
