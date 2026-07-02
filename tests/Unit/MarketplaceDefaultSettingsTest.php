<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\Marketplace\BaseMarketplaceController;
use App\Models\Market;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceDefaultSettingsTest extends TestCase
{
    public function test_synthetic_demo_market_uses_demo_marketplace_defaults(): void
    {
        $market = new Market([
            'name' => 'Демо-рынок Центральный',
            'slug' => 'demo-market',
            'settings' => [
                'demo_pilot' => [
                    'synthetic_source' => 'demo_pilot',
                ],
            ],
        ]);

        $settings = $this->controller()->exposeMarketplaceSettings($market);

        self::assertSame('Демо-рынок Центральный', $settings['brand_name']);
        self::assertSame('marketplace/brand/demo-market-logo.svg', $settings['logo_path']);
        self::assertSame('Демонстрационный рынок', $settings['hero_eyebrow']);
        self::assertSame('Покупки на демо-рынке в одном месте', $settings['hero_title']);
        self::assertSame('рынка «Демо-рынок Центральный»', $settings['market_public_label']);
    }

    public function test_regular_market_keeps_existing_marketplace_defaults(): void
    {
        $market = new Market([
            'name' => 'Эко Ярмарка',
            'slug' => 'ekoiarmarka-vdnx',
            'settings' => [],
        ]);

        $settings = $this->controller()->exposeMarketplaceSettings($market);

        self::assertSame('Маркетплейс Экоярмарки', $settings['brand_name']);
        self::assertSame('', $settings['logo_path']);
        self::assertSame('Городская Экоярмарка', $settings['hero_eyebrow']);
        self::assertSame('Покупки на Экоярмарке в одном месте', $settings['hero_title']);
        self::assertSame('Экоярмарки', $settings['market_public_label']);
    }

    public function test_bundled_public_logo_path_uses_public_asset_url(): void
    {
        $url = $this->controller()->exposeMarketplaceLogoUrl([
            'logo_path' => 'marketplace/brand/demo-market-logo.svg',
        ]);

        self::assertSame(asset('marketplace/brand/demo-market-logo.svg'), $url);
    }

    public function test_uploaded_logo_path_still_uses_public_storage_url(): void
    {
        $url = $this->controller()->exposeMarketplaceLogoUrl([
            'logo_path' => 'marketplace/brand/custom-upload.svg',
        ]);

        self::assertSame(Storage::disk('public')->url('marketplace/brand/custom-upload.svg'), $url);
    }

    private function controller(): object
    {
        return new class extends BaseMarketplaceController {
            /**
             * @return array<string, mixed>
             */
            public function exposeMarketplaceSettings(Market $market): array
            {
                return $this->resolveMarketplaceSettings($market);
            }

            /**
             * @param  array<string, mixed>  $settings
             */
            public function exposeMarketplaceLogoUrl(array $settings): string
            {
                return $this->resolveMarketplaceLogoUrl($settings);
            }
        };
    }
}
