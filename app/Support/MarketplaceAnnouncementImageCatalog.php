<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

class MarketplaceAnnouncementImageCatalog
{
    /**
     * @return array<string, string>
     */
    public static function titleMap(): array
    {
        return [
            static::u('\u0038 \u043c\u0430\u0440\u0442\u0430') => '/marketplace/announcements/holiday-8-march.jpg',
            static::u('\u043c\u0435\u0436\u0434\u0443\u043d\u0430\u0440\u043e\u0434\u043d\u044b\u0439 \u0436\u0435\u043d\u0441\u043a\u0438\u0439 \u0434\u0435\u043d\u044c') => '/marketplace/announcements/holiday-8-march.jpg',
            static::u('\u0031 \u043c\u0430\u044f') => '/marketplace/announcements/holiday-1-may.jpg',
            static::u('\u043f\u0440\u0430\u0437\u0434\u043d\u0438\u043a \u0432\u0435\u0441\u043d\u044b \u0438 \u0442\u0440\u0443\u0434\u0430') => '/marketplace/announcements/holiday-1-may.jpg',
            static::u('\u0432\u0435\u0441\u0435\u043d\u043d\u0438\u0439 \u0446\u0435\u043d\u043e\u043f\u0430\u0434') => '/marketplace/announcements/promo-spring-discount.jpg',
            static::u('\u043d\u0435\u0434\u0435\u043b\u044f \u0444\u0435\u0440\u043c\u0435\u0440\u0441\u043a\u0438\u0445 \u0432\u043a\u0443\u0441\u043e\u0432') => '/marketplace/announcements/promo-farm-flavors.jpg',
            static::u('\u0432\u044b\u0445\u043e\u0434\u043d\u044b\u0435 \u043f\u043e\u0434\u0430\u0440\u043a\u043e\u0432 \u0438 \u0446\u0432\u0435\u0442\u043e\u0432') => '/marketplace/announcements/promo-gifts-flowers.jpg',
        ];
    }

    public static function pathForTitle(?string $title): ?string
    {
        $normalized = static::normalizeTitle($title);

        if ($normalized === '') {
            return null;
        }

        return static::titleMap()[$normalized] ?? null;
    }

    public static function resolveCoverImage(?string $title, mixed $currentCoverImage, bool $force = false): ?string
    {
        $mapped = static::pathForTitle($title);
        $current = is_string($currentCoverImage) ? trim($currentCoverImage) : '';

        if ($mapped === null) {
            return $current !== '' ? $current : null;
        }

        if ($force || $current === '' || static::isExternalImage($current)) {
            return $mapped;
        }

        return $current;
    }

    public static function isExternalImage(?string $value): bool
    {
        $value = trim((string) $value);

        if ($value === '') {
            return false;
        }

        return Str::startsWith($value, ['http://', 'https://']);
    }

    private static function normalizeTitle(?string $title): string
    {
        $value = Str::lower(trim((string) $title));

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private static function u(string $value): string
    {
        /** @var string $decoded */
        $decoded = json_decode('"' . $value . '"', true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
