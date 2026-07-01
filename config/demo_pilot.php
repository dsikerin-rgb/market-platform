<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Demo / pilot contour
    |--------------------------------------------------------------------------
    |
    | This contour is intentionally disabled by default. It is the safety gate
    | for future demo:provision and demo:reset commands before they can create
    | synthetic markets, users, tenants, spaces, contracts, or finance records.
    |
    */
    'enabled' => env('DEMO_PILOT_ENABLED', false),
    'provision_enabled' => env('DEMO_PILOT_PROVISION_ENABLED', false),
    'reset_enabled' => env('DEMO_PILOT_RESET_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Production write barrier
    |--------------------------------------------------------------------------
    |
    | Keep this false on prod until a concrete demo/pilot data-write operation
    | has been reviewed, backed up where needed, and explicitly approved.
    |
    */
    'allow_production_data_writes' => env('DEMO_PILOT_ALLOW_PROD_WRITES', false),

    /*
    |--------------------------------------------------------------------------
    | Synthetic data defaults
    |--------------------------------------------------------------------------
    */
    'market_slug' => env('DEMO_PILOT_MARKET_SLUG', 'demo-market'),
    'email_domain' => env('DEMO_PILOT_EMAIL_DOMAIN', 'demo.marketuchet.local'),
    'synthetic_source' => 'demo_pilot',
    'access_password' => env('DEMO_PILOT_ACCESS_PASSWORD'),
    'owner_emails' => env('DEMO_PILOT_OWNER_EMAILS', '321_123@bk.ru'),

    /*
    |--------------------------------------------------------------------------
    | Public demo entry
    |--------------------------------------------------------------------------
    |
    | Keep disabled by default. When enabled, the /demo page can open the
    | synthetic demo market without publishing a shared password.
    |
    */
    'public_login_enabled' => env('DEMO_PILOT_PUBLIC_LOGIN_ENABLED', false),
    'public_login_email' => env('DEMO_PILOT_PUBLIC_LOGIN_EMAIL'),
    'public_login_redirect' => env('DEMO_PILOT_PUBLIC_LOGIN_REDIRECT', '/admin/market-map'),

    /*
    |--------------------------------------------------------------------------
    | External integrations
    |--------------------------------------------------------------------------
    |
    | Demo data should not call live 1C, mail, Telegram, or other external
    | systems unless a later package enables and verifies a narrow adapter.
    |
    */
    'external_integrations_enabled' => env('DEMO_PILOT_EXTERNAL_INTEGRATIONS_ENABLED', false),
];
