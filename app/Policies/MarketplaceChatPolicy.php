<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MarketplaceChat;
use App\Models\User;
use App\Support\AdminCapabilities;

class MarketplaceChatPolicy
{
    public function viewAny(User $user): bool
    {
        return AdminCapabilities::canViewMarketplaceOrders($user);
    }

    public function view(User $user, MarketplaceChat $chat): bool
    {
        return AdminCapabilities::canViewMarketplaceOrders($user, (int) $chat->market_id);
    }

    public function updateStatus(User $user, MarketplaceChat $chat): bool
    {
        return AdminCapabilities::canManageMarketplaceOrders($user, (int) $chat->market_id);
    }

    public function reply(User $user, MarketplaceChat $chat): bool
    {
        return AdminCapabilities::canManageMarketplaceOrders($user, (int) $chat->market_id);
    }
}
