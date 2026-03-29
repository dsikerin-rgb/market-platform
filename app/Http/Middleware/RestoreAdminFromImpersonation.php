<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Cabinet\TenantImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RestoreAdminFromImpersonation
{
    /**
     * Keep admin routes bound to the original admin during tenant impersonation.
     *
     * The cabinet uses the shared web guard session and switches the session user
     * to the tenant account. For /admin requests we restore the impersonator only
     * for the current request, so the seller cabinet remains active elsewhere.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $path = (string) $request->path();

        if ($path !== 'admin' && ! str_starts_with($path, 'admin/')) {
            return $next($request);
        }

        $context = $request->session()->get(TenantImpersonationService::SESSION_KEY);

        if (! is_array($context)) {
            return $next($request);
        }

        $impersonatorId = (int) ($context['impersonator_user_id'] ?? 0);

        if ($impersonatorId <= 0) {
            return $next($request);
        }

        $currentUser = Auth::user();

        if ($currentUser instanceof User && $currentUser->hasAnyRole([
            'super-admin',
            'market-admin',
            'market-manager',
            'market-operator',
        ])) {
            return $next($request);
        }

        $impersonator = User::query()->find($impersonatorId);

        if (! $impersonator || ! $impersonator->hasAnyRole([
            'super-admin',
            'market-admin',
            'market-manager',
            'market-operator',
        ])) {
            return $next($request);
        }

        Auth::shouldUse('web');
        Auth::guard('web')->setUser($impersonator);
        $request->setUserResolver(static fn (): User => $impersonator);

        return $next($request);
    }
}
