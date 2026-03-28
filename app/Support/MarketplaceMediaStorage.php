<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class MarketplaceMediaStorage
{
    private const ORIGINAL_MAX_WIDTH = 1600;
    private const ORIGINAL_MAX_HEIGHT = 1600;
    private const PREVIEW_WIDTH = 480;
    private const PREVIEW_HEIGHT = 360;
    private const ORIGINAL_QUALITY = 82;
    private const PREVIEW_QUALITY = 76;

    public static function disk(): string
    {
        $disk = trim((string) config('marketplace.media_disk', 'public'));

        return $disk !== '' ? $disk : 'public';
    }

    public static function fallbackDisk(): ?string
    {
        $disk = trim((string) config('marketplace.media_fallback_disk', 'public'));

        return $disk !== '' ? $disk : null;
    }

    public static function store(UploadedFile $file, string $directory): string
    {
        $path = self::importFromPath(
            (string) $file->getRealPath(),
            $directory,
            null,
            (string) $file->getMimeType()
        );

        return $path ?? $file->store($directory, self::disk());
    }

    public static function importFromPath(string $sourcePath, string $directory, ?string $baseName = null, ?string $mimeType = null): ?string
    {
        $normalizedPath = trim($sourcePath);
        if ($normalizedPath === '' || ! is_file($normalizedPath) || ! is_readable($normalizedPath)) {
            return null;
        }

        $directory = trim($directory, '/');
        $baseName = trim((string) $baseName);
        if ($baseName === '') {
            $baseName = (string) Str::uuid();
        }

        $resolvedMimeType = trim((string) ($mimeType ?: self::detectMimeType($normalizedPath)));

        if (! self::canTransformPath($normalizedPath, $resolvedMimeType)) {
            $extension = self::extensionForMimeType($resolvedMimeType)
                ?: strtolower((string) pathinfo($normalizedPath, PATHINFO_EXTENSION))
                ?: 'bin';
            $path = $directory . '/' . $baseName . '.' . $extension;
            $binary = @file_get_contents($normalizedPath);

            if (! is_string($binary)) {
                return null;
            }

            Storage::disk(self::disk())->put($path, $binary, array_filter([
                'visibility' => 'public',
                'ContentType' => $resolvedMimeType !== '' ? $resolvedMimeType : null,
            ], static fn ($value): bool => $value !== null));

            return $path;
        }

        $path = $directory . '/' . $baseName . '.webp';
        $previewPath = self::previewPath($path);

        $image = self::loadImageResourceFromPath($normalizedPath, $resolvedMimeType);
        if ($image === null) {
            return null;
        }

        try {
            $normalized = self::resizeToFit($image, self::ORIGINAL_MAX_WIDTH, self::ORIGINAL_MAX_HEIGHT);
            $preview = self::createCoverPreview($normalized, self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT);

            self::storeWebp(self::disk(), $path, $normalized, self::ORIGINAL_QUALITY);
            self::storeWebp(self::disk(), $previewPath, $preview, self::PREVIEW_QUALITY);
        } finally {
            self::destroyImage($image);
            self::destroyImage($normalized ?? null);
            self::destroyImage($preview ?? null);
        }

        return $path;
    }

    public static function delete(?string $path): void
    {
        $value = trim((string) $path);
        if ($value === '' || self::isExternal($value)) {
            return;
        }

        $paths = [$value, self::previewPath($value)];

        Storage::disk(self::disk())->delete($paths);

        $fallbackDisk = self::fallbackDisk();
        if ($fallbackDisk !== null && $fallbackDisk !== self::disk()) {
            Storage::disk($fallbackDisk)->delete($paths);
        }
    }

    public static function url(?string $path): ?string
    {
        $value = trim((string) $path);
        if ($value === '') {
            return null;
        }

        if (self::isExternal($value)) {
            return $value;
        }

        return self::proxyRoute($value);
    }

    public static function previewUrl(?string $path): ?string
    {
        $value = trim((string) $path);
        if ($value === '') {
            return null;
        }

        if (self::isExternal($value)) {
            return $value;
        }

        $previewPath = self::previewPath($value);
        $disk = self::disk();
        $fallbackDisk = self::fallbackDisk();

        if (self::exists($disk, $previewPath)) {
            return self::proxyRoute($previewPath);
        }

        if (self::exists($disk, $value)) {
            return self::proxyRoute($value);
        }

        if ($fallbackDisk !== null && $fallbackDisk !== $disk) {
            if (self::exists($fallbackDisk, $previewPath)) {
                return self::proxyRoute($previewPath);
            }

            if (self::exists($fallbackDisk, $value)) {
                return self::proxyRoute($value);
            }
        }

        return self::proxyRoute($previewPath);
    }

    public static function serve(string $path)
    {
        $value = trim($path, "/ \t\n\r\0\x0B");
        abort_if($value === '', 404);

        $disk = self::disk();
        $fallbackDisk = self::fallbackDisk();

        if (self::exists($disk, $value)) {
            return self::serveFromDisk($disk, $value);
        }

        if ($fallbackDisk !== null && $fallbackDisk !== $disk && self::exists($fallbackDisk, $value)) {
            return self::serveFromDisk($fallbackDisk, $value);
        }

        if (str_contains($value, '/previews/')) {
            foreach (self::originalPathCandidatesFromPreviewPath($value) as $originalPath) {
                if (self::ensurePreview($originalPath) && self::exists($disk, $value)) {
                    return self::serveFromDisk($disk, $value);
                }
            }
        }

        foreach (self::originalPathCandidatesFromPreviewPath($value) as $originalPath) {
            if (self::exists($disk, $originalPath)) {
                return self::serveFromDisk($disk, $originalPath);
            }

            if ($fallbackDisk !== null && $fallbackDisk !== $disk && self::exists($fallbackDisk, $originalPath)) {
                return self::serveFromDisk($fallbackDisk, $originalPath);
            }
        }

        abort(404);
    }

    public static function ensurePreview(?string $path, bool $force = false): bool
    {
        $value = trim((string) $path);
        if ($value === '' || self::isExternal($value)) {
            return false;
        }

        $previewPath = self::previewPath($value);
        $disk = self::disk();
        if (! $force && self::exists($disk, $previewPath)) {
            return true;
        }

        $source = self::locateExistingPath($value);
        if ($source === null) {
            return false;
        }

        [$sourceDisk, $sourcePath] = $source;
        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        if (! is_resource($stream)) {
            return false;
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'mp-preview-');
        if (! is_string($tempPath) || $tempPath === '') {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return false;
        }

        try {
            $target = fopen($tempPath, 'wb');
            if (! is_resource($target)) {
                return false;
            }

            stream_copy_to_stream($stream, $target);
            fclose($target);
            fclose($stream);

            $mimeType = null;
            try {
                $mimeType = Storage::disk($sourceDisk)->mimeType($sourcePath);
            } catch (\Throwable) {
                $mimeType = null;
            }

            $resolvedMimeType = trim((string) ($mimeType ?: self::detectMimeType($tempPath)));
            if (! self::canTransformPath($tempPath, $resolvedMimeType)) {
                return false;
            }

            $image = self::loadImageResourceFromPath($tempPath, $resolvedMimeType);
            if ($image === null) {
                return false;
            }

            try {
                $normalized = self::resizeToFit($image, self::ORIGINAL_MAX_WIDTH, self::ORIGINAL_MAX_HEIGHT);
                $preview = self::createCoverPreview($normalized, self::PREVIEW_WIDTH, self::PREVIEW_HEIGHT);

                if ($force && self::exists($disk, $previewPath)) {
                    Storage::disk($disk)->delete($previewPath);
                }

                self::storeWebp($disk, $previewPath, $preview, self::PREVIEW_QUALITY);
            } finally {
                self::destroyImage($image);
                self::destroyImage($normalized ?? null);
                self::destroyImage($preview ?? null);
            }

            return true;
        } finally {
            @unlink($tempPath);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public static function hasPreview(?string $path): bool
    {
        $value = trim((string) $path);
        if ($value === '' || self::isExternal($value)) {
            return false;
        }

        $previewPath = self::previewPath($value);
        $disk = self::disk();
        if (self::exists($disk, $previewPath)) {
            return true;
        }

        $fallbackDisk = self::fallbackDisk();

        return $fallbackDisk !== null
            && $fallbackDisk !== $disk
            && self::exists($fallbackDisk, $previewPath);
    }

    public static function previewPath(string $path): string
    {
        $directory = trim((string) pathinfo($path, PATHINFO_DIRNAME), '/.');
        $filename = trim((string) pathinfo($path, PATHINFO_FILENAME));

        $segments = [];
        if ($directory !== '') {
            $segments[] = $directory;
        }
        $segments[] = 'previews';
        $segments[] = $filename . '.webp';

        return implode('/', $segments);
    }

    public static function originalPathFromPreviewPath(string $path): ?string
    {
        return self::originalPathCandidatesFromPreviewPath($path)[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function originalPathCandidatesFromPreviewPath(string $path): array
    {
        $normalized = trim($path, '/');
        if ($normalized === '' || ! str_contains($normalized, '/previews/')) {
            return [];
        }

        $directory = trim((string) pathinfo($normalized, PATHINFO_DIRNAME), '/.');
        $stem = trim((string) pathinfo($normalized, PATHINFO_FILENAME));
        if ($directory === '' || $stem === '') {
            return [];
        }

        $baseDirectory = preg_replace('#/previews$#', '', $directory, 1);
        if (! is_string($baseDirectory) || $baseDirectory === '') {
            return [];
        }

        $candidates = [];
        foreach (['webp', 'jpg', 'jpeg', 'png', 'gif'] as $extension) {
            $candidates[] = $baseDirectory . '/' . $stem . '.' . $extension;
        }

        return array_values(array_unique($candidates));
    }

    private static function isExternal(string $path): bool
    {
        return Str::startsWith($path, ['http://', 'https://', 'data:', '/']);
    }

    private static function exists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function locateExistingPath(string $path): ?array
    {
        $disk = self::disk();
        if (self::exists($disk, $path)) {
            return [$disk, $path];
        }

        $fallbackDisk = self::fallbackDisk();
        if ($fallbackDisk !== null && $fallbackDisk !== $disk && self::exists($fallbackDisk, $path)) {
            return [$fallbackDisk, $path];
        }

        return null;
    }

    private static function proxyRoute(string $path): string
    {
        return route('marketplace.media.proxy', ['path' => ltrim($path, '/')]);
    }

    /**
     * @return array<string, string>
     */
    private static function responseHeaders(): array
    {
        return [
            'Cache-Control' => 'public, max-age=604800, immutable',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    private static function serveFromDisk(string $disk, string $path)
    {
        try {
            $absolutePath = Storage::disk($disk)->path($path);

            if (is_string($absolutePath) && $absolutePath !== '' && is_file($absolutePath)) {
                return response()->file($absolutePath, self::responseHeaders());
            }
        } catch (\Throwable) {
        }

        return Storage::disk($disk)->response($path, null, self::responseHeaders());
    }

    private static function canTransform(UploadedFile $file): bool
    {
        return self::canTransformPath(
            (string) $file->getRealPath(),
            (string) $file->getMimeType()
        );
    }

    private static function canTransformPath(string $path, string $mimeType): bool
    {
        return function_exists('imagewebp')
            && function_exists('imagecreatetruecolor')
            && $path !== ''
            && in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    /**
     * @return \GdImage|resource|null
     */
    private static function loadImageResource(UploadedFile $file)
    {
        return self::loadImageResourceFromPath(
            (string) $file->getRealPath(),
            (string) $file->getMimeType()
        );
    }

    /**
     * @return \GdImage|resource|null
     */
    private static function loadImageResourceFromPath(string $path, string $mimeType)
    {
        if ($path === '') {
            return null;
        }

        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => null,
        };

        if ($image === false || $image === null) {
            return null;
        }

        if ($mimeType === 'image/jpeg') {
            $image = self::applyExifOrientation($image, $path);
        }

        return $image;
    }

    private static function detectMimeType(string $path): ?string
    {
        $detected = @mime_content_type($path);

        return is_string($detected) && $detected !== '' ? $detected : null;
    }

    private static function extensionForMimeType(string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function resizeToFit($image, int $maxWidth, int $maxHeight)
    {
        $sourceWidth = (int) imagesx($image);
        $sourceHeight = (int) imagesy($image);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            throw new RuntimeException('Unable to determine image size.');
        }

        $scale = min($maxWidth / $sourceWidth, $maxHeight / $sourceHeight, 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        if ($targetWidth === $sourceWidth && $targetHeight === $sourceHeight) {
            return self::cloneImage($image, $sourceWidth, $sourceHeight);
        }

        $canvas = self::createCanvas($targetWidth, $targetHeight);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);

        return $canvas;
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function createCoverPreview($image, int $targetWidth, int $targetHeight)
    {
        $sourceWidth = (int) imagesx($image);
        $sourceHeight = (int) imagesy($image);
        $scale = max($targetWidth / max($sourceWidth, 1), $targetHeight / max($sourceHeight, 1));

        $scaledWidth = max($targetWidth, (int) ceil($sourceWidth * $scale));
        $scaledHeight = max($targetHeight, (int) ceil($sourceHeight * $scale));

        $scaled = self::createCanvas($scaledWidth, $scaledHeight);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $scaledWidth, $scaledHeight, $sourceWidth, $sourceHeight);

        $cropX = max(0, (int) floor(($scaledWidth - $targetWidth) / 2));
        $cropY = max(0, (int) floor(($scaledHeight - $targetHeight) / 2));

        $preview = self::createCanvas($targetWidth, $targetHeight);
        imagecopy($preview, $scaled, 0, 0, $cropX, $cropY, $targetWidth, $targetHeight);
        self::destroyImage($scaled);

        return $preview;
    }

    /**
     * @param \GdImage|resource $image
     */
    private static function storeWebp(string $disk, string $path, $image, int $quality): void
    {
        ob_start();
        imagewebp($image, null, $quality);
        $binary = ob_get_clean();

        if (! is_string($binary)) {
            throw new RuntimeException('Unable to encode image to WEBP.');
        }

        Storage::disk($disk)->put($path, $binary, [
            'visibility' => 'public',
            'ContentType' => 'image/webp',
        ]);
    }

    /**
     * @return \GdImage|resource
     */
    private static function createCanvas(int $width, int $height)
    {
        $canvas = imagecreatetruecolor($width, $height);
        self::initializeCanvas($canvas);

        return $canvas;
    }

    /**
     * @param \GdImage|resource $image
     */
    private static function initializeCanvas($image): void
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 255, 255, 255, 127);
        imagefilledrectangle($image, 0, 0, (int) imagesx($image), (int) imagesy($image), $transparent);
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function cloneImage($image, int $width, int $height)
    {
        $clone = self::createCanvas($width, $height);
        imagecopy($clone, $image, 0, 0, 0, 0, $width, $height);

        return $clone;
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function applyExifOrientation($image, string $path)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        return match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /**
     * @param \GdImage|resource|null $image
     */
    private static function destroyImage($image): void
    {
        if ($image !== null && is_object($image)) {
            imagedestroy($image);
        }
    }
}
