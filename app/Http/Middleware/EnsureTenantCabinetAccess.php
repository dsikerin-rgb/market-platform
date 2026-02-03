<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantCabinetAccess
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $hasRoleAccess = method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['merchant', 'merchant-user']);

        if (! $hasRoleAccess) {
            abort(403);
        }

        if (! $user->tenant_id) {
            abort(403);
        }

        $tenant = $user->tenant;

        if (! $tenant) {
            abort(403);
        }

        if ($user->market_id && $tenant->market_id && $user->market_id !== $tenant->market_id) {
            abort(403);
        }

        return $next($request);
    }
}
