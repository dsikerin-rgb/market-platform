<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\MapReviewDataQualitySignalsWidget;
use App\Models\Market;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MapReviewDataQualitySignalsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_widget_uses_market_context_session_keys(): void
    {
        $market = $this->createMarket('Market A');
        $superAdmin = $this->createSuperAdmin();

        $this->actingAsFilamentUser($superAdmin);
        session(['selected_market_id' => (int) $market->id]);

        self::assertSame((int) $market->id, $this->selectedMarketId());
    }

    public function test_super_admin_widget_has_no_default_market_without_context(): void
    {
        $this->createMarket('Market A');
        $superAdmin = $this->createSuperAdmin();

        $this->actingAsFilamentUser($superAdmin);

        self::assertSame(0, $this->selectedMarketId());
    }

    private function selectedMarketId(): int
    {
        $method = new ReflectionMethod(MapReviewDataQualitySignalsWidget::class, 'selectedMarketId');
        $method->setAccessible(true);

        return (int) $method->invoke(new MapReviewDataQualitySignalsWidget);
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
