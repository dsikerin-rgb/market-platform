<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\RoleCapabilityCatalog;
use PHPUnit\Framework\TestCase;

class RoleCapabilityCatalogTest extends TestCase
{
    public function test_market_manager_summary_matches_effective_access_rules(): void
    {
        self::assertSame([
            'Места и арендаторы: управление',
            'Финансы 1С',
            'Договоры: просмотр',
        ], RoleCapabilityCatalog::summaryForRole('market-manager'));

        self::assertContains('Не меняет настройки рынка', RoleCapabilityCatalog::limitationsForRole('market-manager'));
    }

    public function test_accountant_sees_finance_without_market_directory_management(): void
    {
        self::assertSame([
            'Места: просмотр',
            'Арендаторы: просмотр',
            'Финансы 1С',
            'Договоры: просмотр',
        ], RoleCapabilityCatalog::summaryForRole('market-accountant'));

        self::assertContains(
            'Не меняет места, арендаторов и типы мест',
            RoleCapabilityCatalog::limitationsForRole('market-accountant'),
        );
    }

    public function test_security_role_is_read_only_for_directory_and_has_no_finance(): void
    {
        self::assertSame([
            'Места: просмотр',
            'Арендаторы: сервисный просмотр',
        ], RoleCapabilityCatalog::summaryForRole('market-security'));

        self::assertContains('Финансы 1С скрыты', RoleCapabilityCatalog::limitationsForRole('market-security'));
    }

    public function test_direct_permissions_expand_custom_role_summary(): void
    {
        self::assertSame([
            'Места и арендаторы: управление',
            'Финансы 1С',
            'Настройки рынка: изменение',
        ], RoleCapabilityCatalog::summaryForRole('custom-role', [
            'markets.update',
            'finance.1c.view',
            'market-settings.update',
        ]));
    }

    public function test_owner_and_legal_admin_profiles_have_distinct_capabilities(): void
    {
        self::assertSame([
            'Места: просмотр',
            'Арендаторы: просмотр',
            'Финансы 1С',
            'Календарь событий: просмотр',
            'Договоры: просмотр',
        ], RoleCapabilityCatalog::summaryForRole('market-owner'));

        self::assertFalse(RoleCapabilityCatalog::canManageMarketDirectory('market-owner'));
        self::assertFalse(RoleCapabilityCatalog::canUpdateMarketSettings('market-owner'));
        self::assertFalse(RoleCapabilityCatalog::canManageTenantContracts('market-owner'));

        self::assertSame([
            'Места и арендаторы: управление',
            'Финансы 1С',
            'Договоры: управление',
        ], RoleCapabilityCatalog::summaryForRole('market-legal-admin'));

        self::assertTrue(RoleCapabilityCatalog::canManageMarketDirectory('market-legal-admin'));
        self::assertFalse(RoleCapabilityCatalog::canUpdateMarketSettings('market-legal-admin'));
        self::assertTrue(RoleCapabilityCatalog::canManageTenantContracts('market-legal-admin'));
    }

    public function test_owner_director_profile_can_manage_market_without_super_admin(): void
    {
        self::assertTrue(RoleCapabilityCatalog::canManageMarketDirectory('market-owner-director'));
        self::assertTrue(RoleCapabilityCatalog::canViewFinance('market-owner-director'));
        self::assertTrue(RoleCapabilityCatalog::canUpdateMarketSettings('market-owner-director'));
        self::assertTrue(RoleCapabilityCatalog::canManageTenantContracts('market-owner-director'));
    }

    public function test_demo_market_admin_profile_matches_director_capabilities(): void
    {
        self::assertTrue(RoleCapabilityCatalog::canManageMarketDirectory('demo-market-admin'));
        self::assertTrue(RoleCapabilityCatalog::canViewFinance('demo-market-admin'));
        self::assertTrue(RoleCapabilityCatalog::canUpdateMarketSettings('demo-market-admin'));
        self::assertTrue(RoleCapabilityCatalog::canManageTenantContracts('demo-market-admin'));
        self::assertContains('Настройки рынка: изменение', RoleCapabilityCatalog::summaryForRole('demo-market-admin'));
    }

    public function test_marketing_and_advertising_manage_marketplace_content_without_finance(): void
    {
        foreach (['market-marketing', 'market-advertising'] as $role) {
            self::assertTrue(RoleCapabilityCatalog::canManageMarketplaceContent($role));
            self::assertTrue(RoleCapabilityCatalog::canManageMarketplaceOrders($role));
            self::assertFalse(RoleCapabilityCatalog::canViewFinance($role));
            self::assertFalse(RoleCapabilityCatalog::canViewTenantContracts($role));

            $summary = RoleCapabilityCatalog::summaryForRole($role);
            self::assertContains('Маркетплейс: витрины и товары', $summary);
            self::assertContains('Маркетплейс: обращения и заказы', $summary);
        }
    }

    public function test_permission_names_are_resolved_from_form_state(): void
    {
        self::assertSame([
            'markets.update',
            'finance.1c.view',
        ], RoleCapabilityCatalog::permissionNamesFromState([1, 'finance.1c.view', '', 999], [
            1 => 'markets.update',
        ]));
    }
}
