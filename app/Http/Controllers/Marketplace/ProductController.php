<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceProduct;
use App\Models\TenantReview;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends BaseMarketplaceController
{
    public function show(Request $request, string $marketSlug, string $productSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        $product = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('slug', $productSlug)
            ->where('is_active', true)
            ->with([
                'tenant:id,name,short_name,slug,market_id',
                'category:id,name,slug',
                'marketSpace:id,display_name,number,code',
            ])
            ->firstOrFail();

        $product->increment('views_count');

        $reviews = TenantReview::query()
            ->where('tenant_id', (int) $product->tenant_id)
            ->where('status', 'published')
            ->when((int) ($product->market_space_id ?? 0) > 0, function ($query) use ($product): void {
                $query->where(function ($inner) use ($product): void {
                    $inner->whereNull('market_space_id')
                        ->orWhere('market_space_id', (int) $product->market_space_id);
                });
            })
            ->latest('created_at')
            ->limit(20)
            ->get();

        $relatedProducts = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->whereKeyNot((int) $product->id)
            ->where(function ($query) use ($product): void {
                $query
                    ->where('category_id', (int) ($product->category_id ?? 0))
                    ->orWhere('tenant_id', (int) $product->tenant_id);
            })
            ->with(['tenant:id,name,short_name,slug'])
            ->orderByDesc('favorites_count')
            ->orderByDesc('published_at')
            ->limit(12)
            ->get();

        $favoriteExists = false;
        $user = $request->user();
        if ($user && method_exists($user, 'isBuyer') && $user->isBuyer()) {
            $favoriteExists = $user->marketplaceFavorites()
                ->where('product_id', (int) $product->id)
                ->exists();
        }

        return view('marketplace.products.show', array_merge(
            $this->sharedViewData($request, $market),
            [
                'product' => $product,
                'reviews' => $reviews,
                'relatedProducts' => $relatedProducts,
                'favoriteExists' => $favoriteExists,
            ],
        ));
    }
}

