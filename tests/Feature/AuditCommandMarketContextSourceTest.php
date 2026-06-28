<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuditCommandMarketContextSourceTest extends TestCase
{
    #[DataProvider('singleMarketAuditCommandFiles')]
    public function test_single_market_audit_command_wraps_execution_in_market_context(string $path): void
    {
        $source = file_get_contents(app_path($path));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
    }

    #[DataProvider('optionalMarketAuditCommandFiles')]
    public function test_optional_market_audit_command_keeps_all_market_scope_without_market_filter(string $path): void
    {
        $source = file_get_contents(app_path($path));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('app(MarketContext::class)->withMarket(', $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function singleMarketAuditCommandFiles(): array
    {
        return [
            'current duplicate contracts audit' => ['Console/Commands/AuditCurrentDuplicateContractsCommand.php'],
            'primary contract chains audit' => ['Console/Commands/AuditPrimaryContractChainsCommand.php'],
            'contract document types audit' => ['Console/Commands/AuditTenantContractDocumentTypesCommand.php'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function optionalMarketAuditCommandFiles(): array
    {
        return [
            'tenants audit' => ['Console/Commands/AuditTenants.php'],
            'notifications audit' => ['Console/Commands/AuditNotifications.php'],
        ];
    }
}
