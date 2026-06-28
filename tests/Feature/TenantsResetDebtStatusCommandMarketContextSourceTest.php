<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class TenantsResetDebtStatusCommandMarketContextSourceTest extends TestCase
{
    public function test_reset_debt_status_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/TenantsResetDebtStatus.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('{--execute : Apply changes (default: dry-run)}', $source);
        self::assertStringContainsString('$dryRun = ! $execute || (bool) $this->option(\'dry-run\');', $source);
        self::assertStringContainsString('if ($execute && (bool) $this->option(\'dry-run\')) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->resetDebtStatus(null, $dryRun);', $source);
    }
}
