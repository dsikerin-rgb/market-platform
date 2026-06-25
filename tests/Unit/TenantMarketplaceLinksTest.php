<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantMarketplaceLinks;
use Tests\TestCase;

class TenantMarketplaceLinksTest extends TestCase
{
    public function test_marketplace_role_can_open_own_market_tenant_storefront(): void
    {
        $user = $this->user(['market-marketing'], marketId: 1);
        $tenant = $this->tenant(marketId: 1, tenantSlug: 'tenant-a', marketSlug: 'market-a');

        self::assertTrue(TenantMarketplaceLinks::canOpenStore($user, $tenant));
        self::assertSame(
            route('marketplace.store.show', ['marketSlug' => 'market-a', 'tenantSlug' => 'tenant-a']),
            TenantMarketplaceLinks::storeUrl($tenant),
        );
    }

    public function test_marketplace_role_cannot_open_other_market_tenant_storefront(): void
    {
        $user = $this->user(['market-advertising'], marketId: 1);
        $tenant = $this->tenant(marketId: 2, tenantSlug: 'tenant-b', marketSlug: 'market-b');

        self::assertFalse(TenantMarketplaceLinks::canOpenStore($user, $tenant));
    }

    public function test_storefront_link_is_hidden_without_tenant_slug(): void
    {
        $user = $this->user(['market-marketing'], marketId: 1);
        $tenant = $this->tenant(marketId: 1, tenantSlug: '', marketSlug: 'market-a');

        self::assertFalse(TenantMarketplaceLinks::canOpenStore($user, $tenant));
        self::assertNull(TenantMarketplaceLinks::storeUrl($tenant));
    }

    /**
     * @param list<string> $roles
     */
    private function user(array $roles, int $marketId): User
    {
        $user = new class extends User {
            /** @var list<string> */
            public array $roleNames = [];

            public function isSuperAdmin(): bool
            {
                return in_array('super-admin', $this->roleNames, true);
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
                return false;
            }
        };

        $user->roleNames = $roles;
        $user->setRawAttributes([
            'id' => 1,
            'market_id' => $marketId,
        ], true);

        return $user;
    }

    private function tenant(int $marketId, string $tenantSlug, string $marketSlug): Tenant
    {
        $tenant = new Tenant();
        $tenant->setRawAttributes([
            'id' => 10,
            'market_id' => $marketId,
            'slug' => $tenantSlug,
        ], true);

        $market = new Market();
        $market->setRawAttributes([
            'id' => $marketId,
            'slug' => $marketSlug,
        ], true);

        $tenant->setRelation('market', $market);

        return $tenant;
    }
}
