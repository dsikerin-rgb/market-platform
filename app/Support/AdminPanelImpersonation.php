<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\Cabinet\TenantImpersonationService;
use Illuminate\Http\Request;

final class AdminPanelImpersonation
{
    public static function resolveAdminUser(?User $user = null, ?Request $request = null): ?User
    {
        if ($user instanceof User && self::hasAdminPanelRole($user)) {
            return $user;
        }

        $request ??= request();

        if (! $request || ! $request->hasSession()) {
            return $user;
        }

        $context = $request->session()->get(TenantImpersonationService::SESSION_KEY);

        if (! is_array($context)) {
            return $user;
        }

        $impersonatorId = (int) ($context['impersonator_user_id'] ?? 0);

        if ($impersonatorId <= 0) {
            return $user;
        }

        $impersonator = User::query()->find($impersonatorId);

        if (! $impersonator || ! self::hasAdminPanelRole($impersonator)) {
            return $user;
        }

        return $impersonator;
    }

    public static function hasAdminPanelRole(?User $user): bool
    {
        return $user instanceof User
            && $user->hasAnyRole([
                'super-admin',
                'market-admin',
                'market-manager',
                'market-operator',
            ]);
    }
}
