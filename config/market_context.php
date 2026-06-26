<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | SaaS market context safety flags
    |--------------------------------------------------------------------------
    |
    | These flags are intentionally off by default. The first SaaS stage only
    | provides a single context resolver; later stages may opt into scope and
    | write guards after local/staging verification.
    |
    */
    'scope_enabled' => env('MARKET_CONTEXT_SCOPE_ENABLED', false),
    'write_guards_enabled' => env('MARKET_CONTEXT_WRITE_GUARDS_ENABLED', false),
    'strict_missing_context' => env('MARKET_CONTEXT_STRICT_MISSING', false),
    'shadow_mode' => env('MARKET_CONTEXT_SHADOW_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | Super-admin fallback
    |--------------------------------------------------------------------------
    |
    | Supported values:
    | - none: return null when a super-admin has not selected a market.
    | - first_by_id: use the first market by id.
    | - first_by_name: use the first market by name.
    |
    */
    'super_admin_fallback' => env('MARKET_CONTEXT_SUPER_ADMIN_FALLBACK', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Legacy session keys
    |--------------------------------------------------------------------------
    |
    | Keep the current keys in one place before replacing scattered reads.
    | The {panel} placeholder is expanded from Filament's current panel id.
    |
    */
    'session_keys' => [
        'dashboard_market_id',
        'filament.{panel}.selected_market_id',
        'filament_{panel}_market_id',
        'filament.{panel}.market_id',
        'filament.admin.selected_market_id',
        'filament.admin.market_id',
        'selected_market_id',
    ],
];
