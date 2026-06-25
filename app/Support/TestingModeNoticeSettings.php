<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Market;
use App\Models\User;

final class TestingModeNoticeSettings
{
    public const SETTINGS_KEY = 'testing_mode_notice';
    public const ENABLED_KEY = 'enabled';

    public function enabledForUser(?User $user): bool
    {
        return $this->enabledForMarket($this->resolveMarketForUser($user));
    }

    public function enabledForMarket(?Market $market): bool
    {
        if (! $market) {
            return true;
        }

        $settings = (array) ($market->settings ?? []);
        $notice = (array) ($settings[self::SETTINGS_KEY] ?? []);

        return array_key_exists(self::ENABLED_KEY, $notice)
            ? (bool) $notice[self::ENABLED_KEY]
            : true;
    }

    private function resolveMarketForUser(?User $user): ?Market
    {
        if (! $user) {
            return null;
        }

        if (! $user->exists) {
            return null;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            $marketId = $this->selectedMarketIdFromSession();

            return $marketId
                ? Market::query()->select(['id', 'settings'])->find($marketId)
                : Market::query()->select(['id', 'settings'])->orderBy('id')->first();
        }

        $marketId = (int) ($user->market_id ?? 0);

        return $marketId > 0
            ? Market::query()->select(['id', 'settings'])->find($marketId)
            : null;
    }

    private function selectedMarketIdFromSession(): ?int
    {
        return app(MarketContext::class)->selectedMarketIdFromSession();
    }
}
