<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Throwable;

class AdminCapabilities
{
    private const MARKET_DIRECTORY_MANAGERS = [
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
    ];

    private const TENANT_FULL_VIEWERS = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const TENANT_SERVICE_VIEWERS = [
        'market-operator',
        'market-maintenance',
        'market-engineer',
        'market-support',
        'market-security',
        'market-guard',
    ];

    private const FINANCE_VIEWERS = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const MARKET_SETTINGS_MANAGERS = [
        'market-owner-director',
        'market-admin',
    ];

    private const TENANT_CONTRACT_VIEWERS = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const TENANT_CONTRACT_MANAGERS = [
        'market-owner-director',
        'market-admin',
        'market-legal-admin',
    ];

    public static function canViewMarketDirectory(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        return self::sameMarket($user, $marketId);
    }

    public static function canViewTenantDirectory(?User $user, ?int $marketId = null): bool
    {
        return self::canViewFullTenantProfile($user, $marketId)
            || self::canViewTenantServiceContext($user, $marketId);
    }

    public static function canViewFullTenantProfile(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::sameMarket($user, $marketId)) {
            return false;
        }

        return self::hasAnyRole($user, self::TENANT_FULL_VIEWERS)
            || self::can($user, 'markets.view')
            || self::can($user, 'markets.viewAny')
            || self::can($user, 'markets.update');
    }

    public static function canViewTenantServiceContext(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::sameMarket($user, $marketId)) {
            return false;
        }

        return self::hasAnyRole($user, self::TENANT_SERVICE_VIEWERS)
            || self::can($user, 'tenants.service-view');
    }

    public static function canManageMarketDirectory(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::sameMarket($user, $marketId)) {
            return false;
        }

        return self::hasAnyRole($user, self::MARKET_DIRECTORY_MANAGERS)
            || self::can($user, 'markets.update');
    }

    public static function canViewFinance(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::sameMarket($user, $marketId)) {
            return false;
        }

        return self::hasAnyRole($user, self::FINANCE_VIEWERS)
            || self::can($user, 'finance.1c.view')
            || self::can($user, 'finance.accruals.view');
    }

    public static function canViewTenantContracts(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::sameMarket($user, $marketId)) {
            return false;
        }

        return self::hasAnyRole($user, self::TENANT_CONTRACT_VIEWERS)
            || self::can($user, 'contracts.update');
    }

    public static function canManageTenantContracts(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::sameMarket($user, $marketId)) {
            return false;
        }

        return self::hasAnyRole($user, self::TENANT_CONTRACT_MANAGERS)
            || self::can($user, 'contracts.update');
    }

    public static function canAccessMarketSettings(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        return (self::sameMarket($user) && self::hasAnyRole($user, self::MARKET_SETTINGS_MANAGERS))
            || self::can($user, 'market-settings.view')
            || self::can($user, 'market-settings.update')
            || self::can($user, 'markets.update');
    }

    public static function canUpdateMarketSettings(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        return (self::sameMarket($user) && self::hasAnyRole($user, self::MARKET_SETTINGS_MANAGERS))
            || self::can($user, 'market-settings.update')
            || self::can($user, 'markets.update');
    }

    private static function isSuperAdmin(User $user): bool
    {
        return method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();
    }

    private static function sameMarket(User $user, ?int $marketId = null): bool
    {
        $userMarketId = (int) ($user->market_id ?? 0);
        if ($userMarketId <= 0) {
            return false;
        }

        if ($marketId === null || $marketId <= 0) {
            return true;
        }

        return $userMarketId === $marketId;
    }

    /**
     * @param list<string> $roles
     */
    private static function hasAnyRole(User $user, array $roles): bool
    {
        if (method_exists($user, 'hasAnyRole')) {
            return $user->hasAnyRole(...$roles);
        }

        if (! method_exists($user, 'hasRole')) {
            return false;
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private static function can(User $user, string $permission): bool
    {
        try {
            return method_exists($user, 'can') && $user->can($permission);
        } catch (Throwable) {
            return false;
        }
    }
}
