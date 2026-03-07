<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\TenantReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StoreController extends BaseMarketplaceController
{
    public function show(Request $request, string $marketSlug, string $tenantSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $tenant = $this->resolveTenantByRouteKey((int) $market->id, $tenantSlug);

        $spaces = $tenant->spaces()
            ->orderByRaw('COALESCE(code, number, display_name) asc')
            ->get(['id', 'code', 'number', 'display_name', 'activity_type']);

        $hasReviewSpaceColumn = Schema::hasColumn('tenant_reviews', 'market_space_id');

        $selectedSpaceId = (int) $request->integer('space_id', 0);
        if ($selectedSpaceId > 0 && ! $spaces->contains('id', $selectedSpaceId)) {
            $selectedSpaceId = 0;
        }

        $productsQuery = MarketplaceProduct::query()
            ->publiclyVisibleInMarket((int) $market->id)
            ->where('tenant_id', (int) $tenant->id)
            ->with(['marketSpace:id,display_name,number,code']);

        if ($selectedSpaceId > 0) {
            $productsQuery->where('market_space_id', $selectedSpaceId);
        }

        $products = $productsQuery
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->paginate(24)
            ->withQueryString();

        $reviews = TenantReview::query()
            ->where('tenant_id', (int) $tenant->id)
            ->where('status', 'published')
            ->when($hasReviewSpaceColumn && $selectedSpaceId > 0, function ($query) use ($selectedSpaceId): void {
                $query->where(function ($inner) use ($selectedSpaceId): void {
                    $inner->whereNull('market_space_id')->orWhere('market_space_id', $selectedSpaceId);
                });
            })
            ->latest('created_at')
            ->limit(30)
            ->get();

        $reviewStats = [
            'count' => (int) $reviews->count(),
            'avg' => $reviews->count() > 0 ? round((float) $reviews->avg('rating'), 1) : null,
        ];

        $showcase = null;
        if ($selectedSpaceId > 0) {
            $showcase = $tenant->spaceShowcases()
                ->where('market_space_id', $selectedSpaceId)
                ->where('is_active', true)
                ->first();
        }
        if (! $showcase) {
            $showcase = $tenant->showcase()->first();
        }

        return view('marketplace.stores.show', array_merge(
            $this->sharedViewData($request, $market),
            [
                'tenant' => $tenant,
                'spaces' => $spaces,
                'selectedSpaceId' => $selectedSpaceId,
                'products' => $products,
                'reviews' => $reviews,
                'reviewStats' => $reviewStats,
                'showcase' => $showcase,
            ],
        ));
    }

    public function submitReview(Request $request, string $marketSlug, string $tenantSlug): RedirectResponse
    {
        $market = $this->resolveMarketOrFail($marketSlug);
        $tenant = $this->resolveTenantByRouteKey((int) $market->id, $tenantSlug);

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'review_text' => ['required', 'string', 'max:3000'],
            'reviewer_name' => ['nullable', 'string', 'max:120'],
            'reviewer_contact' => ['nullable', 'string', 'max:190'],
            'market_space_id' => ['nullable', 'integer'],
        ]);

        $allowedSpaceIds = $tenant->spaces()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $marketSpaceId = (int) ($validated['market_space_id'] ?? 0);
        if ($marketSpaceId > 0 && ! in_array($marketSpaceId, $allowedSpaceIds, true)) {
            $marketSpaceId = 0;
        }

        $reviewerName = trim((string) ($validated['reviewer_name'] ?? ''));
        if ($reviewerName === '' && $request->user()) {
            $reviewerName = trim((string) ($request->user()->name ?? ''));
        }

        $payload = [
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'rating' => (int) $validated['rating'],
            'reviewer_name' => $reviewerName !== '' ? $reviewerName : null,
            'reviewer_contact' => trim((string) ($validated['reviewer_contact'] ?? '')) ?: null,
            'review_text' => trim((string) $validated['review_text']),
            'status' => 'published',
        ];

        if (Schema::hasColumn('tenant_reviews', 'market_space_id')) {
            $payload['market_space_id'] = $marketSpaceId > 0 ? $marketSpaceId : null;
        }

        TenantReview::query()->create($payload);

        return back()->with('success', 'Спасибо, отзыв опубликован.');
    }

    private function resolveTenantByRouteKey(int $marketId, string $tenantRouteKey): Tenant
    {
        return Tenant::query()
            ->where('market_id', $marketId)
            ->whereHas('contracts', function ($query) use ($marketId): void {
                $query
                    ->where('market_id', $marketId)
                    ->where('is_active', true);
            })
            ->where(function ($query) use ($tenantRouteKey): void {
                $query->where('slug', $tenantRouteKey);
                if (is_numeric($tenantRouteKey)) {
                    $query->orWhere('id', (int) $tenantRouteKey);
                }
            })
            ->firstOrFail();
    }
}
