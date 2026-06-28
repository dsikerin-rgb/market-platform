<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreviewApplyCommandMarketContextSourceTest extends TestCase
{
    public function test_primary_contract_chain_resolver_wraps_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ResolvePrimaryContractChainsCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
    }

    #[DataProvider('allMarketPreviewCommandFiles')]
    public function test_all_market_preview_command_selects_all_rows_then_processes_each_contract_in_market_context(string $path): void
    {
        $source = file_get_contents(app_path($path));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('->withoutMarketScope();', $source);
        self::assertStringContainsString('withContractMarket(', $source);
        self::assertStringContainsString('app(MarketContext::class)->withMarket($marketId, $callback)', $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allMarketPreviewCommandFiles(): array
    {
        return [
            'contract space linker' => ['Console/Commands/LinkContractSpacesCommand.php'],
            'contract binding sync' => ['Console/Commands/SyncTenantContractBindingsCommand.php'],
        ];
    }
}
