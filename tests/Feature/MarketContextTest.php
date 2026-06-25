<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\User;
use App\Support\MarketContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MarketContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_user_market_id_is_current_context(): void
    {
        $market = $this->createMarket('Market A');
        $user = User::factory()->create([
            'market_id' => (int) $market->id,
        ]);

        $this->actingAsFilamentUser($user);

        $context = app(MarketContext::class);

        self::assertSame((int) $market->id, $context->currentMarketId());
        self::assertTrue($context->currentMarket()?->is($market));
        self::assertFalse($context->scopeEnabled());
        self::assertFalse($context->writeGuardsEnabled());
        self::assertFalse($context->strictMissingContext());
        self::assertTrue($context->shadowMode());
    }

    public function test_super_admin_selected_market_session_is_current_context(): void
    {
        $firstMarket = $this->createMarket('Market A');
        $selectedMarket = $this->createMarket('Market B');
        $superAdmin = $this->createSuperAdmin();

        $this->actingAsFilamentUser($superAdmin);
        session([
            'dashboard_market_id' => (int) $selectedMarket->id,
            'filament.admin.selected_market_id' => (int) $firstMarket->id,
        ]);

        $context = app(MarketContext::class);

        self::assertSame((int) $selectedMarket->id, $context->selectedMarketIdFromSession());
        self::assertSame((int) $selectedMarket->id, $context->currentMarketId());
    }

    public function test_super_admin_has_no_fallback_market_by_default(): void
    {
        $this->createMarket('Market A');
        $superAdmin = $this->createSuperAdmin();

        $this->actingAsFilamentUser($superAdmin);

        self::assertNull(app(MarketContext::class)->currentMarketId());
    }

    public function test_super_admin_fallback_can_use_first_market_by_name(): void
    {
        $secondByName = $this->createMarket('B Market');
        $firstByName = $this->createMarket('A Market');
        $superAdmin = $this->createSuperAdmin();

        config()->set('market_context.super_admin_fallback', 'first_by_name');

        $this->actingAsFilamentUser($superAdmin);

        self::assertSame((int) $firstByName->id, app(MarketContext::class)->currentMarketId());
        self::assertNotSame((int) $secondByName->id, app(MarketContext::class)->currentMarketId());
    }

    public function test_with_market_overrides_and_restores_context(): void
    {
        $firstMarket = $this->createMarket('Market A');
        $secondMarket = $this->createMarket('Market B');
        $context = app(MarketContext::class);

        self::assertNull($context->currentMarketId());

        $result = $context->withMarket($firstMarket, function () use ($context, $firstMarket, $secondMarket): array {
            $outer = $context->currentMarketId();

            $inner = $context->withMarket((int) $secondMarket->id, fn (): ?int => $context->currentMarketId());

            return [
                'outer' => $outer,
                'inner' => $inner,
                'restored' => $context->currentMarketId(),
                'required' => $context->requireMarketId(),
                'market' => $context->currentMarket()?->id,
                'expected' => (int) $firstMarket->id,
            ];
        });

        self::assertSame((int) $firstMarket->id, $result['outer']);
        self::assertSame((int) $secondMarket->id, $result['inner']);
        self::assertSame((int) $firstMarket->id, $result['restored']);
        self::assertSame((int) $firstMarket->id, $result['required']);
        self::assertSame((int) $firstMarket->id, $result['market']);
        self::assertSame((int) $firstMarket->id, $result['expected']);
        self::assertNull($context->currentMarketId());
    }

    public function test_require_market_id_fails_without_context(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Market context is not available.');

        app(MarketContext::class)->requireMarketId();
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
