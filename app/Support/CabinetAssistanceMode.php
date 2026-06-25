<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Cabinet\TenantImpersonationService;
use Illuminate\Http\Request;

class CabinetAssistanceMode
{
    public const MODE_FULL = 'full';
    public const MODE_MARKETPLACE_HELP = 'marketplace_help';

    public static function isMarketplaceHelp(?Request $request = null): bool
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return false;
        }

        $context = $request->session()->get(TenantImpersonationService::SESSION_KEY);

        return is_array($context)
            && (string) ($context['access_mode'] ?? self::MODE_FULL) === self::MODE_MARKETPLACE_HELP;
    }

    public static function canViewFinance(?Request $request = null): bool
    {
        return ! self::isMarketplaceHelp($request);
    }
}
