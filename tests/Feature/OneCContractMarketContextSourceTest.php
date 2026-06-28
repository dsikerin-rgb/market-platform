<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class OneCContractMarketContextSourceTest extends TestCase
{
    public function test_contract_import_wraps_processing_in_market_context(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/OneC/ContractController.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('$marketId = (int) $integration->market_id;', $source);
        self::assertMatchesRegularExpression(
            '/app\(MarketContext::class\)->withMarket\(\s*\$marketId,\s*function \(\) use \([^)]+&\$exchange\): JsonResponse/s',
            $source,
        );
    }
}
