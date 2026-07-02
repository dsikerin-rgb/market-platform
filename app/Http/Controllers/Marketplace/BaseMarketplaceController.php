<?php

declare(strict_types=1);

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Market;
use App\Models\MarketplaceCategory;
use App\Models\User;
use App\Services\Auth\PortalAccessService;
use App\Services\Marketplace\MarketplaceDemoContentService;
use App\Services\Marketplace\MarketplaceContextService;
use App\Support\MarketplaceSettingsValue;
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
        $defaults = $this->defaultMarketplaceSettings($market);

        $interval = is_numeric($raw['slider_autoplay_interval_ms'] ?? null)
            ? (int) $raw['slider_autoplay_interval_ms']
            : 7000;

        return [
            'brand_name' => MarketplaceSettingsValue::string($raw['brand_name'] ?? null, $defaults['brand_name']) ?: $defaults['brand_name'],
            'logo_path' => MarketplaceSettingsValue::string($raw['logo_path'] ?? null, $defaults['logo_path']),
            'hero_eyebrow' => MarketplaceSettingsValue::string($raw['hero_eyebrow'] ?? null, $defaults['hero_eyebrow']) ?: $defaults['hero_eyebrow'],
            'hero_title' => MarketplaceSettingsValue::string($raw['hero_title'] ?? null, $defaults['hero_title']) ?: $defaults['hero_title'],
            'hero_subtitle' => MarketplaceSettingsValue::string($raw['hero_subtitle'] ?? null, $defaults['hero_subtitle']) ?: $defaults['hero_subtitle'],
            'market_public_label' => MarketplaceSettingsValue::string($raw['market_public_label'] ?? null, $defaults['market_public_label']) ?: $defaults['market_public_label'],
            'public_phone' => MarketplaceSettingsValue::string($raw['public_phone'] ?? null, config('marketplace.brand.public_phone', '')),
            'public_email' => MarketplaceSettingsValue::string($raw['public_email'] ?? null, config('marketplace.brand.public_email', '')),
            'public_address' => MarketplaceSettingsValue::string($raw['public_address'] ?? null, $market->address ?? config('marketplace.brand.public_address', '')),
            'slider_enabled' => array_key_exists('slider_enabled', $raw) ? (bool) $raw['slider_enabled'] : true,
            'slider_autoplay_enabled' => array_key_exists('slider_autoplay_enabled', $raw) ? (bool) $raw['slider_autoplay_enabled'] : true,
            'slider_autoplay_interval_ms' => max(4000, min($interval, 20000)),
            'legacy_site_merge_enabled' => array_key_exists('legacy_site_merge_enabled', $raw)
                ? (bool) $raw['legacy_site_merge_enabled']
                : (bool) config('marketplace.brand.legacy_site_merge_enabled', true),
            'allow_public_sales_without_active_contracts' => array_key_exists('allow_public_sales_without_active_contracts', $raw)
                ? (bool) $raw['allow_public_sales_without_active_contracts']
                : (bool) config('marketplace.contracts.allow_public_sales_without_active_contracts', false),
            'demo_content_enabled' => array_key_exists('demo_content_enabled', $raw)
                ? (bool) $raw['demo_content_enabled']
                : (bool) config('marketplace.demo_content_enabled', false),
        ];
    }

    /**
     * @return array{brand_name:string, logo_path:?string, hero_eyebrow:string, hero_title:string, hero_subtitle:string, market_public_label:string}
     */
    protected function defaultMarketplaceSettings(Market $market): array
    {
        if ($this->isSyntheticDemoMarket($market)) {
            return [
                'brand_name' => 'Демо-рынок Центральный',
                'logo_path' => 'marketplace/brand/demo-market-logo.svg',
                'hero_eyebrow' => 'Демонстрационный рынок',
                'hero_title' => 'Покупки на демо-рынке в одном месте',
                'hero_subtitle' => 'Единая витрина товаров, карта демо-рынка, прямой чат с продавцами, отзывы и анонсы мероприятий.',
                'market_public_label' => 'рынка «Демо-рынок Центральный»',
            ];
        }

        return [
            'brand_name' => 'Маркетплейс Экоярмарки',
            'logo_path' => null,
            'hero_eyebrow' => 'Городская Экоярмарка',
            'hero_title' => 'Покупки на Экоярмарке в одном месте',
            'hero_subtitle' => 'Единая витрина товаров, карта Экоярмарки, прямой чат с продавцами, отзывы и анонсы мероприятий.',
            'market_public_label' => 'Экоярмарки',
        ];
    }

    protected function isSyntheticDemoMarket(Market $market): bool
    {
        $source = trim((string) data_get($market->settings, 'demo_pilot.synthetic_source', ''));
        $expectedSource = trim((string) config('demo_pilot.synthetic_source', 'demo_pilot'));

        if ($source !== '' && $source === $expectedSource) {
            return true;
        }

        $demoSlug = trim((string) config('demo_pilot.market_slug', 'demo-market'));

        return $demoSlug !== '' && (string) ($market->slug ?? '') === $demoSlug;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    protected function resolveMarketplaceLogoUrl(array $settings): string
    {
        $value = MarketplaceSettingsValue::string($settings['logo_path'] ?? null);

        if ($value === '') {
            return asset('marketplace/brand/eko-fair-logo.svg');
        }

        if (Str::startsWith($value, ['http://', 'https://', 'data:', '/'])) {
            return $value;
        }

        if (! str_contains($value, '..') && ! str_contains($value, '\\') && is_file(public_path($value))) {
            return asset($value);
        }

        return Storage::disk('public')->url($value);
    }

    protected function marketplaceDemoContentEnabled(Market $market): bool
    {
        return app(MarketplaceDemoContentService::class)->isEnabled($market);
    }
}
