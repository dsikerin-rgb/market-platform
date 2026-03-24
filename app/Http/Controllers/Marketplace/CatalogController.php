<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Services\Auth\PortalAccessService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CatalogController extends BaseMarketplaceController
{
    public function index(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $allowWithoutActiveContracts = app(PortalAccessService::class)->allowsPublicSalesWithoutActiveContract($market);
        $showDemoContent = $this->marketplaceDemoContentEnabled($market);

        $query = MarketplaceProduct::query()
            ->publiclyVisibleInMarket((int) $market->id, $allowWithoutActiveContracts, $showDemoContent)
            ->with(['tenant:id,name,short_name,slug', 'category:id,name,slug']);

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($inner) use ($search): void {
                $pattern = '%' . $search . '%';
                $inner
                    ->where('title', 'like', $pattern)
                    ->orWhere('description', 'like', $pattern)
                    ->orWhere('sku', 'like', $pattern);
            });
        }

        $categorySlug = trim((string) $request->query('category', ''));
        $selectedCategory = null;
        if ($categorySlug !== '') {
            $selectedCategory = MarketplaceCategory::query()
                ->where('slug', $categorySlug)
                ->where('is_active', true)
                ->where(function ($inner) use ($market): void {
                    $inner->whereNull('market_id')->orWhere('market_id', (int) $market->id);
                })
                ->first();

            if ($selectedCategory) {
                $query->where('category_id', (int) $selectedCategory->id);
            }
        }

        $tenantSlug = trim((string) $request->query('store', ''));
        $selectedStore = null;
        if ($tenantSlug !== '') {
            $selectedStore = Tenant::query()
                ->where('market_id', (int) $market->id)
                ->where(function ($inner) use ($tenantSlug): void {
                    $inner->where('slug', $tenantSlug);
                    if (is_numeric($tenantSlug)) {
                        $inner->orWhereKey((int) $tenantSlug);
                    }
                })
                ->first();
            if ($selectedStore) {
                $query->where('tenant_id', (int) $selectedStore->id);
            }
        }

        $minPrice = $request->query('min_price');
        if (is_numeric($minPrice)) {
            $query->where('price', '>=', (float) $minPrice);
        }

        $maxPrice = $request->query('max_price');
        if (is_numeric($maxPrice)) {
            $query->where('price', '<=', (float) $maxPrice);
        }

        $sort = (string) $request->query('sort', 'new');
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('price')->orderByDesc('id');
                break;
            case 'price_desc':
                $query->orderByDesc('price')->orderByDesc('id');
                break;
            case 'popular':
                $query->orderByDesc('favorites_count')->orderByDesc('views_count')->orderByDesc('id');
                break;
            default:
                $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $products = $query->paginate(24)->withQueryString();

        $categories = MarketplaceCategory::query()
            ->where('is_active', true)
            ->where(function ($inner) use ($market): void {
                $inner->whereNull('market_id')->orWhere('market_id', (int) $market->id);
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id']);

        $stores = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->whereHas('marketplaceProducts', function ($q) use ($market, $allowWithoutActiveContracts, $showDemoContent): void {
                $q->publiclyVisibleInMarket((int) $market->id, $allowWithoutActiveContracts, $showDemoContent);
            })
            ->withCount(['marketplaceProducts as active_products_count' => function ($q) use ($market, $allowWithoutActiveContracts, $showDemoContent): void {
                $q->publiclyVisibleInMarket((int) $market->id, $allowWithoutActiveContracts, $showDemoContent);
            }])
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'short_name', 'slug']);

        return view('marketplace.catalog.index', array_merge(
            $this->sharedViewData($request, $market),
            [
                'products' => $products,
                'categories' => $categories,
                'stores' => $stores,
                'selectedCategory' => $selectedCategory,
                'selectedStore' => $selectedStore,
                'search' => $search,
                'sort' => $sort,
                'minPrice' => $minPrice,
                'maxPrice' => $maxPrice,
            ],
        ));
    }
}
