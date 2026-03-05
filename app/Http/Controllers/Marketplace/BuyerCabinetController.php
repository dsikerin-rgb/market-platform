<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceChat;
use App\Models\MarketplaceFavorite;
use App\Models\MarketplaceProduct;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BuyerCabinetController extends BaseMarketplaceController
{
    public function dashboard(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);

        $favoritesCount = (int) MarketplaceFavorite::query()
            ->where('buyer_user_id', (int) $buyer->id)
            ->whereHas('product', fn ($q) => $q->where('market_id', (int) $market->id))
            ->count();

        $openChatsCount = (int) MarketplaceChat::query()
            ->where('buyer_user_id', (int) $buyer->id)
            ->where('market_id', (int) $market->id)
            ->where('status', 'open')
            ->count();

        $latestFavorites = MarketplaceProduct::query()
            ->whereHas('favorites', fn ($q) => $q->where('buyer_user_id', (int) $buyer->id))
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->with(['tenant:id,name,short_name,slug'])
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        $latestChats = MarketplaceChat::query()
            ->where('buyer_user_id', (int) $buyer->id)
            ->where('market_id', (int) $market->id)
            ->with([
                'tenant:id,name,short_name,slug',
                'product:id,title,slug',
                'messages' => fn ($q) => $q->latest('id')->limit(1),
            ])
            ->orderByDesc('last_message_at')
            ->limit(12)
            ->get();

        return view('marketplace.buyer.dashboard', array_merge(
            $this->sharedViewData($request, $market),
            [
                'favoritesCount' => $favoritesCount,
                'openChatsCount' => $openChatsCount,
                'latestFavorites' => $latestFavorites,
                'latestChats' => $latestChats,
            ],
        ));
    }

    public function favorites(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);

        $products = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->whereHas('favorites', fn ($q) => $q->where('buyer_user_id', (int) $buyer->id))
            ->with(['tenant:id,name,short_name,slug'])
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        return view('marketplace.buyer.favorites', array_merge(
            $this->sharedViewData($request, $market),
            [
                'products' => $products,
            ],
        ));
    }
}

