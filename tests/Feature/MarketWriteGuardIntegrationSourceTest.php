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

    public function test_accrual_maintenance_commands_use_market_write_guard(): void
    {
        $backfillAccrualContracts = file_get_contents(app_path('Console/Commands/BackfillTenantAccrualContractsCommand.php'));
        $dedupeTenantAccruals = file_get_contents(app_path('Console/Commands/DedupeTenantAccrualsCommand.php'));

        self::assertIsString($backfillAccrualContracts);
        self::assertIsString($dedupeTenantAccruals);

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $backfillAccrualContracts);
        self::assertStringContainsString('MarketWriteGuard $marketWriteGuard', $backfillAccrualContracts);
        self::assertSame(3, substr_count($backfillAccrualContracts, '$marketWriteGuard->assertSameMarketId('));

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $dedupeTenantAccruals);
        self::assertStringContainsString('MarketWriteGuard $marketWriteGuard', $dedupeTenantAccruals);
        self::assertStringContainsString('$marketWriteGuard->assertSameMarketId(', $dedupeTenantAccruals);
        self::assertStringContainsString("->where('market_id', \$marketId)\n                            ->whereIn('id', \$deleteIds)", $dedupeTenantAccruals);
    }
}
