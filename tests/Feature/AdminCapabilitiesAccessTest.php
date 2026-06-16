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
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
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
     * @dataProvider serviceTenantViewerRoles
     */
    public function test_service_roles_can_view_tenants_without_managing_places_tenants_or_place_types(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $space = $this->createMarketSpace($market);
        $tenant = $this->createTenant($market);
        $spaceType = $this->createMarketSpaceType($market);

        $this->actingAsFilamentUser($user);

        self::assertFalse(AdminCapabilities::canManageMarketDirectory($user));
        self::assertTrue(AdminCapabilities::canViewTenantServiceContext($user));
        self::assertTrue(MarketSpaceResource::canViewAny());
        self::assertTrue(MarketSpaceResource::canView($space));
        self::assertTrue(TenantResource::canViewAny());
        self::assertTrue(TenantResource::canView($tenant));
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
     * @dataProvider noTenantDirectoryRoles
     */
    public function test_roles_without_tenant_context_cannot_view_tenant_directory(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $tenant = $this->createTenant($market);

        $this->actingAsFilamentUser($user);

        self::assertTrue(MarketSpaceResource::canViewAny());
        self::assertTrue(MarketSpaceTypeResource::canViewAny());
        self::assertFalse(AdminCapabilities::canViewTenantDirectory($user));
        self::assertFalse(TenantResource::canViewAny());
        self::assertFalse(TenantResource::canView($tenant));
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

    public function test_owner_can_view_directory_finance_and_contracts_without_operational_management(): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, 'market-owner');
        $space = $this->createMarketSpace($market);
        $tenant = $this->createTenant($market);
        $spaceType = $this->createMarketSpaceType($market);
        $contract = $this->createTenantContract($market);

        $this->actingAsFilamentUser($user);

        self::assertTrue(MarketSpaceResource::canViewAny());
        self::assertTrue(MarketSpaceResource::canView($space));
        self::assertTrue(TenantResource::canViewAny());
        self::assertTrue(MarketSpaceTypeResource::canViewAny());
        self::assertTrue(AdminCapabilities::canViewFinance($user));
        self::assertTrue(TenantContractResource::canViewAny());
        self::assertTrue(TenantContractResource::canEdit($contract));

        self::assertFalse(MarketSpaceResource::canCreate());
        self::assertFalse(MarketSpaceResource::canEdit($space));
        self::assertFalse(TenantResource::canCreate());
        self::assertFalse(TenantResource::canEdit($tenant));
        self::assertFalse(MarketSpaceTypeResource::canCreate());
        self::assertFalse(MarketSpaceTypeResource::canEdit($spaceType));
        self::assertFalse(AdminCapabilities::canManageTenantContracts($user));
        self::assertFalse(AdminCapabilities::canUpdateMarketSettings($user));
    }

    /**
     * @dataProvider tenantContractManagerRoles
     */
    public function test_contract_manager_roles_can_open_and_manage_contract_cards(string $roleName): void
    {
        $market = $this->createMarket();
        $user = $this->createMarketUser($market, $roleName);
        $contract = $this->createTenantContract($market);

        $this->actingAsFilamentUser($user);

        self::assertTrue(TenantContractResource::canViewAny());
        self::assertTrue(TenantContractResource::canEdit($contract));
        self::assertTrue(AdminCapabilities::canManageTenantContracts($user, (int) $market->id));
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
            'market-owner-director' => ['market-owner-director'],
            'market-admin' => ['market-admin'],
            'market-manager' => ['market-manager'],
            'market-legal-admin' => ['market-legal-admin'],
        ];
    }

    public static function financeViewerRoles(): array
    {
        return [
            'market-owner' => ['market-owner'],
            'market-owner-director' => ['market-owner-director'],
            'market-admin' => ['market-admin'],
            'market-manager' => ['market-manager'],
            'market-legal-admin' => ['market-legal-admin'],
            'market-accountant' => ['market-accountant'],
            'market-finance' => ['market-finance'],
        ];
    }

    public static function tenantContractManagerRoles(): array
    {
        return [
            'market-owner-director' => ['market-owner-director'],
            'market-admin' => ['market-admin'],
            'market-legal-admin' => ['market-legal-admin'],
        ];
    }

    public static function restrictedOperationalRoles(): array
    {
        return [
            'market-guard' => ['market-guard'],
            'market-security' => ['market-security'],
            'market-maintenance' => ['market-maintenance'],
            'market-engineer' => ['market-engineer'],
            'market-operator' => ['market-operator'],
            'market-support' => ['market-support'],
            'market-marketing' => ['market-marketing'],
            'staff' => ['staff'],
        ];
    }

    public static function serviceTenantViewerRoles(): array
    {
        return [
            'market-guard' => ['market-guard'],
            'market-security' => ['market-security'],
            'market-maintenance' => ['market-maintenance'],
            'market-engineer' => ['market-engineer'],
            'market-operator' => ['market-operator'],
            'market-support' => ['market-support'],
        ];
    }

    public static function noTenantDirectoryRoles(): array
    {
        return [
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

    private function createTenantContract(Market $market): TenantContract
    {
        return TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $this->createTenant($market)->id,
            'number' => 'Contract 1',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'is_active' => true,
        ]);
    }
}
