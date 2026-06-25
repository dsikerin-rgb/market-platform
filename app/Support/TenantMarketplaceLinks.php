<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

class TenantMarketplaceLinks
{
    public static function canOpenStore(?User $user, ?Tenant $tenant): bool
    {
        if (! $user || ! $tenant) {
            return false;
        }

        if ((int) ($tenant->id ?? 0) <= 0) {
            return false;
        }

        return AdminCapabilities::canViewMarketplaceContent($user, (int) ($tenant->market_id ?? 0));
    }

    public static function storeUrl(?Tenant $tenant): ?string
    {
        if (! $tenant) {
            return null;
        }

        $tenantRouteKey = trim((string) ($tenant->slug ?? ''));
        if ($tenantRouteKey === '') {
            $tenantId = (int) ($tenant->id ?? 0);
            $tenantRouteKey = $tenantId > 0 ? (string) $tenantId : '';
        }

        if ($tenantRouteKey === '') {
            return null;
        }

        $marketSlug = trim((string) ($tenant->market?->slug ?? ''));

        if ($marketSlug !== '') {
            return route('marketplace.store.show', [
                'marketSlug' => $marketSlug,
                'tenantSlug' => $tenantRouteKey,
            ]);
        }

        return route('cabinet.showcase.public', ['tenantSlug' => $tenantRouteKey]);
    }
}
