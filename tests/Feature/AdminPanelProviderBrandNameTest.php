<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class AdminPanelProviderBrandNameTest extends TestCase
{
    public function test_super_admin_brand_name_uses_selected_market_context(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/Providers/Filament/AdminPanelProvider.php');

        self::assertIsString($source);
        self::assertStringContainsString('use App\Models\Market;', $source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('app(MarketContext::class)->currentMarketId($user)', $source);
        self::assertStringContainsString("Market::query()->whereKey(\$marketId)->value('name')", $source);
    }
}
