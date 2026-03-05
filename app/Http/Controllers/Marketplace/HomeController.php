<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Models\MarketplaceAnnouncement;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends BaseMarketplaceController
{
    public function __invoke(Request $request, string $marketSlug): View
    {
        $market = $this->resolveMarketOrFail($marketSlug);

        $featuredProducts = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->where('is_featured', true)
            ->with(['tenant:id,name,short_name,slug', 'category:id,name,slug'])
            ->orderByDesc('published_at')
            ->limit(12)
            ->get();

        $latestProducts = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->with(['tenant:id,name,short_name,slug'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(16)
            ->get();

        $today = CarbonImmutable::today();
        $upcomingWindowEnd = $today->addMonths(2);
        $hideSanitaryInFeed = (bool) config('marketplace.home.hide_sanitary_in_feed', true);
        $showSanitaryWarning = (bool) config('marketplace.home.sanitary_warning_enabled', true);

        $nearestSanitaryAnnouncement = null;

        if ($showSanitaryWarning) {
            $tomorrow = $today->addDay();

            $nearestSanitaryAnnouncement = MarketplaceAnnouncement::query()
                ->where('market_id', (int) $market->id)
                ->where('is_active', true)
                ->where('kind', 'sanitary_day')
                ->where(function ($query) use ($today, $tomorrow): void {
                    $query->whereDate('starts_at', '=', $tomorrow)
                        ->orWhere(function ($activeQuery) use ($today): void {
                            $activeQuery->where(function ($singleDayQuery) use ($today): void {
                                $singleDayQuery
                                    ->whereNull('ends_at')
                                    ->whereDate('starts_at', '=', $today);
                            })->orWhere(function ($rangeQuery) use ($today): void {
                                $rangeQuery
                                    ->whereNotNull('ends_at')
                                    ->whereDate('starts_at', '<=', $today)
                                    ->whereDate('ends_at', '>=', $today);
                            });
                        });
                })
                ->orderBy('starts_at')
                ->orderBy('id')
                ->first(['id', 'kind', 'title', 'slug', 'excerpt', 'cover_image', 'starts_at', 'ends_at']);
        }

        $announcementsQuery = MarketplaceAnnouncement::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->whereDate('starts_at', '>=', $today->toDateString())
            ->whereDate('starts_at', '<=', $upcomingWindowEnd->toDateString());

        if ($hideSanitaryInFeed) {
            $announcementsQuery->where('kind', '!=', 'sanitary_day');
        }

        $announcements = $announcementsQuery
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(6)
            ->get(['id', 'kind', 'title', 'slug', 'excerpt', 'cover_image', 'starts_at', 'ends_at']);

        $topStores = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->whereHas('marketplaceProducts', function ($query): void {
                $query->where('is_active', true);
            })
            ->withCount(['marketplaceProducts as active_products_count' => function ($query): void {
                $query->where('is_active', true);
            }])
            ->orderByDesc('active_products_count')
            ->limit(12)
            ->get(['id', 'name', 'short_name', 'slug']);

        return view('marketplace.home', array_merge(
            $this->sharedViewData($request, $market),
            [
                'featuredProducts' => $featuredProducts,
                'latestProducts' => $latestProducts,
                'announcements' => $announcements,
                'nearestSanitaryAnnouncement' => $nearestSanitaryAnnouncement,
                'topStores' => $topStores,
            ],
        ));
    }
}
