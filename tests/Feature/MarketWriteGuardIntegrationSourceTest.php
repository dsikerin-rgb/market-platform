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

    public function test_one_c_finance_import_controllers_use_market_write_guard(): void
    {
        $accrualController = file_get_contents(app_path('Http/Controllers/Api/OneC/AccrualController.php'));
        $paymentController = file_get_contents(app_path('Http/Controllers/Api/OneC/PaymentController.php'));

        self::assertIsString($accrualController);
        self::assertIsString($paymentController);

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $accrualController);
        self::assertStringContainsString('MarketWriteGuard $marketWriteGuard', $accrualController);
        self::assertStringContainsString('$this->assertMarketSpaceBelongsToMarket(', $accrualController);
        self::assertStringContainsString('$this->assertTenantContractBelongsToMarket(', $accrualController);
        self::assertStringContainsString('$this->assertAccrualBelongsToMarket(', $accrualController);
        self::assertSame(4, substr_count($accrualController, '$marketWriteGuard->assertSameMarketId('));

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $paymentController);
        self::assertStringContainsString('MarketWriteGuard $marketWriteGuard', $paymentController);
        self::assertStringContainsString("'Resolved tenant belongs to another market.'", $paymentController);
        self::assertStringContainsString("'Resolved contract belongs to another market.'", $paymentController);
        self::assertStringContainsString("'Existing payment belongs to another market.'", $paymentController);
        self::assertStringContainsString("'Created payment belongs to another market.'", $paymentController);
        self::assertSame(4, substr_count($paymentController, '$marketWriteGuard->assertSameMarketId('));
    }

    public function test_market_document_write_models_use_market_write_guard(): void
    {
        $document = file_get_contents(app_path('Models/MarketDocument.php'));
        $folder = file_get_contents(app_path('Models/MarketDocumentFolder.php'));
        $share = file_get_contents(app_path('Models/MarketDocumentShare.php'));

        self::assertIsString($document);
        self::assertIsString($folder);
        self::assertIsString($share);

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $document);
        self::assertStringContainsString('assertOwnerBelongsToDocumentMarket', $document);
        self::assertStringContainsString('assertRelatedBelongsToDocumentMarket', $document);
        self::assertStringContainsString("'Selected folder belongs to another market.'", $document);
        self::assertStringContainsString("'Related record belongs to another market.'", $document);
        self::assertSame(3, substr_count($document, 'assertSameMarketId('));

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $folder);
        self::assertStringContainsString('assertOwnerBelongsToFolderMarket', $folder);
        self::assertStringContainsString("'Parent folder belongs to another market.'", $folder);
        self::assertStringContainsString("'Folder owner belongs to another market.'", $folder);
        self::assertSame(2, substr_count($folder, 'assertSameMarketId('));

        self::assertStringContainsString('use App\Support\MarketWriteGuard;', $share);
        self::assertStringContainsString('assertUsersBelongToDocumentMarket', $share);
        self::assertStringContainsString("'Share recipient belongs to another market.'", $share);
        self::assertStringContainsString("'Share author belongs to another market.'", $share);
        self::assertSame(2, substr_count($share, 'assertSameMarketId('));
    }
}
