<?php

declare(strict_types=1);

namespace App\Support;

class RoleCapabilityCatalog
{
    private const DIRECTORY_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
    ];

    private const FINANCE_VIEWER_ROLES = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const MARKET_SETTINGS_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
    ];

    private const TENANT_CONTRACT_VIEWER_ROLES = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const TENANT_CONTRACT_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'market-legal-admin',
    ];

    /**
     * @param iterable<string> $permissions
     * @return list<string>
     */
    public static function summaryForRole(string $role, iterable $permissions = []): array
    {
        $permissions = self::normalizePermissions($permissions);

        if ($role === 'super-admin') {
            return ['Полный доступ'];
        }

        $summary = [];

        if (self::canViewMarketDirectory($role, $permissions)) {
            $summary[] = self::canManageMarketDirectory($role, $permissions)
                ? 'Места и арендаторы: управление'
                : 'Места и арендаторы: просмотр';
        }

        if (self::canViewFinance($role, $permissions)) {
            $summary[] = 'Финансы 1С';
        }

        if (self::canUpdateMarketSettings($role, $permissions)) {
            $summary[] = 'Настройки рынка: изменение';
        } elseif (self::canViewMarketSettings($role, $permissions)) {
            $summary[] = 'Настройки рынка: просмотр';
        }

        if (self::hasMarketplaceAccess($permissions)) {
            $summary[] = 'Маркетплейс';
        }

        if (self::hasStaffAccess($permissions)) {
            $summary[] = 'Сотрудники';
        }

        if (self::canViewTenantContracts($role, $permissions)) {
            $summary[] = self::canManageTenantContracts($role, $permissions)
                ? 'Договоры: управление'
                : 'Договоры: просмотр';
        }

        return $summary === [] ? ['Без специальных административных доступов'] : $summary;
    }

    /**
     * @param iterable<string> $permissions
     * @return list<string>
     */
    public static function limitationsForRole(string $role, iterable $permissions = []): array
    {
        $permissions = self::normalizePermissions($permissions);

        if ($role === 'super-admin') {
            return [];
        }

        $limitations = [];

        if (self::canViewMarketDirectory($role, $permissions) && ! self::canManageMarketDirectory($role, $permissions)) {
            $limitations[] = 'Не меняет места, арендаторов и типы мест';
        }

        if (! self::canViewFinance($role, $permissions)) {
            $limitations[] = 'Финансы 1С скрыты';
        }

        if (! self::canUpdateMarketSettings($role, $permissions)) {
            $limitations[] = 'Не меняет настройки рынка';
        }

        return $limitations;
    }

    /**
     * @param iterable<int|string> $state
     * @param array<int, string> $permissionsById
     * @return list<string>
     */
    public static function permissionNamesFromState(iterable $state, array $permissionsById): array
    {
        $names = [];

        foreach ($state as $value) {
            if (is_numeric($value)) {
                $id = (int) $value;
                if (isset($permissionsById[$id])) {
                    $names[] = $permissionsById[$id];
                }

                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $names[] = trim($value);
            }
        }

        return self::normalizePermissions($names);
    }

    public static function tableSummaryForRole(string $role, iterable $permissions = []): string
    {
        return implode('; ', self::summaryForRole($role, $permissions));
    }

    public static function canManageMarketDirectory(string $role, iterable $permissions = []): bool
    {
        return $role === 'super-admin'
            || in_array($role, self::DIRECTORY_MANAGER_ROLES, true)
            || in_array('markets.update', self::normalizePermissions($permissions), true);
    }

    public static function canViewFinance(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::FINANCE_VIEWER_ROLES, true)
            || in_array('finance.1c.view', $permissions, true)
            || in_array('finance.accruals.view', $permissions, true);
    }

    public static function canViewMarketSettings(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKET_SETTINGS_MANAGER_ROLES, true)
            || in_array('market-settings.view', $permissions, true)
            || in_array('market-settings.update', $permissions, true)
            || in_array('markets.update', $permissions, true);
    }

    public static function canUpdateMarketSettings(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKET_SETTINGS_MANAGER_ROLES, true)
            || in_array('market-settings.update', $permissions, true)
            || in_array('markets.update', $permissions, true);
    }

    public static function canViewTenantContracts(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::TENANT_CONTRACT_VIEWER_ROLES, true)
            || in_array('contracts.update', $permissions, true);
    }

    public static function canManageTenantContracts(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::TENANT_CONTRACT_MANAGER_ROLES, true)
            || in_array('contracts.update', $permissions, true);
    }

    private static function canViewMarketDirectory(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || $role === 'staff'
            || str_starts_with($role, 'market-')
            || in_array('markets.viewAny', $permissions, true)
            || in_array('markets.view', $permissions, true)
            || in_array('markets.update', $permissions, true);
    }

    /**
     * @param iterable<string> $permissions
     */
    private static function hasMarketplaceAccess(iterable $permissions): bool
    {
        foreach (self::normalizePermissions($permissions) as $permission) {
            if (str_starts_with($permission, 'marketplace.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<string> $permissions
     */
    private static function hasStaffAccess(iterable $permissions): bool
    {
        foreach (self::normalizePermissions($permissions) as $permission) {
            if (str_starts_with($permission, 'staff.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param iterable<string> $permissions
     * @return list<string>
     */
    private static function normalizePermissions(iterable $permissions): array
    {
        $normalized = [];

        foreach ($permissions as $permission) {
            $permission = trim((string) $permission);
            if ($permission !== '') {
                $normalized[] = $permission;
            }
        }

        return array_values(array_unique($normalized));
    }
}
