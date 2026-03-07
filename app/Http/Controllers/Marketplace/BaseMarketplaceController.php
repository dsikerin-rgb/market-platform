<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketplaceCategory;
use App\Models\User;
use App\Services\Auth\PortalAccessService;
use App\Services\Marketplace\MarketplaceContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
     *   marketplaceCurrentUserCanUseBuyer: bool,
     *   marketplaceCurrentUserCanUseSeller: bool,
     *   marketplaceCurrentUserCanSellPublicly: bool,
     *   marketplaceFavoriteCount: int,
     *   marketplaceChatUnreadCount: int,
     *   marketplaceSettings: array<string,mixed>,
     *   marketplaceBrandName: string,
     *   marketplaceLogoUrl: string
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
        $access = app(PortalAccessService::class);

        $canUseBuyer = $user instanceof User && $access->canUseMarketplaceBuyer($user, $market);
        $canUseSeller = $user instanceof User && $access->canUseSellerCabinet($user);
        $canSellPublicly = $user instanceof User && $access->canSellOnMarketplace($user, $market);
        $portalUser = ($canUseBuyer || $canUseSeller) && $user instanceof User ? $user : null;

        $favoriteCount = 0;
        $chatUnread = 0;
        if ($canUseBuyer && $user instanceof User) {
            $favoriteCount = (int) $user->marketplaceFavorites()->count();
            $chatUnread = (int) $user->marketplaceBuyerChats()
                ->where('market_id', (int) $market->id)
                ->sum('buyer_unread_count');
        }

        $marketplaceSettings = $this->resolveMarketplaceSettings($market);

        return [
            'market' => $market,
            'topCategories' => $topCategories,
            'marketplaceCurrentUser' => $portalUser,
            'marketplaceCurrentUserIsBuyer' => $canUseBuyer,
            'marketplaceCurrentUserCanUseBuyer' => $canUseBuyer,
            'marketplaceCurrentUserCanUseSeller' => $canUseSeller,
            'marketplaceCurrentUserCanSellPublicly' => $canSellPublicly,
            'marketplaceFavoriteCount' => $favoriteCount,
            'marketplaceChatUnreadCount' => $chatUnread,
            'marketplaceSettings' => $marketplaceSettings,
            'marketplaceBrandName' => (string) ($marketplaceSettings['brand_name'] ?? 'Маркетплейс Экоярмарки'),
            'marketplaceLogoUrl' => $this->resolveMarketplaceLogoUrl($marketplaceSettings),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveMarketplaceSettings(Market $market): array
    {
        $raw = (array) (($market->settings ?? [])['marketplace'] ?? []);

        $interval = is_numeric($raw['slider_autoplay_interval_ms'] ?? null)
            ? (int) $raw['slider_autoplay_interval_ms']
            : 7000;

        return [
            'brand_name' => trim((string) ($raw['brand_name'] ?? '')) ?: 'Маркетплейс Экоярмарки',
            'logo_path' => trim((string) ($raw['logo_path'] ?? '')),
            'hero_title' => trim((string) ($raw['hero_title'] ?? '')) ?: 'Покупки на Экоярмарке в одном месте',
            'hero_subtitle' => trim((string) ($raw['hero_subtitle'] ?? '')) ?: 'Единая витрина товаров, карта Экоярмарки, прямой чат с продавцами, отзывы и анонсы мероприятий.',
            'public_phone' => trim((string) ($raw['public_phone'] ?? config('marketplace.brand.public_phone', ''))),
            'public_email' => trim((string) ($raw['public_email'] ?? config('marketplace.brand.public_email', ''))),
            'public_address' => trim((string) ($raw['public_address'] ?? ($market->address ?? config('marketplace.brand.public_address', '')))),
            'slider_enabled' => array_key_exists('slider_enabled', $raw) ? (bool) $raw['slider_enabled'] : true,
            'slider_autoplay_enabled' => array_key_exists('slider_autoplay_enabled', $raw) ? (bool) $raw['slider_autoplay_enabled'] : true,
            'slider_autoplay_interval_ms' => max(4000, min($interval, 20000)),
            'legacy_site_merge_enabled' => array_key_exists('legacy_site_merge_enabled', $raw)
                ? (bool) $raw['legacy_site_merge_enabled']
                : (bool) config('marketplace.brand.legacy_site_merge_enabled', true),
            'allow_public_sales_without_active_contracts' => array_key_exists('allow_public_sales_without_active_contracts', $raw)
                ? (bool) $raw['allow_public_sales_without_active_contracts']
                : (bool) config('marketplace.contracts.allow_public_sales_without_active_contracts', false),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function resolveMarketplaceLogoUrl(array $settings): string
    {
        $value = trim((string) ($settings['logo_path'] ?? ''));

        if ($value === '') {
            return asset('marketplace/brand/eko-fair-logo.svg');
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:', '/'])) {
            return $value;
        }

        return Storage::disk('public')->url($value);
    }
}
