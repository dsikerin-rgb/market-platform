<?php
# tests/Feature/DuplicateSpaceResolutionServiceTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\MarketSpaceTenantBinding;
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
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::withoutEvents(fn () => Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]));

        $duplicate = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'OS11/3',
            'code' => 'os-11-3',
            'display_name' => 'OS11/3',
            'status' => 'occupied',
            'is_active' => true,
        ]));

        $candidate = MarketSpace::withoutEvents(fn () => MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'OS11/3__t114',
            'code' => 'os-11-3-t114',
            'display_name' => 'OS11/3 / Test Tenant',
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
            'number' => 'A OS 11/3 dated 2023-06-01',
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
        $this->expectExceptionMessage('Canonical space cannot be selected based only on financial links');

        app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $candidate->id,
        );
    }

    public function test_preview_allows_safe_duplicate_without_any_financial_links(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
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

        // Fixture setup.
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Fixture setup.
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
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
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

        // Fixture setup.
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Fixture setup.
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Fixture setup.
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Fixture setup.
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

    public function test_preview_allows_duplicate_with_safe_snapshot_tenant_binding_and_historical_financial_tail(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/2',
            'display_name' => 'Duplicate with snapshot',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/2',
            'display_name' => 'Canonical with contract',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-SAFE-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
            'is_active' => true,
        ]);

        MarketSpaceTenantBinding::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => null,
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => [
                'status' => 'occupied',
                'is_active' => true,
            ],
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 60000,
        ]);

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

        $result = app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('duplicate_with_historical_financial_tail', $result['classification']);
        $this->assertSame(1, $result['tenant_binding_classification']['safe_snapshot_tenant_bindings']);
        $this->assertSame(0, $result['tenant_binding_classification']['blocking_tenant_bindings']);
        $this->assertSame(2, $result['accrual_classification']['historical_tail_accruals']);
        $this->assertArrayHasKey('retained_financial_tail', $result);
    }

    public function test_preview_allows_duplicate_with_historical_tail_and_only_safe_snapshot_tenant_binding(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/snapshot-only',
            'display_name' => 'Duplicate snapshot only',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/snapshot-only',
            'display_name' => 'Canonical snapshot only',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-SNAPSHOT-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        MarketSpaceTenantBinding::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => null,
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => [
                'status' => 'occupied',
                'is_active' => true,
            ],
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 60000,
        ]);

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

        $result = app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('duplicate_with_historical_financial_tail', $result['classification']);
        $this->assertSame(1, $result['tenant_binding_classification']['safe_snapshot_tenant_bindings']);
        $this->assertSame(0, $result['tenant_binding_classification']['blocking_tenant_bindings']);
        $this->assertSame(0, $result['accrual_classification']['blocking_accruals']);
        $this->assertSame(2, $result['accrual_classification']['historical_tail_accruals']);
        $this->assertSame('2025-02-01', $result['accrual_classification']['duplicate_latest_accrual_period']);
        $this->assertSame('2026-01-01', $result['accrual_classification']['canonical_latest_accrual_period']);
        $this->assertFalse($result['accrual_classification']['has_linked_contract_accruals']);
        $this->assertArrayHasKey('retained_financial_tail', $result);
        $this->assertSame(2, $result['retained_financial_tail']['accruals_count']);
        $this->assertTrue($result['retained_financial_tail']['unmatched_only']);
    }

    public function test_preview_allows_duplicate_with_historical_tail_and_no_safe_transfer_links(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
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

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'period' => '2025-01-01',
            'source' => '1c',
            'total_with_vat' => 30000,
            'tenant_contract_id' => null,
        ]);

        $result = app(DuplicateSpaceResolutionService::class)->preview(
            (int) $market->id,
            (int) $duplicate->id,
            (int) $canonical->id,
        );

        $this->assertSame('duplicate_with_historical_financial_tail', $result['classification']);
        $this->assertArrayHasKey('retained_financial_tail', $result);
        $this->assertSame(1, $result['retained_financial_tail']['accruals_count']);
    }

    public function test_resolve_returns_retained_financial_tail_for_allowed_historical_tail_case(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
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

        // Fixture setup.
        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1, 'bbox_y1' => 1, 'bbox_x2' => 2, 'bbox_y2' => 2,
            'is_active' => true,
        ]);

        // Fixture setup.
        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        // Fixture setup.
        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

        // Fixture setup.
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

    public function test_resolve_closes_safe_snapshot_tenant_binding_on_duplicate_and_does_not_transfer_it(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'is_active' => true,
        ]);

        $duplicate = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'D/resolve-snapshot',
            'display_name' => 'Duplicate resolve snapshot',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $canonical = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'C/resolve-snapshot',
            'display_name' => 'Canonical resolve snapshot',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'CANON-RESOLVE-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
            'bbox_x1' => 1,
            'bbox_y1' => 1,
            'bbox_x2' => 2,
            'bbox_y2' => 2,
            'is_active' => true,
        ]);

        $snapshotBinding = MarketSpaceTenantBinding::create([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => null,
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => [
                'status' => 'occupied',
                'is_active' => true,
            ],
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50000,
        ]);

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
        $this->assertSame(1, $result['closed_tenant_bindings']);
        $this->assertSame(0, $result['tenant_binding_classification']['blocking_tenant_bindings']);
        $this->assertSame(1, $result['tenant_binding_classification']['safe_snapshot_tenant_bindings']);

        $snapshotBinding->refresh();
        $this->assertNotNull($snapshotBinding->ended_at);
        $this->assertSame('duplicate_space_retired', $snapshotBinding->resolution_reason);

        $this->assertSame(
            0,
            MarketSpaceTenantBinding::query()
                ->where('market_space_id', $canonical->id)
                ->whereNull('tenant_contract_id')
                ->where('binding_type', 'space_snapshot')
                ->count(),
        );
    }
}
