<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GigaChat Configuration — Market Platform
    |--------------------------------------------------------------------------
    |
    | All credentials are read from .env. No fallback to other projects.
    | This config is isolated to Market Platform only.
    |
    */

    'auth_key' => env('GIGACHAT_AUTH_KEY'),

    'scope' => env('GIGACHAT_SCOPE', 'GIGACHAT_API_PERS'),

    'model' => env('GIGACHAT_MODEL', 'GigaChat'),

    'diag_model' => env('GIGACHAT_DIAG_MODEL', 'GigaChat'),

    /**
     * SSL verification для GigaChat API.
     * Local: можно false для отладки.
     * Staging/Prod: всегда true.
     */
    'verify_ssl' => (bool) env('GIGACHAT_VERIFY_SSL', true),

];
