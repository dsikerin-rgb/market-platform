<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CabinetImpersonationAudit;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CabinetImpersonationFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_issue_and_consume_one_time_impersonation_link(): void
    {
        $context = $this->createTenantWithCabinetUser();
        $admin = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'super-admin',
        );

        $this->actingAs($admin, 'web');

        $response = $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $context['tenant']->id,
        ]));

        $response->assertRedirect();

        $impersonationUrl = (string) $response->headers->get('Location');
        $this->assertNotSame('', $impersonationUrl);
        $this->assertStringContainsString('/cabinet/impersonate/', $impersonationUrl);

        $consume = $this->get($impersonationUrl);
        $consume->assertRedirect(route('cabinet.dashboard'));
        $this->assertAuthenticatedAs($context['cabinetUser'], 'web');

        $audit = CabinetImpersonationAudit::query()->firstOrFail();
        $this->assertSame(CabinetImpersonationAudit::STATUS_ACTIVE, $audit->status);
        $this->assertNotNull($audit->started_at);

        $reuse = $this->get($impersonationUrl);
        $reuse->assertForbidden();
    }

    public function test_market_admin_can_impersonate_tenant_from_own_market(): void
    {
        $context = $this->createTenantWithCabinetUser();
        $admin = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'market-admin',
        );

        $this->actingAs($admin, 'web');

        $response = $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $context['tenant']->id,
        ]));

        $response->assertRedirect();
        $this->get((string) $response->headers->get('Location'))
            ->assertRedirect(route('cabinet.dashboard'));

        $this->assertAuthenticatedAs($context['cabinetUser'], 'web');
    }

    public function test_impersonation_creates_primary_cabinet_user_when_missing(): void
    {
        $market = $this->createMarket('Тестовый рынок');
        $tenant = $this->createTenant($market, 'Без кабинета');
        User::query()->where('tenant_id', (int) $tenant->id)->delete();

        $admin = $this->createUser(
            marketId: (int) $market->id,
            role: 'super-admin',
        );

        $this->actingAs($admin, 'web');

        $response = $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $tenant->id,
        ]));

        $response->assertRedirect();
        $this->get((string) $response->headers->get('Location'))
            ->assertRedirect(route('cabinet.dashboard'));

        $primary = User::query()->where('tenant_id', (int) $tenant->id)->orderBy('id')->first();
        $this->assertNotNull($primary);
        $this->assertTrue((bool) $primary?->hasAnyRole(['merchant', 'merchant-user']));
    }

    public function test_market_admin_cannot_impersonate_tenant_from_other_market(): void
    {
        $marketA = $this->createMarket('Market A');
        $marketB = $this->createMarket('Market B');
        $tenantB = $this->createTenant($marketB, 'Tenant B');
        $this->createCabinetUserForTenant($tenantB);
        $admin = $this->createUser(marketId: (int) $marketA->id, role: 'market-admin');

        $this->actingAs($admin, 'web');

        $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $tenantB->id,
        ]))->assertForbidden();

        $audit = CabinetImpersonationAudit::query()->latest('id')->firstOrFail();
        $this->assertSame(CabinetImpersonationAudit::STATUS_DENIED, $audit->status);
        $this->assertSame('cross_market_denied', $audit->reason);
    }

    public function test_merchant_cannot_call_admin_impersonation_endpoint(): void
    {
        $context = $this->createTenantWithCabinetUser();
        $merchant = $context['cabinetUser'];
        $this->actingAs($merchant, 'web');

        $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $context['tenant']->id,
        ]))->assertForbidden();
    }

    public function test_expired_token_cannot_be_consumed(): void
    {
        $context = $this->createTenantWithCabinetUser();
        $admin = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'super-admin',
        );

        $this->actingAs($admin, 'web');

        $response = $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $context['tenant']->id,
        ]));
        $response->assertRedirect();
        $impersonationUrl = (string) $response->headers->get('Location');

        $this->travel(3)->minutes();

        $this->get($impersonationUrl)->assertForbidden();
    }

    public function test_exit_returns_back_to_admin_and_closes_audit(): void
    {
        $context = $this->createTenantWithCabinetUser();
        $admin = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'super-admin',
        );

        $this->actingAs($admin, 'web');

        $response = $this->post(route('filament.admin.tenants.cabinet-impersonate', [
            'tenant' => (int) $context['tenant']->id,
        ]));
        $this->get((string) $response->headers->get('Location'))
            ->assertRedirect(route('cabinet.dashboard'));

        $exit = $this->post(route('cabinet.impersonation.exit'));
        $exit->assertRedirect(url('/admin/tenants/' . (int) $context['tenant']->id . '/edit'));
        $this->assertAuthenticatedAs($admin, 'web');

        $audit = CabinetImpersonationAudit::query()->firstOrFail();
        $this->assertSame(CabinetImpersonationAudit::STATUS_ENDED, $audit->status);
        $this->assertNotNull($audit->ended_at);
    }

    public function test_super_admin_still_sees_impersonation_button_on_admin_page_during_impersonation(): void
    {
        $context = $this->createTenantWithCabinetUser();
        $admin = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'super-admin',
        );

        $this->actingAs($context['cabinetUser'], 'web');

        $response = $this
            ->withSession([
                \App\Services\Cabinet\TenantImpersonationService::SESSION_KEY => [
                    'impersonator_user_id' => (int) $admin->id,
                    'tenant_id' => (int) $context['tenant']->id,
                ],
            ])
            ->get('/admin/tenants/' . (int) $context['tenant']->id . '/edit');

        $response->assertOk();
        $response->assertSee('Войти в кабинет', false);
    }

    /**
     * @return array{market: Market, tenant: Tenant, cabinetUser: User}
     */
    private function createTenantWithCabinetUser(): array
    {
        $market = $this->createMarket('Тестовый рынок');
        $tenant = $this->createTenant($market, 'Тестовый арендатор');
        $cabinetUser = $this->createCabinetUserForTenant($tenant);

        return compact('market', 'tenant', 'cabinetUser');
    }

    private function createMarket(string $name): Market
    {
        return Market::query()->create([
            'name' => $name,
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function createTenant(Market $market, string $name): Tenant
    {
        return Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    private function createCabinetUserForTenant(Tenant $tenant): User
    {
        $user = User::query()
            ->where('tenant_id', (int) $tenant->id)
            ->orderBy('id')
            ->first();

        if ($user) {
            Role::findOrCreate('merchant', 'web');
            if (! $user->hasAnyRole(['merchant', 'merchant-user'])) {
                $user->assignRole('merchant');
            }

            return $user;
        }

        return $this->createUser(
            marketId: (int) $tenant->market_id,
            tenantId: (int) $tenant->id,
            role: 'merchant',
        );
    }

    private function createUser(
        int $marketId,
        ?int $tenantId = null,
        string $role = 'merchant',
        string $password = 'password'
    ): User {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'password' => Hash::make($password),
        ]);

        $user->assignRole($role);

        return $user;
    }
}
