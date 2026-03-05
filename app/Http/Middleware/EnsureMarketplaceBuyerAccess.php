<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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

        if (! method_exists($user, 'isBuyer') || ! $user->isBuyer()) {
            abort(403);
        }

        $market = app(MarketplaceContextService::class)->resolveMarket($marketSlug);
        if (! $market) {
            abort(404);
        }

        if ((int) ($user->market_id ?? 0) > 0 && (int) $user->market_id !== (int) $market->id) {
            abort(403);
        }

        return $next($request);
    }
}

