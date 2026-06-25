<?php

namespace Tests\Feature;

use App\Filament\Widgets\RevenueYearChartWidget;
use App\Models\Market;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RevenueYearChartWidgetMarketContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
    }

    public function test_super_admin_widget_uses_market_context_session_keys(): void
    {
        $firstMarket = $this->createMarket('Основной рынок');
        $selectedMarket = $this->createMarket('Выбранный рынок');
        $user = $this->createSuperAdmin($firstMarket);

        $this->actingAsFilamentUser($user);

        session(['filament.admin.selected_market_id' => $selectedMarket->id]);

        $this->assertSame($selectedMarket->id, $this->resolvedMarketId($user));
    }

    public function test_super_admin_widget_has_no_default_market_without_context(): void
    {
        $market = $this->createMarket('Основной рынок');
        $user = $this->createSuperAdmin($market);

        $this->actingAsFilamentUser($user);

        $this->assertNull($this->resolvedMarketId($user));
    }

    public function test_regular_user_widget_uses_user_market_context(): void
    {
        $market = $this->createMarket('Основной рынок');
        $user = User::factory()->create([
            'market_id' => $market->id,
        ]);

        $this->actingAsFilamentUser($user);

        $this->assertSame($market->id, $this->resolvedMarketId($user));
    }

    private function resolvedMarketId(User $user): ?int
    {
        $method = new ReflectionMethod(RevenueYearChartWidget::class, 'resolveMarketIdForWidget');
        $method->setAccessible(true);

        return $method->invoke(new RevenueYearChartWidget(), $user);
    }

    private function createMarket(string $name): Market
    {
        return Market::query()->create([
            'name' => $name,
            'city' => 'Новосибирск',
            'address' => "{$name}, 1",
        ]);
    }

    private function createSuperAdmin(Market $market): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::query()->firstOrCreate([
            'name' => 'super_admin',
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create([
            'market_id' => $market->id,
        ]);

        $user->assignRole($role);

        return $user;
    }

    private function actingAsFilamentUser(User $user): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user);
    }
}
