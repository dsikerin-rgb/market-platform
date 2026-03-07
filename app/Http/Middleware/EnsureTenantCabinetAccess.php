<?php

namespace App\Http\Middleware;

use App\Services\Auth\PortalAccessService;
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
        $access = app(PortalAccessService::class);

        if (! $user) {
            return redirect()->route('cabinet.login');
        }

        if (! $access->canUseSellerCabinet($user)) {
            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole([
                'super-admin',
                'market-admin',
                'market-manager',
                'market-operator',
            ])) {
                return redirect('/admin');
            }

            $marketSlug = $access->resolveUserMarketRouteKey($user);
            if ($marketSlug !== null && $access->canUseMarketplaceBuyer($user, $access->resolveUserMarket($user))) {
                $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_BUYER);

                return redirect()->route('marketplace.buyer.dashboard', ['marketSlug' => $marketSlug]);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('cabinet.login');
        }

        $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_SELLER);

        return $next($request);
    }
}
