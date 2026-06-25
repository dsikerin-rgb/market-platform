<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\OneCAccrualPaymentReconciliationWidget;
use App\Models\Market;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class OneCAccrualPaymentReconciliationWidgetMarketContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
    }

    public function test_super_admin_widget_uses_market_context_session_keys(): void
    {
        $market = $this->createMarket('Market A');
        $superAdmin = $this->createSuperAdmin();

        $this->actingAsFilamentUser($superAdmin);
        session(['selected_market_id' => (int) $market->id]);

        self::assertSame((int) $market->id, $this->resolvedMarketId($superAdmin));
    }

    public function test_super_admin_widget_has_no_default_market_without_context(): void
    {
        $this->createMarket('Market A');
        $superAdmin = $this->createSuperAdmin();

        $this->actingAsFilamentUser($superAdmin);

        self::assertNull($this->resolvedMarketId($superAdmin));
    }

    public function test_regular_user_widget_uses_user_market_context(): void
    {
        $userMarket = $this->createMarket('Market A');
        $selectedMarket = $this->createMarket('Market B');
        $user = User::factory()->create([
            'market_id' => (int) $userMarket->id,
        ]);

        $this->actingAsFilamentUser($user);
        session(['selected_market_id' => (int) $selectedMarket->id]);

        self::assertSame((int) $userMarket->id, $this->resolvedMarketId($user));
    }

    private function resolvedMarketId(User $user): ?int
    {
        $method = new ReflectionMethod(OneCAccrualPaymentReconciliationWidget::class, 'resolveMarketIdForWidget');
        $method->setAccessible(true);

        return $method->invoke(new OneCAccrualPaymentReconciliationWidget, $user);
    }

    private function createMarket(string $name): Market
    {
        return Market::query()->create([
            'name' => $name,
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function createSuperAdmin(): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => null,
            'email' => 'super-admin-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole('super-admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function actingAsFilamentUser(User $user): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user, Filament::getAuthGuard());
    }
}
