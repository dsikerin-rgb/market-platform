<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Auth\PortalAccessService;
use App\Services\Marketplace\MarketplaceContextService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMarketplaceBuyerAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $marketSlug = (string) ($request->route('marketSlug') ?? '');

        if (! $user) {
            if ($marketSlug !== '') {
                return redirect()->route('marketplace.login', ['marketSlug' => $marketSlug]);
            }

            return redirect()->route('marketplace.entry');
        }

        $market = app(MarketplaceContextService::class)->resolveMarket($marketSlug);
        if (! $market) {
            abort(404);
        }

        $access = app(PortalAccessService::class);
        if (! $access->canUseMarketplaceBuyer($user, $market)) {
            abort(403);
        }

        $request->session()->put(PortalAccessService::SESSION_ACTIVE_MODE, PortalAccessService::MODE_BUYER);

        return $next($request);
    }
}
