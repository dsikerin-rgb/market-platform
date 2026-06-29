<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class MarketWriteGuardIntegrationSourceTest extends TestCase
{
    public function test_space_and_contract_write_services_use_market_write_guard(): void
    {
        $tenantSwitchPlanner = file_get_contents(app_path('Services/MarketSpaces/TenantSwitchPlanner.php'));
        $spaceGroupManager = file_get_contents(app_path('Services/MarketSpaces/SpaceGroupManager.php'));
        $safeContractSpaceLinker = file_get_contents(app_path('Services/TenantContracts/SafeContractSpaceLinker.php'));

        self::assertIsString($tenantSwitchPlanner);
        self::assertIsString($spaceGroupManager);
        self::assertIsString($safeContractSpaceLinker);

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $tenantSwitchPlanner);
        self::assertStringContainsString('private readonly MarketWriteGuard $marketWriteGuard', $tenantSwitchPlanner);
        self::assertStringContainsString("\$this->marketWriteGuard->assertSameMarket(\n            \$space,\n            \$targetTenant,\n            'target_tenant_id',", $tenantSwitchPlanner);

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $spaceGroupManager);
        self::assertStringContainsString('private readonly MarketWriteGuard $marketWriteGuard', $spaceGroupManager);
        self::assertSame(2, substr_count($spaceGroupManager, '$this->marketWriteGuard->assertSameMarket('));

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $safeContractSpaceLinker);
        self::assertStringContainsString('private readonly MarketWriteGuard $marketWriteGuard', $safeContractSpaceLinker);
        self::assertStringContainsString('->withoutMarketScope()', $safeContractSpaceLinker);
        self::assertStringContainsString("\$this->marketWriteGuard->assertSameMarket(\n            \$contract,\n            \$space,\n            'market_space_id',", $safeContractSpaceLinker);
    }
}
