<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccrualsReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_json_reports_overlap_statuses_and_basis_precedence(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenantMatched = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Matched',
        ]);

        $tenantMismatch = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Mismatch',
        ]);

        $tenantOnlyOneC = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Only 1C',
        ]);

        $tenantOnlyCsv = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Only CSV',
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantMatched->id,
            'number' => 'CONTRACT-1',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'signed_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'P/2',
            'code' => 'P/2',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantMatched->id,
            'tenant_contract_id' => $contract->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 100,
            'total_no_vat' => 100,
            'source_place_code' => 'P/1',
            'source_row_hash' => 'matched-1c',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantMatched->id,
            'tenant_contract_id' => $contract->id,
            'period' => '2026-01-01',
            'source' => 'excel',
            'total_with_vat' => 100,
            'total_no_vat' => 100,
            'source_place_code' => 'P/1',
            'source_row_hash' => 'matched-csv',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantMismatch->id,
            'market_space_id' => $space->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 200,
            'total_no_vat' => 200,
            'source_place_code' => 'P/2',
            'source_row_hash' => 'mismatch-1c',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantMismatch->id,
            'market_space_id' => $space->id,
            'period' => '2026-01-01',
            'source' => 'csv',
            'total_with_vat' => 240,
            'total_no_vat' => 240,
            'source_place_code' => 'P/2',
            'source_row_hash' => 'mismatch-csv',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantOnlyOneC->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 50,
            'total_no_vat' => 50,
            'source_place_code' => 'P/3',
            'source_row_hash' => 'only-1c',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantOnlyCsv->id,
            'period' => '2026-01-01',
            'source' => 'excel',
            'total_with_vat' => 75,
            'total_no_vat' => 75,
            'source_place_code' => 'P/4',
            'source_row_hash' => 'only-csv',
        ]);

        $this->artisan('accruals:reconcile', [
            '--market' => $market->id,
            '--period' => '2026-01',
            '--json' => true,
            '--with-matched-overlap' => true,
            '--overlap-limit' => 10,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"bucket_count_total": 4')
            ->expectsOutputToContain('"bucket_count_in_both": 2')
            ->expectsOutputToContain('"bucket_count_matched": 1')
            ->expectsOutputToContain('"bucket_count_mismatch": 1')
            ->expectsOutputToContain('"bucket_count_only_in_1c": 1')
            ->expectsOutputToContain('"bucket_count_only_in_csv": 1')
            ->expectsOutputToContain('"comparison_basis": "contract"')
            ->expectsOutputToContain('"comparison_basis": "market_space"')
            ->expectsOutputToContain('"comparison_basis": "place_code"')
            ->expectsOutputToContain('"status": "matched"')
            ->expectsOutputToContain('"status": "mismatch"')
            ->expectsOutputToContain('"status": "only_1c"')
            ->expectsOutputToContain('"status": "only_csv"')
            ->expectsOutputToContain('"bucket_label": "contract:' . $contract->id . '"')
            ->expectsOutputToContain('"bucket_label": "market_space:' . $space->id . '"')
            ->expectsOutputToContain('"bucket_label": "place_code:P/3"')
            ->expectsOutputToContain('"bucket_label": "place_code:P/4"');
    }

    public function test_reconcile_json_respects_tenant_filter_for_overlap_report(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenantIncluded = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Included',
        ]);

        $tenantExcluded = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Excluded',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantIncluded->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 10,
            'total_no_vat' => 10,
            'source_place_code' => 'A/1',
            'source_row_hash' => 'included-1c',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantIncluded->id,
            'period' => '2026-01-01',
            'source' => 'excel',
            'total_with_vat' => 15,
            'total_no_vat' => 15,
            'source_place_code' => 'A/1',
            'source_row_hash' => 'included-csv',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenantExcluded->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 20,
            'total_no_vat' => 20,
            'source_place_code' => 'B/1',
            'source_row_hash' => 'excluded-1c',
        ]);

        $this->artisan('accruals:reconcile', [
            '--market' => $market->id,
            '--period' => '2026-01',
            '--tenant' => $tenantIncluded->id,
            '--json' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"tenant_id": ' . $tenantIncluded->id)
            ->expectsOutputToContain('"bucket_count_total": 1')
            ->expectsOutputToContain('"bucket_count_mismatch": 1')
            ->doesntExpectOutputToContain('Tenant Excluded')
            ->doesntExpectOutputToContain('"bucket_count_total": 2');
    }

    public function test_reconcile_json_matches_same_contract_despite_different_activity_types(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Activity Drift',
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'CONTRACT-ACTIVITY',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'signed_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'activity_type' => 'rent',
            'total_with_vat' => 100,
            'total_no_vat' => 100,
            'source_row_hash' => 'activity-1c',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'period' => '2026-01-01',
            'source' => 'excel',
            'activity_type' => 'furniture',
            'total_with_vat' => 100,
            'total_no_vat' => 100,
            'source_row_hash' => 'activity-csv',
        ]);

        $this->artisan('accruals:reconcile', [
            '--market' => $market->id,
            '--period' => '2026-01',
            '--json' => true,
            '--with-matched-overlap' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"bucket_count_total": 1')
            ->expectsOutputToContain('"bucket_count_in_both": 1')
            ->expectsOutputToContain('"bucket_count_matched": 1')
            ->expectsOutputToContain('"comparison_basis": "contract"')
            ->expectsOutputToContain('"bucket_label": "contract:' . $contract->id . '"')
            ->doesntExpectOutputToContain('activity:rent')
            ->doesntExpectOutputToContain('activity:furniture');
    }

    public function test_reconcile_json_can_fall_back_to_tenant_basis_for_aggregated_cross_source_rows(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Aggregated',
        ]);

        $leftContract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'CONTRACT-LEFT',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'signed_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $rightContract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'CONTRACT-RIGHT',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'signed_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $leftContract->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 100,
            'total_no_vat' => 100,
            'source_row_hash' => 'aggregated-1c-left',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $rightContract->id,
            'period' => '2026-01-01',
            'source' => '1c',
            'total_with_vat' => 150,
            'total_no_vat' => 150,
            'source_row_hash' => 'aggregated-1c-right',
        ]);

        $this->createAccrual([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'period' => '2026-01-01',
            'source' => 'excel',
            'total_with_vat' => 250,
            'total_no_vat' => 250,
            'source_row_hash' => 'aggregated-csv',
        ]);

        $this->artisan('accruals:reconcile', [
            '--market' => $market->id,
            '--period' => '2026-01',
            '--json' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"bucket_count_total": 1')
            ->expectsOutputToContain('"bucket_count_in_both": 1')
            ->expectsOutputToContain('"bucket_count_mismatch": 1')
            ->expectsOutputToContain('"comparison_basis": "tenant"')
            ->expectsOutputToContain('"bucket_label": "tenant:' . $tenant->id . '"')
            ->doesntExpectOutputToContain('"status": "only_1c"')
            ->doesntExpectOutputToContain('"status": "only_csv"');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAccrual(array $attributes): void
    {
        TenantAccrual::query()->create(array_merge([
            'currency' => 'RUB',
            'status' => 'imported',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }
}
