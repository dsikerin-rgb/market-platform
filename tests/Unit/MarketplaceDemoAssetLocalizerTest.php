<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\MarketplaceDemoAssetLocalizer;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MarketplaceDemoAssetLocalizerTest extends TestCase
{
    public function test_localize_downloads_remote_demo_asset_once_and_reuses_cached_file(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');
        config()->set('marketplace.demo_assets.localize', true);
        config()->set('marketplace.demo_assets.directory', 'marketplace-demo-assets');
        config()->set('marketplace.demo_assets.timeout', 15);

        $file = UploadedFile::fake()->image('demo.jpg', 1200, 900);
        $binary = (string) file_get_contents($file->getRealPath());
        $source = 'https://images.unsplash.com/photo-demo-test?w=1400&q=80';

        Http::fake([
            $source => Http::response($binary, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $firstPath = MarketplaceDemoAssetLocalizer::localize($source, 'products/ready_food');
        $secondPath = MarketplaceDemoAssetLocalizer::localize($source, 'products/ready_food');

        $this->assertSame($firstPath, $secondPath);
        $this->assertStringStartsWith('marketplace-demo-assets/products/ready_food/', $firstPath);
        $this->assertStringEndsWith('.webp', $firstPath);

        Storage::disk('public')->assertExists($firstPath);
        Storage::disk('public')->assertExists(MarketplaceMediaStorage::previewPath($firstPath));
        Http::assertSentCount(1);
    }
}
