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

    private const MARKET_EVENT_VIEWERS = [
        'market-owner',
        'market-owner-director',
        'market-admin',
    ];

    private const MARKET_EVENT_CREATORS = [
        'market-owner',
        'market-owner-director',
        'market-admin',
    ];

    private const MARKET_EVENT_MANAGERS = [
        'market-owner-director',
        'market-admin',
    ];

    private const MARKET_EVENT_DELETERS = [
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

    public static function canViewMarketEvents(?User $user, ?int $marketId = null): bool
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

        return self::hasAnyRole($user, self::MARKET_EVENT_VIEWERS)
            || self::can($user, 'market-holidays.viewAny')
            || self::can($user, 'market-holidays.view')
            || self::can($user, 'market-holidays.create')
            || self::can($user, 'market-holidays.update')
            || self::can($user, 'market-holidays.delete');
    }

    public static function canCreateMarketEvents(?User $user, ?int $marketId = null): bool
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

        return self::hasAnyRole($user, self::MARKET_EVENT_CREATORS)
            || self::can($user, 'market-holidays.create')
            || self::can($user, 'market-holidays.update')
            || self::can($user, 'market-holidays.delete');
    }

    public static function canUpdateMarketEvents(?User $user, ?int $marketId = null): bool
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

        return self::hasAnyRole($user, self::MARKET_EVENT_MANAGERS)
            || self::can($user, 'market-holidays.update')
            || self::can($user, 'market-holidays.delete');
    }

    public static function canDeleteMarketEvents(?User $user, ?int $marketId = null): bool
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

        return self::hasAnyRole($user, self::MARKET_EVENT_DELETERS)
            || self::can($user, 'market-holidays.delete');
    }

    public static function canViewMarketLocations(?User $user, ?int $marketId = null): bool
    {
        return self::canViewMarketDirectory($user, $marketId)
            || self::canScoped($user, $marketId, [
                'market-locations.viewAny',
                'market-locations.view',
                'market-locations.create',
                'market-locations.update',
                'market-locations.delete',
            ]);
    }

    public static function canManageMarketLocations(?User $user, ?int $marketId = null): bool
    {
        return self::canManageMarketDirectory($user, $marketId)
            || self::canScoped($user, $marketId, [
                'market-locations.create',
                'market-locations.update',
                'market-locations.delete',
            ])
            || self::hasMarketScope($user, $marketId);
    }

    public static function canViewMarketLocationTypes(?User $user, ?int $marketId = null): bool
    {
        return self::canViewMarketDirectory($user, $marketId)
            || self::canScoped($user, $marketId, [
                'market-location-types.viewAny',
                'market-location-types.view',
                'market-location-types.create',
                'market-location-types.update',
                'market-location-types.delete',
            ]);
    }

    public static function canManageMarketLocationTypes(?User $user, ?int $marketId = null): bool
    {
        return self::canManageMarketDirectory($user, $marketId)
            || self::canScoped($user, $marketId, [
                'market-location-types.create',
                'market-location-types.update',
                'market-location-types.delete',
            ])
            || self::hasMarketScope($user, $marketId);
    }

    public static function canViewReports(?User $user, ?int $marketId = null): bool
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

        return self::can($user, 'reports.viewAny')
            || self::can($user, 'reports.view')
            || self::can($user, 'reports.create')
            || self::can($user, 'reports.update')
            || self::can($user, 'reports.delete')
            || self::canViewFinance($user, $marketId);
    }

    public static function canManageReports(?User $user, ?int $marketId = null): bool
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

        return self::can($user, 'reports.create')
            || self::can($user, 'reports.update')
            || self::can($user, 'reports.delete');
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

    /**
     * @param list<string> $permissions
     */
    private static function canScoped(?User $user, ?int $marketId, array $permissions): bool
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

        foreach ($permissions as $permission) {
            if (self::can($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    private static function hasMarketScope(?User $user, ?int $marketId = null): bool
    {
        if (! $user) {
            return false;
        }

        return self::sameMarket($user, $marketId);
    }
}
