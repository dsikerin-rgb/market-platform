<?php
# tests/Feature/DuplicateSpaceResolutionServiceTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Services\MarketMap\DuplicateSpaceResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DuplicateSpaceResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_rejects_accrual_only_canonical_when_duplicate_has_shape_and_contracts(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Р‘Р°СЂРєРѕРІСЃРєР°СЏ Р›.РЎ.',
            'is_active' => true,
        ]));

        $duplicate = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'РћРЎ11/3',
            'code' => 'os-11-3',
            'display_name' => 'РћРЎ11/3',
            'status' => 'occupied',
            'is_active' => true,
        ]));

        $candidate = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'РћРЎ11/3__t114',
            'code' => 'os-11-3-t114',
            'display_name' => 'РћРЎ11/3 / Р‘Р°СЂРєРѕРІСЃРєР°СЏ Р›.РЎ.',
            'status' => 'occupied',
            'is_active' => true,
        ]));

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 1, 'y' => 1],
                ['x' => 2, 'y' => 1],
                ['x' => 2, 'y' => 2],
            ],
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
            'is_active' => true,
        ]);

        TenantContract::withoutEvents(fn () => TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'external_id' => 'contract-os-11-3',
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_AUTO,
            'number' => 'Рђ РћРЎ 11/3 РѕС‚ 01.06.2023',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]));

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $candidate->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 1000,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('РћСЃРЅРѕРІРЅРѕРµ РјРµСЃС‚Рѕ РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РІС‹Р±СЂР°РЅРѕ С‚РѕР»СЊРєРѕ РїРѕ С„РёРЅР°РЅСЃРѕРІС‹Рј СЃРІСЏР·СЏРј');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $candidate->id,
        );
    }

    public function test_preview_allows_safe_duplicate_without_any_financial_links(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј Р±РµР·РѕРїР°СЃРЅС‹Рµ СЃРІСЏР·Рё РЅР° РґСѓР±Р»Рµ
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $result = app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('safe_duplicate_no_financials', $result['classification']);
        $this->assertSame(0, $result['accrual_classification']['blocking_accruals']);
        $this->assertSame(0, $result['accrual_classification']['historical_tail_accruals']);
    }

    public function test_preview_allows_duplicate_with_historical_financial_tail(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј Р±РµР·РѕРїР°СЃРЅС‹Рµ СЃРІСЏР·Рё РЅР° РґСѓР±Р»Рµ (map_shapes)
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂС‹ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј СЃРІРµР¶РёРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° canonical (2026-01)
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј unmatched РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° РґСѓР±Р»Рµ (2025-01..2025-04) - historical tail
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-01-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-02-01',
            'source' => '1c',
            'total_with_vat' => 32000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-03-01',
            'source' => '1c',
            'total_with_vat' => 31000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-04-01',
            'source' => '1c',
            'total_with_vat' => 33000,
            'tenant_contract_id' => null,
        ]);

        $result = app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('duplicate_with_historical_financial_tail', $result['classification']);
        $this->assertSame(0, $result['accrual_classification']['blocking_accruals']);
        $this->assertSame(4, $result['accrual_classification']['historical_tail_accruals']);
        $this->assertSame('2025-04-01', $result['accrual_classification']['duplicate_latest_accrual_period']);
        $this->assertSame('2026-01-01', $result['accrual_classification']['canonical_latest_accrual_period']);
        $this->assertFalse($result['accrual_classification']['has_linked_contract_accruals']);
        $this->assertArrayHasKey('retained_financial_tail', $result);
        $this->assertSame(4, $result['retained_financial_tail']['accruals_count']);
    }

    public function test_preview_blocks_duplicate_with_active_contract(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј map_shapes РЅР° РґСѓР±Р»Рµ, С‡С‚РѕР±С‹ РїСЂРѕР№С‚Рё validateCanonicalAnchors
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј map_shapes РЅР° canonical, С‡С‚РѕР±С‹ РѕРЅ Р±С‹Р» РІР°Р»РёРґРЅС‹Рј
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $canonical->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј Р°РєС‚РёРІРЅС‹Р№ РґРѕРіРѕРІРѕСЂ РЅР° РґСѓР±Р»Рµ
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'number' => 'DUP-CONTRACT',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Duplicate space has active contracts');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );
    }

    public function test_preview_blocks_duplicate_with_linked_accruals(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј map_shapes РЅР° РґСѓР±Р»Рµ, С‡С‚РѕР±С‹ РїСЂРѕР№С‚Рё validateCanonicalAnchors
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РЅР°С‡РёСЃР»РµРЅРёРµ СЃ tenant_contract_id РЅР° РґСѓР±Р»Рµ
        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'number' => 'OLD-DUP-CONTRACT',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-06-01',
            'source' => '1c',
            'total_with_vat' => 40000,
            'tenant_contract_id' => $contract->id,
        ]);

        // Р‘Р»РѕРєРёСЂРѕРІРєР° СЃСЂР°Р±РѕС‚Р°РµС‚ РєР°Рє contracts, С‚Р°Рє РєР°Рє contract СЃРѕР·РґР°РЅ
        // Р­С‚Рѕ РїСЂР°РІРёР»СЊРЅС‹Р№ СЃС†РµРЅР°СЂРёР№ - linked accruals Р±Р»РѕРєРёСЂСѓСЋС‚СЃСЏ С‡РµСЂРµР· contracts
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Duplicate space has active contracts');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );
    }

    public function test_preview_blocks_duplicate_with_fresh_accruals(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј СЃРІРµР¶РёРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° canonical (2025-06)
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2025-06-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј Р±РѕР»РµРµ СЃРІРµР¶РёРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° РґСѓР±Р»Рµ (2025-08) - unmatched
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-08-01',
            'source' => '1c',
            'total_with_vat' => 45000,
            'tenant_contract_id' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Duplicate space has fresh accruals that conflict');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );
    }

    public function test_preview_blocks_duplicate_when_canonical_has_no_accruals_but_duplicate_has_any(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° РґСѓР±Р»Рµ (РЅРѕ canonical РЅРµ РёРјРµРµС‚ РЅР°С‡РёСЃР»РµРЅРёР№)
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-04-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        // Р•СЃР»Рё canonicalLatestPeriod === null, С‚Рѕ РІСЃРµ accruals РЅР° РґСѓР±Р»Рµ СЃС‡РёС‚Р°СЋС‚СЃСЏ blocking
        // РўР°Рє РєР°Рє historical tail РЅРµ РјРѕР¶РµС‚ СЃСѓС‰РµСЃС‚РІРѕРІР°С‚СЊ Р±РµР· canonical latest
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Duplicate space has fresh accruals that conflict');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );
    }

    public function test_preview_allows_116_218_like_scenario(): void
    {
        // РЎС†РµРЅР°СЂРёР№ #116/#218:
        // canonical (#116): tenant_id=406, contracts=2 active, latest accrual 2026-01, map_shapes=0
        // duplicate (#218): tenant_id=406, contracts=0, unmatched accruals 2025-01..2025-04, map_shapes=1

        $market = Market::create([
            'name' => 'РЎС‚Р°СЂС‹Р№ Р±Р°Р·Р°СЂ',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РЈРњРќР«Р™ Р РРўР•Р™Р› РћРћРћ',
            'is_active' => true,
        ]);

        // #116 - canonical
        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => '116',
            'display_name' => '116 / РЎР°РјРѕРєР°С‚',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // #218 - duplicate
        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => '218',
            'display_name' => '218 / РЎРў/СЃРєР»Р°Рґ/11/1',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // 2 Р°РєС‚РёРІРЅС‹С… РґРѕРіРѕРІРѕСЂР° РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CONTRACT-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CONTRACT-002',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Latest accrual 2026-01 РЅР° canonical
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 100000,
        ]);

        // map_shapes=1 РЅР° duplicate
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // unmatched accruals 2025-01..2025-04 РЅР° duplicate (tenant_contract_id=null)
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-01-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-02-01',
            'source' => '1c',
            'total_with_vat' => 32000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-03-01',
            'source' => '1c',
            'total_with_vat' => 31000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-04-01',
            'source' => '1c',
            'total_with_vat' => 33000,
            'tenant_contract_id' => null,
        ]);

        $result = app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('duplicate_with_historical_financial_tail', $result['classification']);
        $this->assertSame(0, $result['accrual_classification']['blocking_accruals']);
        $this->assertSame(4, $result['accrual_classification']['historical_tail_accruals']);
        $this->assertSame('2025-04-01', $result['accrual_classification']['duplicate_latest_accrual_period']);
        $this->assertSame('2026-01-01', $result['accrual_classification']['canonical_latest_accrual_period']);
        $this->assertFalse($result['accrual_classification']['has_linked_contract_accruals']);
        $this->assertArrayHasKey('retained_financial_tail', $result);
        $this->assertSame(4, $result['retained_financial_tail']['accruals_count']);
        $this->assertTrue($result['retained_financial_tail']['unmatched_only']);
    }

    public function test_preview_blocks_historical_tail_when_duplicate_has_no_safe_transfer_links(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј СЃРІРµР¶РёРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° canonical (2026-01)
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј unmatched РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° РґСѓР±Р»Рµ (2025-01..2025-04) - historical tail
        // РќРћ: РЅРµС‚ map_shapes РёР»Рё РґСЂСѓРіРёС… safe links РЅР° РґСѓР±Р»Рµ
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-01-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no safe transfer links found');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );
    }

    public function test_resolve_blocks_historical_tail_when_duplicate_has_no_safe_transfer_links(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј СЃРІРµР¶РёРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° canonical
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј historical tail РЅР° РґСѓР±Р»Рµ Р±РµР· safe links
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-01-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no safe transfer links found');

        // РЎРѕС…СЂР°РЅСЏРµРј РёР·РЅР°С‡Р°Р»СЊРЅС‹Р№ is_active РґСѓР±Р»СЏ
        $originalIsActive = $duplicate->is_active;

        try {
            app(DuplicateSpaceResolutionService::class)->resolve(
                (int) $market->id,
                (int) $duplicate->id,
                (int) $canonical->id,
            );
        } catch (ValidationException $e) {
            // РџСЂРѕРІРµСЂСЏРµРј, С‡С‚Рѕ РґСѓР±Р»СЊ РќР• Р±С‹Р» РґРµР°РєС‚РёРІРёСЂРѕРІР°РЅ
            $duplicate->refresh();
            $this->assertSame($originalIsActive, $duplicate->is_active, 'Duplicate should not be deactivated when ambiguous');
            throw $e;
        }
    }

    public function test_resolve_returns_retained_financial_tail_for_allowed_historical_tail_case(): void
    {
        $market = Market::create([
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ СЂС‹РЅРѕРє',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'РўРµСЃС‚РѕРІС‹Р№ Р°СЂРµРЅРґР°С‚РѕСЂ',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/1',
            'display_name' => 'Duplicate',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/1',
            'display_name' => 'Canonical',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј map_shapes РЅР° РґСѓР±Р»Рµ (safe link)
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј РґРѕРіРѕРІРѕСЂ РЅР° canonical
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј СЃРІРµР¶РёРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° canonical
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Р”РѕР±Р°РІР»СЏРµРј historical tail РЅР° РґСѓР±Р»Рµ
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-01-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-02-01',
            'source' => '1c',
            'total_with_vat' => 32000,
            'tenant_contract_id' => null,
        ]);

        $result = app(DuplicateSpaceResolutionService::class)->resolve(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('duplicate_with_historical_financial_tail', $result['classification']);
        $this->assertArrayHasKey('retained_financial_tail', $result);
        $this->assertSame(2, $result['retained_financial_tail']['accruals_count']);
        $this->assertSame('2025-02-01', $result['retained_financial_tail']['latest_period']);
        $this->assertTrue($result['retained_financial_tail']['unmatched_only']);
    }
}
