<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantReview;
use App\Services\Auth\PortalAccessService;
use Illuminate\View\View;

class PublicShowcaseController extends Controller
{
    public function __invoke(string $tenantSlug): View
    {
        $tenant = Tenant::query()
            ->where('slug', $tenantSlug)
            ->firstOrFail();

        $market = $tenant->market()->select(['id', 'settings'])->first();
        $access = app(PortalAccessService::class);
        $allowWithoutActiveContracts = $access->allowsPublicSalesWithoutActiveContract($market);

        if (! $allowWithoutActiveContracts) {
            $hasActiveContract = $tenant->contracts()
                ->where('market_id', (int) ($tenant->market_id ?? 0))
                ->where('is_active', true)
                ->exists();

            abort_unless($hasActiveContract, 404);
        }

        $spaces = MarketSpace::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $tenant->market_id))
            ->orderByRaw('COALESCE(code, number, display_name) asc')
            ->get(['id', 'code', 'number', 'display_name']);

        $requestedSpaceId = (int) request()->integer('space_id', 0);
        $selectedSpace = $requestedSpaceId > 0 ? $spaces->firstWhere('id', $requestedSpaceId) : null;
        $selectedSpaceId = $selectedSpace ? (int) $selectedSpace->id : null;

        $showcase = $selectedSpace
            ? $tenant->spaceShowcases()
                ->where('market_space_id', (int) $selectedSpace->id)
                ->where('is_active', true)
                ->first()
            : null;

        if (! $showcase) {
            $showcase = $tenant->showcase()->first();
        }

        $reviews = TenantReview::query()
            ->where('tenant_id', (int) $tenant->id)
            ->when((int) ($tenant->market_id ?? 0) > 0, fn ($query) => $query->where('market_id', (int) $tenant->market_id))
            ->where('status', 'published')
            ->when($selectedSpaceId, fn ($query) => $query->where('market_space_id', $selectedSpaceId))
            ->latest('created_at')
            ->limit(20)
            ->get();

        return view('cabinet.showcase.public', [
            'tenant' => $tenant,
            'showcase' => $showcase,
            'spaces' => $spaces,
            'selectedSpaceId' => $selectedSpaceId,
            'reviews' => $reviews,
        ]);
    }
}
