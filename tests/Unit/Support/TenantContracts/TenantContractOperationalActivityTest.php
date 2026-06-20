<?php

declare(strict_types=1);

namespace Tests\Unit\Support\TenantContracts;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Support\TenantContracts\TenantContractOperationalActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantContractOperationalActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_contract_without_recent_accrual_is_not_operational_for_current_map(): void
    {
        [$market, $tenant, $space] = $this->createMarketTenantAndSpace();
        $contract = $this->createContract($market, $tenant, $space);
        $this->insertUnrelatedLatestAccrual($market);

        $this->assertFalse(
            app(TenantContractOperationalActivity::class)->isOperationalForCurrentMap($contract)
        );
    }

    public function test_contract_with_recent_accrual_is_operational_for_current_map(): void
    {
        [$market, $tenant, $space] = $this->createMarketTenantAndSpace();
        $contract = $this->createContract($market, $tenant, $space);

        $this->insertUnrelatedLatestAccrual($market);
        $this->insertContractAccrual($contract, $tenant, $space, '2026-04-01');

        $this->assertTrue(
            app(TenantContractOperationalActivity::class)->isOperationalForCurrentMap($contract->fresh())
        );
    }

    public function test_recent_payment_blocks_auto_archive_but_not_current_map_operation(): void
    {
        [$market, $tenant, $space] = $this->createMarketTenantAndSpace();
        $contract = $this->createContract($market, $tenant, $space);

        $this->insertUnrelatedLatestAccrual($market);
        $this->insertContractPayment($contract, $tenant, '2026-06-10');

        $activity = app(TenantContractOperationalActivity::class);

        $this->assertFalse($activity->isOperationalForCurrentMap($contract->fresh()));
        $this->assertFalse($activity->shouldArchiveAsStale($contract->fresh()));
    }

    /**
     * @return array{Market,Tenant,MarketSpace}
     */
    private function createMarketTenantAndSpace(): array
    {
        $market = Market::query()->create([
            'name' => 'Operational Activity Market',
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
            'external_id' => 'contract-'.uniqid(),
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'number' => 'A1 from 2025',
            'status' => 'active',
            'starts_at' => '2025-01-01',
            'ends_at' => null,
            'signed_at' => '2025-01-01',
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

    private function insertContractAccrual(TenantContract $contract, Tenant $tenant, MarketSpace $space, string $period): void
    {
        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $contract->market_id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'contract_external_id' => (string) $contract->external_id,
            'market_space_id' => (int) $space->id,
            'period' => $period,
            'source_place_code' => 'A1',
            'source_place_name' => 'A1',
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
