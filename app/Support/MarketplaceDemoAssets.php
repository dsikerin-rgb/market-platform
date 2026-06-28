<?php

declare(strict_types=1);

namespace App\Support;

class MarketplaceDemoAssets
{
    private const DEFAULT_PROFILE = 'default';

    private const PRODUCT_PROFILE_KEYS = [
        'produce',
        'meat_fish',
        'dairy',
        'grocery',
        'clothing',
        'home',
        'services',
        'ready_food',
        self::DEFAULT_PROFILE,
    ];

    private const DEFAULT_IMAGE_PATHS = [
        '/marketplace/demo/demo-1.svg',
        '/marketplace/demo/demo-2.svg',
        '/marketplace/demo/demo-3.svg',
        '/marketplace/demo/demo-4.svg',
        '/marketplace/demo/demo-5.svg',
        '/marketplace/demo/demo-6.svg',
    ];

    /**
     * @return list<string>
     */
    public static function imagePaths(?string $profile = null): array
    {
        return self::DEFAULT_IMAGE_PATHS;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function productImageBanks(): array
    {
        $banks = [];

        foreach (self::PRODUCT_PROFILE_KEYS as $profileKey) {
            $banks[$profileKey] = self::imagePaths($profileKey);
        }

        return $banks;
    }

    /**
     * @return list<string>
     */
    public static function showcaseImagePaths(): array
    {
        return self::DEFAULT_IMAGE_PATHS;
    }
}
