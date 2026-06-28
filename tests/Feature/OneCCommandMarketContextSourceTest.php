<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OneCCommandMarketContextSourceTest extends TestCase
{
    #[DataProvider('oneCCommandFiles')]
    public function test_one_c_command_wraps_execution_in_market_context(string $path): void
    {
        $source = file_get_contents(app_path($path));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertMatchesRegularExpression(
            '/return app\(MarketContext::class\)->withMarket\(\s*\$marketId,/s',
            $source,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function oneCCommandFiles(): array
    {
        return [
            'accrual reconcile report' => ['Console/Commands/AccrualsReconcileCommand.php'],
            'accrual dedupe repair' => ['Console/Commands/DedupeTenantAccrualsCommand.php'],
            'accrual contract linker' => ['Console/Commands/BackfillTenantAccrualContractsCommand.php'],
            'contract debt backfill' => ['Console/Commands/BackfillTenantContractsFromDebtsCommand.php'],
            'debt decision preview' => ['Console/Commands/DraftDebtDecisionsCommand.php'],
            'contract space matcher' => ['Console/Commands/MatchTenantContractsToSpacesCommand.php'],
            'contract duplicate warning report' => ['Console/Commands/ReportSuspiciousCurrentDuplicateWarningsCommand.php'],
            'market space collision report' => ['Console/Commands/ReportMarketSpaceKeyCollisions.php'],
        ];
    }
}
