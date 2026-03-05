<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CabinetLoginFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_open_cabinet_login_page(): void
    {
        $context = $this->createCabinetContext();

        $this->get(route('cabinet.login'))
            ->assertOk()
            ->assertViewIs('cabinet.auth.login')
            ->assertSee((string) $context['market']->name);
    }

    public function test_cabinet_user_is_redirected_from_login_to_dashboard(): void
    {
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            tenantId: (int) $context['tenant']->id,
            role: 'merchant',
        );

        $this->actingAs($user, 'web');

        $this->get(route('cabinet.login'))
            ->assertRedirect(route('cabinet.dashboard'));
    }

    public function test_non_cabinet_user_is_redirected_from_login_to_admin(): void
    {
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'market-admin',
        );

        $this->actingAs($user, 'web');

        $this->get(route('cabinet.login'))
            ->assertRedirect('/admin');
    }

    public function test_merchant_can_login_to_cabinet(): void
    {
        $plainPassword = 'Secret123!';
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            tenantId: (int) $context['tenant']->id,
            role: 'merchant-user',
            password: $plainPassword,
        );

        $this->post(route('cabinet.login.submit'), [
            // Проверяем, что вход работает независимо от регистра email.
            'email' => mb_strtoupper((string) $user->email),
            'password' => $plainPassword,
        ])->assertRedirect(route('cabinet.dashboard'));

        $this->assertAuthenticatedAs($user, 'web');

        $this->get(route('cabinet.dashboard'))->assertOk();
    }

    public function test_login_fails_with_invalid_password(): void
    {
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            tenantId: (int) $context['tenant']->id,
            role: 'merchant',
            password: 'Secret123!',
        );

        $this->post(route('cabinet.login.submit'), [
            'email' => (string) $user->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email']);

        $this->assertGuest('web');

        $this->get(route('cabinet.login'))
            ->assertOk()
            ->assertSee((string) $context['market']->name);
    }

    public function test_login_fails_for_user_without_cabinet_role(): void
    {
        $plainPassword = 'Secret123!';
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            tenantId: (int) $context['tenant']->id,
            role: 'market-admin',
            password: $plainPassword,
        );

        $this->post(route('cabinet.login.submit'), [
            'email' => (string) $user->email,
            'password' => $plainPassword,
        ])->assertSessionHasErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_login_page_uses_market_name_from_entered_email_on_error(): void
    {
        $marketA = Market::create([
            'name' => 'Рынок A',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
        $marketB = Market::create([
            'name' => 'Рынок B',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenantB = Tenant::create([
            'market_id' => (int) $marketB->id,
            'name' => 'Арендатор B',
            'is_active' => true,
        ]);

        $userB = $this->createUser(
            marketId: (int) $marketB->id,
            tenantId: (int) $tenantB->id,
            role: 'merchant',
            password: 'Secret123!',
        );

        $this->post(route('cabinet.login.submit'), [
            'email' => (string) $userB->email,
            'password' => 'wrong-password',
        ])->assertSessionHasErrors(['email']);

        $this->get(route('cabinet.login'))
            ->assertOk()
            ->assertSee('Рынок B')
            ->assertDontSee('Рынок A');
    }

    public function test_login_fails_for_merchant_without_tenant_binding(): void
    {
        $plainPassword = 'Secret123!';
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'merchant',
            password: $plainPassword,
        );

        $this->post(route('cabinet.login.submit'), [
            'email' => (string) $user->email,
            'password' => $plainPassword,
        ])->assertSessionHasErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_login_fails_for_merchant_with_market_mismatch(): void
    {
        $plainPassword = 'Secret123!';
        $marketA = Market::create([
            'name' => 'Рынок A',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
        $marketB = Market::create([
            'name' => 'Рынок B',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenantB = Tenant::create([
            'market_id' => (int) $marketB->id,
            'name' => 'Арендатор B',
            'is_active' => true,
        ]);

        $user = $this->createUser(
            marketId: (int) $marketA->id,
            tenantId: (int) $tenantB->id,
            role: 'merchant',
            password: $plainPassword,
        );

        $this->post(route('cabinet.login.submit'), [
            'email' => (string) $user->email,
            'password' => $plainPassword,
        ])->assertSessionHasErrors(['email']);

        $this->assertGuest('web');
    }

    public function test_authenticated_non_merchant_cannot_open_cabinet_routes(): void
    {
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'market-admin',
        );

        $this->actingAs($user, 'web');

        $this->get(route('cabinet.dashboard'))->assertForbidden();
    }

    public function test_merchant_cannot_access_admin_panel(): void
    {
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            tenantId: (int) $context['tenant']->id,
            role: 'merchant-user',
        );

        $panel = Panel::make()->id('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }

    public function test_market_admin_can_access_admin_panel(): void
    {
        $context = $this->createCabinetContext();
        $user = $this->createUser(
            marketId: (int) $context['market']->id,
            role: 'market-admin',
        );

        $panel = Panel::make()->id('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }

    private function createCabinetContext(): array
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
        ]);

        return compact('market', 'tenant');
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
