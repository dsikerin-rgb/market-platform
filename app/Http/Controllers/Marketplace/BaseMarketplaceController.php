<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketplaceCategory;
use App\Models\User;
use App\Services\Marketplace\MarketplaceContextService;
use Illuminate\Http\Request;

abstract class BaseMarketplaceController extends Controller
{
    protected function resolveMarketOrFail(?string $marketSlug = null): Market
    {
        $market = app(MarketplaceContextService::class)->resolveMarket($marketSlug);
        abort_unless($market, 404);

        return $market;
    }

    /**
     * @return array{
     *   market: Market,
     *   topCategories: \Illuminate\Support\Collection<int, MarketplaceCategory>,
     *   marketplaceCurrentUser: ?User,
     *   marketplaceCurrentUserIsBuyer: bool,
     *   marketplaceFavoriteCount: int,
     *   marketplaceChatUnreadCount: int
     * }
     */
    protected function sharedViewData(Request $request, Market $market): array
    {
        $topCategories = MarketplaceCategory::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->where(function ($query) use ($market): void {
                $query->whereNull('market_id')->orWhere('market_id', (int) $market->id);
            })
            ->orderByRaw('CASE WHEN market_id = ? THEN 0 ELSE 1 END', [(int) $market->id])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'icon', 'market_id'])
            ->unique(static function (MarketplaceCategory $category): string {
                $name = trim((string) $category->name);
                if ($name !== '') {
                    return 'name:' . mb_strtolower($name);
                }

                $slug = trim((string) $category->slug);
                if ($slug !== '') {
                    return 'slug:' . mb_strtolower($slug);
                }

                return 'id:' . (string) $category->id;
            })
            ->values()
            ->take(16);

        $user = $request->user();
        $isBuyer = $user instanceof User && method_exists($user, 'isBuyer') && $user->isBuyer();

        $favoriteCount = 0;
        $chatUnread = 0;
        if ($isBuyer) {
            $favoriteCount = (int) $user->marketplaceFavorites()->count();
            $chatUnread = (int) $user->marketplaceBuyerChats()
                ->where('market_id', (int) $market->id)
                ->sum('buyer_unread_count');
        }

        return [
            'market' => $market,
            'topCategories' => $topCategories,
            'marketplaceCurrentUser' => $isBuyer ? $user : null,
            'marketplaceCurrentUserIsBuyer' => $isBuyer,
            'marketplaceFavoriteCount' => $favoriteCount,
            'marketplaceChatUnreadCount' => $chatUnread,
        ];
    }
}
