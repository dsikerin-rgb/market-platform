<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceFavorite;
use App\Models\MarketplaceProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuyerFavoriteController extends BaseMarketplaceController
{
    public function toggle(Request $request, string $marketSlug, string $productSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $buyer = $request->user();
        abort_unless($buyer, 403);
        $showDemoContent = $this->marketplaceDemoContentEnabled($market);

        $product = MarketplaceProduct::query()
            ->publiclyVisibleInMarket((int) $market->id, false, $showDemoContent)
            ->where('slug', $productSlug)
            ->firstOrFail();

        $existing = MarketplaceFavorite::query()
            ->where('buyer_user_id', (int) $buyer->id)
            ->where('product_id', (int) $product->id)
            ->first();

        if ($existing) {
            $existing->delete();
            if ((int) ($product->favorites_count ?? 0) > 0) {
                $product->decrement('favorites_count');
            }

            return back()->with('success', 'Удалено из избранного.');
        }

        MarketplaceFavorite::query()->create([
            'buyer_user_id' => (int) $buyer->id,
            'product_id' => (int) $product->id,
        ]);
        $product->increment('favorites_count');

        return back()->with('success', 'Добавлено в избранное.');
    }
}
