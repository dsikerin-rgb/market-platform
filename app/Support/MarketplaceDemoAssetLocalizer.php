<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MarketplaceDemoAssetLocalizer
{
    private const REMOTE_USER_AGENT = 'marketplace-demo-asset-localizer/1.0';

    /**
     * @var array<string, string>
     */
    private static array $resolvedPaths = [];

    public static function localize(string $source, string $type = 'products', bool $force = false): string
    {
        $normalizedSource = trim($source);
        if ($normalizedSource === '' || ! self::shouldLocalize()) {
            return $normalizedSource;
        }

        if (! self::isLocalizableSource($normalizedSource)) {
            return $normalizedSource;
        }

        $cacheKey = $type . '|' . $normalizedSource;
        if (isset(self::$resolvedPaths[$cacheKey])) {
            return self::$resolvedPaths[$cacheKey];
        }

        $baseName = sha1($type . '|' . $normalizedSource);
        $directory = self::directory($type);
        $cachedPath = $directory . '/' . $baseName . '.webp';

        if (! $force && self::existsOnAnyDisk($cachedPath)) {
            return self::$resolvedPaths[$cacheKey] = $cachedPath;
        }

        if ($force) {
            MarketplaceMediaStorage::delete($cachedPath);
        }

        if (Str::startsWith($normalizedSource, '/')) {
            return self::$resolvedPaths[$cacheKey] = (self::localizePublicAsset($normalizedSource, $directory, $baseName) ?? $normalizedSource);
        }

        return self::$resolvedPaths[$cacheKey] = (self::localizeRemoteAsset($normalizedSource, $directory, $baseName) ?? $normalizedSource);
    }

    private static function shouldLocalize(): bool
    {
        return (bool) config('marketplace.demo_assets.localize', false);
    }

    private static function isLocalizableSource(string $source): bool
    {
        return Str::startsWith($source, ['http://', 'https://', '/']);
    }

    private static function directory(string $type): string
    {
        $base = trim((string) config('marketplace.demo_assets.directory', 'marketplace-demo-assets'), '/');
        $segment = trim($type, '/');

        return $segment !== '' ? $base . '/' . $segment : $base;
    }

    private static function existsOnAnyDisk(string $path): bool
    {
        try {
            if (Storage::disk(MarketplaceMediaStorage::disk())->exists($path)) {
                return true;
            }
        } catch (\Throwable) {
        }

        $fallbackDisk = MarketplaceMediaStorage::fallbackDisk();

        if ($fallbackDisk === null || $fallbackDisk === MarketplaceMediaStorage::disk()) {
            return false;
        }

        try {
            return Storage::disk($fallbackDisk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function localizePublicAsset(string $source, string $directory, string $baseName): ?string
    {
        $publicPath = public_path(ltrim($source, '/'));

        return MarketplaceMediaStorage::importFromPath($publicPath, $directory, $baseName);
    }

    private static function localizeRemoteAsset(string $source, string $directory, string $baseName): ?string
    {
        $timeout = max(5, (int) config('marketplace.demo_assets.timeout', 15));
        $retries = max(0, (int) config('marketplace.demo_assets.retries', 0));

        try {
            $request = Http::timeout($timeout)
                ->withHeaders([
                    'User-Agent' => self::REMOTE_USER_AGENT,
                ]);

            if ($retries > 0) {
                $request = $request->retry($retries, 200);
            }

            $response = $request->get($source);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $binary = $response->body();
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'mp-demo-');
        if (! is_string($tempPath) || $tempPath === '') {
            return null;
        }

        try {
            if (@file_put_contents($tempPath, $binary) === false) {
                return null;
            }

            $mimeType = trim((string) $response->header('Content-Type'));
            if ($mimeType !== '' && str_contains($mimeType, ';')) {
                $mimeType = trim((string) Str::before($mimeType, ';'));
            }

            return MarketplaceMediaStorage::importFromPath($tempPath, $directory, $baseName, $mimeType !== '' ? $mimeType : null);
        } finally {
            @unlink($tempPath);
        }
    }
}
