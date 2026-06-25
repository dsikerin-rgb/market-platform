<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MarketplaceChat;
use App\Models\MarketplaceProduct;
use App\Models\User;
use App\Policies\MarketplaceChatPolicy;
use App\Policies\MarketplaceProductPolicy;
use App\Support\AdminCapabilities;
use PHPUnit\Framework\TestCase;

class MarketplaceMarketingAccessTest extends TestCase
{
    public function test_marketing_role_can_manage_marketplace_content_only_in_own_market_without_finance(): void
    {
        $user = $this->user(['market-marketing'], marketId: 1);
        $product = $this->product(marketId: 1);
        $otherMarketProduct = $this->product(marketId: 2);
        $policy = new MarketplaceProductPolicy();

        self::assertTrue(AdminCapabilities::canManageMarketplaceContent($user, 1));
        self::assertTrue(AdminCapabilities::canViewMarketplaceOrders($user, 1));
        self::assertTrue(AdminCapabilities::canViewTenantMarketplaceContacts($user, 1));
        self::assertFalse(AdminCapabilities::canViewFinance($user, 1));
        self::assertFalse(AdminCapabilities::canViewTenantContracts($user, 1));

        self::assertTrue($policy->view($user, $product));
        self::assertTrue($policy->updateContent($user, $product));
        self::assertTrue($policy->updateMedia($user, $product));
        self::assertTrue($policy->publish($user, $product));
        self::assertFalse($policy->delete($user, $product));
        self::assertFalse($policy->update($user, $otherMarketProduct));
    }

    public function test_advertising_role_can_reply_to_marketplace_chats_only_in_own_market(): void
    {
        $user = $this->user(['market-advertising'], marketId: 1);
        $chat = $this->chat(marketId: 1);
        $otherMarketChat = $this->chat(marketId: 2);
        $policy = new MarketplaceChatPolicy();

        self::assertTrue($policy->view($user, $chat));
        self::assertTrue($policy->reply($user, $chat));
        self::assertTrue($policy->updateStatus($user, $chat));
        self::assertFalse($policy->view($user, $otherMarketChat));
        self::assertFalse($policy->reply($user, $otherMarketChat));
    }

    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function user(array $roles, int $marketId, array $permissions = []): User
    {
        $user = new class extends User {
            /** @var list<string> */
            public array $roleNames = [];

            /** @var list<string> */
            public array $permissionNames = [];

            public function isSuperAdmin(): bool
            {
                return in_array('super-admin', $this->roleNames, true);
            }

            public function isMarketAdmin(): bool
            {
                return in_array('market-admin', $this->roleNames, true);
            }

            public function hasRole($roles, ?string $guard = null): bool
            {
                foreach ((array) $roles as $role) {
                    if (in_array((string) $role, $this->roleNames, true)) {
                        return true;
                    }
                }

                return false;
            }

            public function hasAnyRole(...$roles): bool
            {
                $flat = [];

                foreach ($roles as $role) {
                    array_push($flat, ...(array) $role);
                }

                return $this->hasRole($flat);
            }

            public function can($abilities, $arguments = [])
            {
                return in_array((string) $abilities, $this->permissionNames, true);
            }
        };

        $user->roleNames = $roles;
        $user->permissionNames = $permissions;
        $user->setRawAttributes([
            'id' => 1,
            'market_id' => $marketId,
        ], true);

        return $user;
    }

    private function product(int $marketId): MarketplaceProduct
    {
        $product = new MarketplaceProduct();
        $product->setRawAttributes(['market_id' => $marketId], true);

        return $product;
    }

    private function chat(int $marketId): MarketplaceChat
    {
        $chat = new MarketplaceChat();
        $chat->setRawAttributes(['market_id' => $marketId], true);

        return $chat;
    }
}
