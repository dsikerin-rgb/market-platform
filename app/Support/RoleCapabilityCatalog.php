<?php

declare(strict_types=1);

namespace App\Support;

class RoleCapabilityCatalog
{
    private const DIRECTORY_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
        'market-manager',
        'market-legal-admin',
    ];

    private const TENANT_SERVICE_VIEWER_ROLES = [
        'market-operator',
        'market-maintenance',
        'market-engineer',
        'market-support',
        'market-security',
        'market-guard',
    ];

    private const TENANT_FULL_VIEWER_ROLES = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const FINANCE_VIEWER_ROLES = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const MARKET_SETTINGS_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
    ];

    private const MARKET_EVENT_VIEWER_ROLES = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
    ];

    private const MARKET_EVENT_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
    ];

    private const MARKETPLACE_CONTENT_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
        'market-marketing',
        'market-advertising',
    ];

    private const MARKETPLACE_ORDER_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
        'market-marketing',
        'market-advertising',
    ];

    private const TENANT_CONTRACT_VIEWER_ROLES = [
        'market-owner',
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
        'market-manager',
        'market-legal-admin',
        'market-accountant',
        'market-finance',
    ];

    private const TENANT_CONTRACT_MANAGER_ROLES = [
        'market-owner-director',
        'market-admin',
        'demo-market-admin',
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

        if (self::canManageMarketDirectory($role, $permissions)) {
            $summary[] = 'Места и арендаторы: управление';
        } elseif (self::canViewMarketDirectory($role, $permissions)) {
            $summary[] = 'Места: просмотр';
        }

        if (! self::canManageMarketDirectory($role, $permissions) && self::canViewFullTenantProfile($role, $permissions)) {
            $summary[] = 'Арендаторы: просмотр';
        }

        if (self::canViewTenantServiceContext($role, $permissions)) {
            $summary[] = 'Арендаторы: сервисный просмотр';
        }

        if (self::canViewFinance($role, $permissions)) {
            $summary[] = 'Финансы 1С';
        }

        if (self::canManageMarketEvents($role, $permissions)) {
            $summary[] = 'Календарь событий: управление';
        } elseif (self::canViewMarketEvents($role, $permissions)) {
            $summary[] = 'Календарь событий: просмотр';
        }

        if (self::canManageReports($role, $permissions)) {
            $summary[] = 'Отчеты: управление';
        } elseif (self::canViewReports($role, $permissions)) {
            $summary[] = 'Отчеты: просмотр';
        }

        if (self::canUpdateMarketSettings($role, $permissions)) {
            $summary[] = 'Настройки рынка: изменение';
        } elseif (self::canViewMarketSettings($role, $permissions)) {
            $summary[] = 'Настройки рынка: просмотр';
        }

        if (self::canManageMarketplaceContent($role, $permissions)) {
            $summary[] = 'Маркетплейс: витрины и товары';
        } elseif (self::canViewMarketplaceContent($role, $permissions)) {
            $summary[] = 'Маркетплейс: просмотр контента';
        }

        if (self::canManageMarketplaceOrders($role, $permissions)) {
            $summary[] = 'Маркетплейс: обращения и заказы';
        } elseif (self::canViewMarketplaceOrders($role, $permissions)) {
            $summary[] = 'Маркетплейс: просмотр обращений и заказов';
        }

        if (self::hasMarketplaceAccess($permissions) && ! self::canViewMarketplaceContent($role, $permissions) && ! self::canViewMarketplaceOrders($role, $permissions)) {
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

    public static function canViewMarketEvents(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKET_EVENT_VIEWER_ROLES, true)
            || self::hasAnyPermission($permissions, [
                'market-holidays.viewAny',
                'market-holidays.view',
                'market-holidays.create',
                'market-holidays.update',
                'market-holidays.delete',
            ]);
    }

    public static function canManageMarketEvents(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKET_EVENT_MANAGER_ROLES, true)
            || self::hasAnyPermission($permissions, [
                'market-holidays.create',
                'market-holidays.update',
                'market-holidays.delete',
            ]);
    }

    public static function canViewReports(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || self::hasAnyPermission($permissions, [
                'reports.viewAny',
                'reports.view',
                'reports.create',
                'reports.update',
                'reports.delete',
            ]);
    }

    public static function canManageReports(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || self::hasAnyPermission($permissions, [
                'reports.create',
                'reports.update',
                'reports.delete',
            ]);
    }

    public static function canViewMarketplaceContent(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKETPLACE_CONTENT_MANAGER_ROLES, true)
            || self::hasAnyPermission($permissions, [
                'marketplace.settings.view',
                'marketplace.settings.update',
                'marketplace.slides.viewAny',
                'marketplace.slides.view',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
                'marketplace.storefronts.view',
                'marketplace.storefronts.update_content',
                'marketplace.products.viewAny',
                'marketplace.products.view',
                'marketplace.products.update_content',
                'marketplace.products.update_media',
                'marketplace.products.publish',
            ]);
    }

    public static function canManageMarketplaceContent(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKETPLACE_CONTENT_MANAGER_ROLES, true)
            || self::hasAnyPermission($permissions, [
                'marketplace.settings.update',
                'marketplace.slides.create',
                'marketplace.slides.update',
                'marketplace.slides.delete',
                'marketplace.storefronts.update_content',
                'marketplace.products.update_content',
                'marketplace.products.update_media',
                'marketplace.products.publish',
            ]);
    }

    public static function canViewMarketplaceOrders(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKETPLACE_ORDER_MANAGER_ROLES, true)
            || self::hasAnyPermission($permissions, [
                'marketplace.orders.view',
                'marketplace.orders.update_status',
                'marketplace.chats.view',
                'marketplace.chats.reply',
            ]);
    }

    public static function canManageMarketplaceOrders(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::MARKETPLACE_ORDER_MANAGER_ROLES, true)
            || self::hasAnyPermission($permissions, [
                'marketplace.orders.update_status',
                'marketplace.chats.reply',
            ]);
    }

    public static function canManageTenantContracts(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::TENANT_CONTRACT_MANAGER_ROLES, true)
            || in_array('contracts.update', $permissions, true);
    }

    public static function canViewFullTenantProfile(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::TENANT_FULL_VIEWER_ROLES, true)
            || in_array('markets.viewAny', $permissions, true)
            || in_array('markets.update', $permissions, true);
    }

    public static function canViewTenantServiceContext(string $role, iterable $permissions = []): bool
    {
        $permissions = self::normalizePermissions($permissions);

        return $role === 'super-admin'
            || in_array($role, self::TENANT_SERVICE_VIEWER_ROLES, true)
            || in_array('tenants.service-view', $permissions, true);
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
     * @param list<string> $permissions
     * @param list<string> $expected
     */
    private static function hasAnyPermission(array $permissions, array $expected): bool
    {
        foreach ($expected as $permission) {
            if (in_array($permission, $permissions, true)) {
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
