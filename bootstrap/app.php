<?php

# bootstrap/app.php

declare(strict_types=1);

use App\Http\Middleware\RestoreAdminFromImpersonation;
use App\Http\Middleware\RedirectAdminTokenMismatch;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        /**
         * ВАЖНО:
         * SplitSessionCookieByPath УДАЛЁН.
         * Используем одну сессию для всех контекстов.
         */

        // Локаль не завязана на сессии — оставляем в web-стеке.
        $middleware->web(prepend: [
            RedirectAdminTokenMismatch::class,
        ], append: [
            SetLocale::class,
            RestoreAdminFromImpersonation::class,
        ]);

        $middleware->alias([
            'cabinet.access' => \App\Http\Middleware\EnsureTenantCabinetAccess::class,
            'marketplace.buyer' => \App\Http\Middleware\EnsureMarketplaceBuyerAccess::class,
            'marketplace.ready' => \App\Http\Middleware\EnsureMarketplaceSchemaReady::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
