<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\MarketplaceAnnouncement;
use App\Models\MarketplaceSlide;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MarketplaceMediaStorageTest extends TestCase
{
    public function test_store_creates_resized_original_and_preview(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $file = UploadedFile::fake()->image('product.jpg', 2400, 1800);

        $path = MarketplaceMediaStorage::store($file, 'marketplace-products');
        $previewPath = MarketplaceMediaStorage::previewPath($path);

        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists($previewPath);

        $originalSize = getimagesizefromstring((string) Storage::disk('public')->get($path));
        $previewSize = getimagesizefromstring((string) Storage::disk('public')->get($previewPath));

        $this->assertIsArray($originalSize);
        $this->assertIsArray($previewSize);
        $this->assertLessThanOrEqual(1600, (int) $originalSize[0]);
        $this->assertLessThanOrEqual(1600, (int) $originalSize[1]);
        $this->assertSame(480, (int) $previewSize[0]);
        $this->assertSame(360, (int) $previewSize[1]);
        $this->assertStringEndsWith('.webp', $path);
        $this->assertStringEndsWith('.webp', $previewPath);
    }

    public function test_import_preserves_real_image_pixels_in_preview(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $tempPath = tempnam(sys_get_temp_dir(), 'mp-color-');
        $jpegPath = $tempPath . '.jpg';
        @unlink($tempPath);

        $image = imagecreatetruecolor(900, 600);
        $red = imagecolorallocate($image, 220, 30, 30);
        imagefilledrectangle($image, 0, 0, 899, 599, $red);
        imagejpeg($image, $jpegPath, 90);
        imagedestroy($image);

        try {
            $path = MarketplaceMediaStorage::importFromPath($jpegPath, 'marketplace-products', 'solid-red-' . Str::random(6), 'image/jpeg');
            $this->assertNotNull($path);

            $previewBinary = Storage::disk('public')->get(MarketplaceMediaStorage::previewPath($path));
            $preview = imagecreatefromstring($previewBinary);

            $this->assertNotFalse($preview);

            $pixel = imagecolorat($preview, 240, 180);
            $channels = imagecolorsforindex($preview, $pixel);

            $this->assertGreaterThan(150, $channels['red']);
            $this->assertLessThan(80, $channels['green']);
            $this->assertLessThan(80, $channels['blue']);

            imagedestroy($preview);
        } finally {
            @unlink($jpegPath);
        }
    }

    public function test_preview_url_prefers_generated_preview_file(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $path = 'marketplace-products/example.webp';
        $previewPath = MarketplaceMediaStorage::previewPath($path);

        Storage::disk('public')->put($path, 'original');
        Storage::disk('public')->put($previewPath, 'preview');

        $this->assertSame(
            route('marketplace.media.proxy', ['path' => $previewPath]),
            MarketplaceMediaStorage::previewUrl($path)
        );
    }

    public function test_preview_url_falls_back_to_original_when_preview_is_missing(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $path = 'marketplace-products/example.webp';

        Storage::disk('public')->put($path, 'original');

        $this->assertSame(
            route('marketplace.media.proxy', ['path' => $path]),
            MarketplaceMediaStorage::previewUrl($path)
        );
    }

    public function test_ensure_preview_generates_missing_preview_for_legacy_original(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $file = UploadedFile::fake()->image('legacy.jpg', 1200, 900);
        $path = $file->storeAs('marketplace-products', 'legacy.jpg', 'public');
        $previewPath = MarketplaceMediaStorage::previewPath($path);

        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing($previewPath);

        $this->assertFalse(MarketplaceMediaStorage::hasPreview($path));
        $this->assertTrue(MarketplaceMediaStorage::ensurePreview($path));
        $this->assertTrue(MarketplaceMediaStorage::hasPreview($path));

        Storage::disk('public')->assertExists($previewPath);

        $previewSize = getimagesizefromstring((string) Storage::disk('public')->get($previewPath));

        $this->assertIsArray($previewSize);
        $this->assertSame(480, (int) $previewSize[0]);
        $this->assertSame(360, (int) $previewSize[1]);
    }

    public function test_ensure_preview_can_force_regeneration_of_existing_preview(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $tempPath = tempnam(sys_get_temp_dir(), 'mp-force-');
        $jpegPath = $tempPath . '.jpg';
        @unlink($tempPath);

        $image = imagecreatetruecolor(900, 600);
        $blue = imagecolorallocate($image, 30, 60, 220);
        imagefilledrectangle($image, 0, 0, 899, 599, $blue);
        imagejpeg($image, $jpegPath, 90);
        imagedestroy($image);

        try {
            $path = MarketplaceMediaStorage::importFromPath($jpegPath, 'marketplace-products', 'solid-blue-' . Str::random(6), 'image/jpeg');
            $this->assertNotNull($path);

            $previewPath = MarketplaceMediaStorage::previewPath($path);
            Storage::disk('public')->put($previewPath, 'broken-preview');

            $this->assertTrue(MarketplaceMediaStorage::ensurePreview($path, true));

            $previewBinary = Storage::disk('public')->get($previewPath);
            $this->assertNotSame('broken-preview', $previewBinary);

            $preview = imagecreatefromstring($previewBinary);
            $this->assertNotFalse($preview);

            $pixel = imagecolorat($preview, 240, 180);
            $channels = imagecolorsforindex($preview, $pixel);

            $this->assertGreaterThan(150, $channels['blue']);
            $this->assertLessThan(100, $channels['red']);
            $this->assertLessThan(120, $channels['green']);

            imagedestroy($preview);
        } finally {
            @unlink($jpegPath);
        }
    }

    public function test_url_falls_back_to_public_when_primary_disk_is_missing(): void
    {
        Storage::fake('s3');
        Storage::fake('public');

        config()->set('marketplace.media_disk', 's3');
        config()->set('marketplace.media_fallback_disk', 'public');

        $path = 'marketplace-demo-assets/products/home/example.webp';
        Storage::disk('public')->put($path, 'fallback');

        $this->assertSame(
            route('marketplace.media.proxy', ['path' => $path]),
            MarketplaceMediaStorage::url($path)
        );
    }

    public function test_models_expose_preview_urls_for_listing_images(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $announcementPath = 'market-holidays/events/announcement.webp';
        $slidePath = 'marketplace/slides/slide.webp';

        Storage::disk('public')->put(MarketplaceMediaStorage::previewPath($announcementPath), 'announcement-preview');
        Storage::disk('public')->put(MarketplaceMediaStorage::previewPath($slidePath), 'slide-preview');

        $announcement = new MarketplaceAnnouncement(['cover_image' => $announcementPath]);
        $slide = new MarketplaceSlide(['image_path' => $slidePath]);

        $this->assertSame(
            route('marketplace.media.proxy', ['path' => MarketplaceMediaStorage::previewPath($announcementPath)]),
            $announcement->cover_image_preview_url
        );
        $this->assertSame(
            route('marketplace.media.proxy', ['path' => MarketplaceMediaStorage::previewPath($slidePath)]),
            $slide->image_preview_url
        );
    }

    public function test_media_proxy_falls_back_to_original_with_different_extension(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $path = 'market-holidays/events/example.jpg';
        Storage::disk('public')->put($path, 'jpeg-original');

        $response = $this->get(route('marketplace.media.proxy', [
            'path' => 'market-holidays/events/previews/example.webp',
        ]));

        $response->assertOk();
        $response->assertHeader('content-disposition');
    }

    public function test_media_proxy_generates_missing_preview_before_falling_back_to_original(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        $file = UploadedFile::fake()->image('legacy.jpg', 1200, 900);
        $path = $file->storeAs('marketplace-products', 'legacy.jpg', 'public');
        $previewPath = 'marketplace-products/previews/legacy.webp';

        Storage::disk('public')->assertMissing($previewPath);

        $response = $this->get(route('marketplace.media.proxy', [
            'path' => $previewPath,
        ]));

        $response->assertOk();
        Storage::disk('public')->assertExists($previewPath);
    }

    public function test_normalize_local_public_tree_permissions_walks_the_full_tree(): void
    {
        $filesystem = new Filesystem();
        $directory = 'marketplace-demo-assets-permissions-' . Str::random(8);
        $basePath = storage_path('app/public/' . $directory);

        $filesystem->ensureDirectoryExists($basePath . '/nested');
        file_put_contents($basePath . '/root.txt', 'root');
        file_put_contents($basePath . '/nested/child.txt', 'child');

        try {
            $normalized = MarketplaceMediaStorage::normalizeLocalPublicTreePermissions($directory);

            $this->assertGreaterThanOrEqual(3, $normalized);
            $this->assertFileExists($basePath . '/root.txt');
            $this->assertFileExists($basePath . '/nested/child.txt');
        } finally {
            $filesystem->deleteDirectory($basePath);
        }
    }

    public function test_media_proxy_sets_public_cache_headers(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');

        Storage::disk('public')->put('marketplace-products/example.webp', 'image');

        $response = $this->get(route('marketplace.media.proxy', [
            'path' => 'marketplace-products/example.webp',
        ]));

        $response->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control', '');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=604800', $cacheControl);
        $this->assertStringContainsString('immutable', $cacheControl);
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
