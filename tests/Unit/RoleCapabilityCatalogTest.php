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
        ], RoleCapabilityCatalog::summaryForRole('market-manager'));

        self::assertContains('Не меняет настройки рынка', RoleCapabilityCatalog::limitationsForRole('market-manager'));
    }

    public function test_accountant_sees_finance_without_market_directory_management(): void
    {
        self::assertSame([
            'Места и арендаторы: просмотр',
            'Финансы 1С',
        ], RoleCapabilityCatalog::summaryForRole('market-accountant'));

        self::assertContains(
            'Не меняет места, арендаторов и типы мест',
            RoleCapabilityCatalog::limitationsForRole('market-accountant'),
        );
    }

    public function test_security_role_is_read_only_for_directory_and_has_no_finance(): void
    {
        self::assertSame([
            'Места и арендаторы: просмотр',
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
