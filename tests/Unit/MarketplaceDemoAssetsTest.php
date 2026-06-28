<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MarketplaceDemoAssets;
use Tests\TestCase;

class MarketplaceDemoAssetsTest extends TestCase
{
    public function test_product_image_banks_expose_known_demo_profiles(): void
    {
        $banks = MarketplaceDemoAssets::productImageBanks();

        self::assertArrayHasKey('default', $banks);
        self::assertArrayHasKey('ready_food', $banks);
        self::assertArrayHasKey('meat_fish', $banks);
        self::assertSame(MarketplaceDemoAssets::imagePaths(), $banks['default']);
        self::assertSame(MarketplaceDemoAssets::imagePaths('ready_food'), $banks['ready_food']);
    }

    public function test_showcase_image_paths_reuse_demo_bank(): void
    {
        self::assertSame(MarketplaceDemoAssets::imagePaths(), MarketplaceDemoAssets::showcaseImagePaths());
    }
}
