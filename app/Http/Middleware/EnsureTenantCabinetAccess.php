<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            return redirect()->route('cabinet.login');
        }

        $hasRoleAccess = method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(['merchant', 'merchant-user']);

        if (! $hasRoleAccess) {
            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole([
                'super-admin',
                'market-admin',
                'market-manager',
                'market-operator',
            ])) {
                return redirect('/admin');
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        if (! $user->tenant_id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        $tenant = $user->tenant;

        if (! $tenant) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        if ($user->market_id && $tenant->market_id && $user->market_id !== $tenant->market_id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        return $next($request);
    }
}
