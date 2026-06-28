<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class ArchiveStaleTenantContractsCommandMarketContextSourceTest extends TestCase
{
    public function test_archive_stale_tenant_contracts_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ArchiveStaleTenantContractsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->archiveStaleContracts($activity, null, $months, $limit, $apply, $contractIds);', $source);
    }
}
