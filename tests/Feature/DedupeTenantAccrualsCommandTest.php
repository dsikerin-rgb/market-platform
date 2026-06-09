<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DedupeTenantAccrualsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_duplicates_without_deleting_rows(): void
    {
        [$market, $tenant] = $this->seedDuplicateAccruals();

        $this->artisan('accruals:dedupe', [
            '--market' => $market->id,
            '--period' => '2026-06',
            '--tenant' => $tenant->id,
            '--json' => true,
        ])->assertSuccessful();

        $this->assertSame(2, DB::table('tenant_accruals')->count());
    }

    public function test_apply_requires_backup_option(): void
    {
        [$market, $tenant] = $this->seedDuplicateAccruals();

        $this->artisan('accruals:dedupe', [
            '--market' => $market->id,
            '--period' => '2026-06',
            '--tenant' => $tenant->id,
            '--apply' => true,
        ])->assertFailed();

        $this->assertSame(2, DB::table('tenant_accruals')->count());
    }

    public function test_apply_keeps_more_complete_duplicate_row(): void
    {
        [$market, $tenant] = $this->seedDuplicateAccruals();

        $this->artisan('accruals:dedupe', [
            '--market' => $market->id,
            '--period' => '2026-06',
            '--tenant' => $tenant->id,
            '--apply' => true,
            '--backup' => '/tmp/test.dump',
            '--json' => true,
        ])->assertSuccessful();

        $this->assertSame(1, DB::table('tenant_accruals')->count());
        $this->assertDatabaseHas('tenant_accruals', [
            'tenant_id' => $tenant->id,
            'organization_external_id' => 'org-001',
            'organization_name' => 'IP Test',
            'account' => '62.01',
            'total_with_vat' => 237510,
        ]);
    }

    /**
     * @return array{0: Market, 1: Tenant}
     */
    private function seedDuplicateAccruals(): array
    {
        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-' . uniqid(),
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'external_id' => 'tenant-dedupe',
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'external_id' => 'contract-dedupe',
            'number' => 'Dedupe contract',
            'status' => 'active',
            'starts_at' => '2026-06-01',
            'is_active' => true,
        ]);

        $base = [
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'contract_external_id' => 'contract-dedupe',
            'tenant_contract_id' => $contract->id,
            'market_space_id' => null,
            'period' => '2026-06-01',
            'source_place_code' => null,
            'source_place_name' => 'Dedupe contract',
            'activity_type' => 'rent',
            'currency' => 'RUB',
            'rent_amount' => 237510,
            'management_fee' => 0,
            'utilities_amount' => 0,
            'electricity_amount' => 0,
            'total_no_vat' => 237510,
            'total_with_vat' => 237510,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => '1c:accruals',
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('tenant_accruals')->insert(array_merge($base, [
            'source_row_number' => 1,
            'source_row_hash' => hash('sha256', 'old-row'),
            'payload' => json_encode(['tenant_external_id' => 'tenant-dedupe'], JSON_UNESCAPED_UNICODE),
        ]));

        DB::table('tenant_accruals')->insert(array_merge($base, [
            'organization_external_id' => 'org-001',
            'organization_name' => 'IP Test',
            'account' => '62.01',
            'source_row_number' => 2,
            'source_row_hash' => hash('sha256', 'new-row'),
            'payload' => json_encode(['organization_external_id' => 'org-001'], JSON_UNESCAPED_UNICODE),
        ]));

        return [$market, $tenant];
    }
}
