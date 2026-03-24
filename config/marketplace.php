<?php

declare(strict_types=1);

return [
    'demo_content_enabled' => env('MARKETPLACE_DEMO_CONTENT_ENABLED', false),

    'home' => [
        // Fast rollback toggle: false returns sanitary_day cards into main feed.
        'hide_sanitary_in_feed' => env('MARKETPLACE_HOME_HIDE_SANITARY_IN_FEED', true),

        // Fast rollback toggle: false hides the dedicated nearest sanitary warning block.
        'sanitary_warning_enabled' => env('MARKETPLACE_HOME_SANITARY_WARNING_ENABLED', true),
    ],
    'contracts' => [
        'allow_public_sales_without_active_contracts' => env('MARKETPLACE_ALLOW_PUBLIC_SALES_WITHOUT_ACTIVE_CONTRACTS', false),
    ],
    'brand' => [
        // Fast rollback toggle for legacy-site content merged into marketplace home.
        'legacy_site_merge_enabled' => env('MARKETPLACE_BRAND_LEGACY_SITE_MERGE_ENABLED', true),

        'public_phone' => env('MARKETPLACE_PUBLIC_PHONE', '+7 (3852) 55-67-55'),
        'public_email' => env('MARKETPLACE_PUBLIC_EMAIL', 'Ekobarnaul22@yandex.ru'),
        'public_address' => env('MARKETPLACE_PUBLIC_ADDRESS', 'г. Барнаул, ул. Взлётная, 2-К'),
    ],
];
