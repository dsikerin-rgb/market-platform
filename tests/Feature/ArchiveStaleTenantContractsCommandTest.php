<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ArchiveStaleTenantContractsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_archive_stale_contract(): void
    {
        [$market, $tenant, $space] = $this->createMarketTenantAndSpace();
        $contract = $this->createContract($market, $tenant, $space);
        $this->insertUnrelatedLatestAccrual($market);

        $this->artisan('tenant-contracts:archive-stale', [
            '--market' => (int) $market->id,
        ])->assertSuccessful();

        $contract->refresh();
        $this->assertSame('active', (string) $contract->status);
        $this->assertTrue((bool) $contract->is_active);
    }

    public function test_apply_archives_active_contract_without_recent_accruals_or_payments(): void
    {
        [$market, $tenant, $space] = $this->createMarketTenantAndSpace();
        $contract = $this->createContract($market, $tenant, $space);
        $this->insertUnrelatedLatestAccrual($market);

        $this->artisan('tenant-contracts:archive-stale', [
            '--market' => (int) $market->id,
            '--apply' => true,
        ])->assertSuccessful();

        $contract->refresh();
        $this->assertSame('archived', (string) $contract->status);
        $this->assertFalse((bool) $contract->is_active);
        $this->assertStringContainsString('Auto archived as stale', (string) $contract->notes);
    }

    public function test_apply_keeps_contract_with_recent_payment_without_accrual(): void
    {
        [$market, $tenant, $space] = $this->createMarketTenantAndSpace();
        $contract = $this->createContract($market, $tenant, $space);
        $this->insertUnrelatedLatestAccrual($market);
        $this->insertContractPayment($contract, $tenant, '2026-06-10');

        $this->artisan('tenant-contracts:archive-stale', [
            '--market' => (int) $market->id,
            '--apply' => true,
        ])->assertSuccessful();

        $contract->refresh();
        $this->assertSame('active', (string) $contract->status);
        $this->assertTrue((bool) $contract->is_active);
    }

    /**
     * @return array{Market,Tenant,MarketSpace}
     */
    private function createMarketTenantAndSpace(): array
    {
        $market = Market::query()->create([
            'name' => 'Archive Stale Contracts Market',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A1',
            'status' => 'occupied',
            'tenant_id' => (int) $tenant->id,
            'is_active' => true,
        ]);

        return [$market, $tenant, $space];
    }

    private function createContract(Market $market, Tenant $tenant, MarketSpace $space): TenantContract
    {
        return TenantContract::query()->create([
            'external_id' => 'archive-stale-'.uniqid(),
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'number' => 'A1 from 2024',
            'status' => 'active',
            'starts_at' => '2024-01-01',
            'ends_at' => null,
            'signed_at' => '2024-01-01',
            'is_active' => true,
        ]);
    }

    private function insertUnrelatedLatestAccrual(Market $market): void
    {
        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Other Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B1',
            'status' => 'occupied',
            'tenant_id' => (int) $tenant->id,
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'period' => '2026-06-01',
            'source_place_code' => 'B1',
            'source_place_name' => 'B1',
            'currency' => 'RUB',
            'total_with_vat' => 1000,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertContractPayment(TenantContract $contract, Tenant $tenant, string $paymentDate): void
    {
        DB::table('tenant_payments')->insert([
            'market_id' => (int) $contract->market_id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'tenant_external_id' => 'tenant-'.$tenant->id,
            'contract_external_id' => (string) $contract->external_id,
            'payment_external_id' => 'payment-'.$contract->id,
            'document_number' => 'payment-'.$contract->id,
            'payment_date' => $paymentDate,
            'period' => substr($paymentDate, 0, 7).'-01',
            'amount' => 1000,
            'currency' => 'RUB',
            'source' => '1c',
            'source_file' => '1c:payments',
            'source_row_hash' => hash('sha256', 'payment-'.$contract->id.'-'.$paymentDate),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
