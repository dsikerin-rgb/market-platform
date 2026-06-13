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

    public function test_role_permission_preset_resolves_only_existing_permissions(): void
    {
        self::assertSame([1], RolePermissionPresetCatalog::permissionIdsForPreset('finance_view', [
            'finance.1c.view' => 1,
        ]));
    }

    public function test_market_readonly_preset_does_not_include_global_market_list_access(): void
    {
        $definitions = RolePermissionPresetCatalog::definitions();

        self::assertSame([
            'markets.view',
            'market-settings.view',
        ], $definitions['market_readonly']['permissions']);
    }
}
