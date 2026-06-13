<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MarketSettings;
use App\Filament\Pages\OneCDebtDecisionPreview;
use App\Filament\Pages\OneCReconciliation;
use App\Filament\Pages\OneCSettlements;
use App\Filament\Pages\ReportsHub;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\MarketSpaceTypeResource;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\User;
use App\Support\AdminCapabilities;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminCapabilitiesAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider marketDirectoryManagerRoles
     */
    public function test_market_directory_manager_roles_can_manage_places_tenants_and_place_types(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $space = $this->createMarketSpace($market);
        $tenant = $this->createTenant($market);
        $spaceType = $this->createMarketSpaceType($market);

        $this->actingAsFilamentUser($user);

        self::assertTrue(MarketSpaceResource::canCreate());
        self::assertTrue(MarketSpaceResource::canEdit($space));
        self::assertTrue(TenantResource::canCreate());
        self::assertTrue(TenantResource::canEdit($tenant));
        self::assertTrue(MarketSpaceTypeResource::canCreate());
        self::assertTrue(MarketSpaceTypeResource::canEdit($spaceType));
        self::assertTrue(MarketSpaceTypeResource::canDelete($spaceType));
    }

    /**
     * @dataProvider restrictedOperationalRoles
     */
    public function test_operational_roles_cannot_manage_places_tenants_or_place_types(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $space = $this->createMarketSpace($market);
        $tenant = $this->createTenant($market);
        $spaceType = $this->createMarketSpaceType($market);

        $this->actingAsFilamentUser($user);

        self::assertFalse(AdminCapabilities::canManageMarketDirectory($user));
        self::assertTrue(MarketSpaceResource::canViewAny());
        self::assertTrue(TenantResource::canViewAny());
        self::assertTrue(MarketSpaceTypeResource::canViewAny());

        self::assertFalse(MarketSpaceResource::canCreate());
        self::assertFalse(MarketSpaceResource::canEdit($space));
        self::assertFalse(TenantResource::canCreate());
        self::assertFalse(TenantResource::canEdit($tenant));
        self::assertFalse(MarketSpaceTypeResource::canCreate());
        self::assertFalse(MarketSpaceTypeResource::canEdit($spaceType));
        self::assertFalse(MarketSpaceTypeResource::canDelete($spaceType));
    }

    /**
     * @dataProvider financeViewerRoles
     */
    public function test_finance_roles_can_view_1c_finance_pages(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $accrual = $this->createTenantAccrual($market);

        $this->actingAsFilamentUser($user);

        self::assertTrue(OneCReconciliation::canAccess());
        self::assertTrue(OneCSettlements::canAccess());
        self::assertTrue(OneCDebtDecisionPreview::canAccess());
        self::assertTrue(ReportsHub::canAccess());
        self::assertTrue(TenantAccrualResource::canViewAny());
        self::assertTrue(TenantAccrualResource::canEdit($accrual));
    }

    /**
     * @dataProvider restrictedOperationalRoles
     */
    public function test_operational_roles_cannot_view_1c_finance_pages(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $accrual = $this->createTenantAccrual($market);

        $this->actingAsFilamentUser($user);

        self::assertFalse(AdminCapabilities::canViewFinance($user));
        self::assertFalse(OneCReconciliation::canAccess());
        self::assertFalse(OneCSettlements::canAccess());
        self::assertFalse(OneCDebtDecisionPreview::canAccess());
        self::assertFalse(ReportsHub::canAccess());
        self::assertFalse(TenantAccrualResource::canViewAny());
        self::assertFalse(TenantAccrualResource::canEdit($accrual));
    }

    public function test_market_settings_update_permission_opens_market_settings(): void
    {
        $market = $this->createMarket();
        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-settings-update@example.test',
        ]);

        Permission::findOrCreate('market-settings.update', 'web');
        $user->givePermissionTo('market-settings.update');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAsFilamentUser($user);

        self::assertTrue(MarketSettings::canAccess());
        self::assertTrue(AdminCapabilities::canUpdateMarketSettings($user));
    }

    public static function marketDirectoryManagerRoles(): array
    {
        return [
            'market-admin' => ['market-admin'],
            'market-manager' => ['market-manager'],
        ];
    }

    public static function financeViewerRoles(): array
    {
        return [
            'market-admin' => ['market-admin'],
            'market-manager' => ['market-manager'],
            'market-accountant' => ['market-accountant'],
            'market-finance' => ['market-finance'],
        ];
    }

    public static function restrictedOperationalRoles(): array
    {
        return [
            'market-guard' => ['market-guard'],
            'market-security' => ['market-security'],
            'market-maintenance' => ['market-maintenance'],
            'market-marketing' => ['market-marketing'],
            'staff' => ['staff'],
        ];
    }

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function actingAsFilamentUser(User $user): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user, Filament::getAuthGuard());
    }

    private function createMarketUser(Market $market, string $roleName): User
    {
        Role::findOrCreate($roleName, 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => $roleName . '-' . uniqid('', true) . '@example.test',
        ]);
        $user->assignRole($roleName);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function createMarketSpace(Market $market): MarketSpace
    {
        return MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'P1',
            'status' => 'free',
            'is_active' => true,
        ]);
    }

    private function createTenant(Market $market): Tenant
    {
        return Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);
    }

    private function createMarketSpaceType(Market $market): MarketSpaceType
    {
        return MarketSpaceType::query()->create([
            'market_id' => (int) $market->id,
            'name_ru' => 'Витрина',
            'code' => 'vitrina',
            'unit' => 'unit',
            'is_active' => true,
        ]);
    }

    private function createTenantAccrual(Market $market): TenantAccrual
    {
        return TenantAccrual::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $this->createTenant($market)->id,
            'period' => '2026-06-01',
            'source' => '1c',
            'source_file' => '1c:accruals',
            'total_with_vat' => 1000,
        ]);
    }
}
