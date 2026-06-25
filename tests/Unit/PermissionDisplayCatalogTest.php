<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PermissionDisplayCatalog;
use App\Support\RolePermissionPresetCatalog;
use PHPUnit\Framework\TestCase;

class PermissionDisplayCatalogTest extends TestCase
{
    public function test_known_permissions_have_russian_labels_and_groups(): void
    {
        self::assertSame('Просмотр финансов 1С', PermissionDisplayCatalog::label('finance.1c.view'));
        self::assertSame('Финансы 1С', PermissionDisplayCatalog::group('finance.1c.view'));
    }

    public function test_unknown_permission_is_displayed_as_russian_text(): void
    {
        self::assertSame('Изменение: торговые места', PermissionDisplayCatalog::label('market-spaces.update'));
        self::assertSame('Места', PermissionDisplayCatalog::group('market-spaces.update'));
    }

    public function test_permission_options_include_group_badges_without_raw_codes(): void
    {
        $options = PermissionDisplayCatalog::options([
            7 => 'finance.1c.view',
        ]);

        self::assertStringContainsString('Финансы 1С', $options[7]);
        self::assertStringContainsString('Просмотр финансов 1С', $options[7]);
        self::assertStringNotContainsString('finance.1c.view', $options[7]);
    }

    public function test_risky_permissions_are_marked_in_options(): void
    {
        $options = PermissionDisplayCatalog::options([
            3 => 'markets.viewAny',
        ]);

        self::assertTrue(PermissionDisplayCatalog::isRisky('markets.viewAny'));
        self::assertStringContainsString('Осторожно', $options[3]);
    }

    public function test_role_permission_preset_resolves_only_existing_permissions(): void
    {
        self::assertSame([1], RolePermissionPresetCatalog::permissionIdsForPreset('finance_view', [
            'finance.1c.view' => 1,
        ]));
    }

    public function test_marketplace_content_permissions_have_human_labels(): void
    {
        self::assertSame('Редактирование текста и описания товаров', PermissionDisplayCatalog::label('marketplace.products.update_content'));
        self::assertSame('Маркетплейс', PermissionDisplayCatalog::group('marketplace.products.update_content'));
        self::assertContains('marketplace.products.update_content', PermissionDisplayCatalog::marketplacePermissions());
    }

    public function test_marketplace_content_preset_includes_products_orders_and_tenant_contacts(): void
    {
        $definitions = RolePermissionPresetCatalog::definitions();

        self::assertContains('marketplace.products.update_content', $definitions['marketplace_content']['permissions']);
        self::assertContains('marketplace.orders.view', $definitions['marketplace_content']['permissions']);
        self::assertContains('marketplace.chats.reply', $definitions['marketplace_content']['permissions']);
        self::assertContains('tenants.marketplace-contacts.view', $definitions['marketplace_content']['permissions']);
        self::assertNotContains('finance.1c.view', $definitions['marketplace_content']['permissions']);
        self::assertNotContains('finance.accruals.view', $definitions['marketplace_content']['permissions']);
    }

    public function test_market_readonly_preset_does_not_include_global_market_list_access(): void
    {
        $definitions = RolePermissionPresetCatalog::definitions();

        self::assertContains('markets.view', $definitions['market_readonly']['permissions']);
        self::assertContains('market-settings.view', $definitions['market_readonly']['permissions']);
        self::assertNotContains('markets.viewAny', $definitions['market_readonly']['permissions']);
    }
}
