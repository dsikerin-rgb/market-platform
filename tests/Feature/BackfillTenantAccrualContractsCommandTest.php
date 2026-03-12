<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillTenantAccrualContractsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_links_accrual_when_single_primary_contract_matches(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
            'external_id' => 'tenant-1',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'П/10',
            'code' => 'p10',
            'tenant_id' => $tenant->id,
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-1',
            'number' => 'П/10 от 01.02.2024',
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'market_space_id' => $space->id,
            'period' => '2026-01-01',
            'source_place_code' => 'П/10',
            'currency' => 'RUB',
            'rent_amount' => 1000,
            'status' => 'imported',
            'source' => 'excel',
            'source_file' => 'test.csv',
            'source_row_number' => 1,
            'source_row_hash' => hash('sha256', 'accrual-1'),
            'payload' => json_encode(['x' => 1], JSON_UNESCAPED_UNICODE),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('accruals:link-contracts', [
            '--market' => $market->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED,
            'contract_link_source' => 'tenant_space_period',
        ]);
    }

    public function test_command_skips_when_multiple_primary_contracts_fit_same_period(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Тест',
            'external_id' => 'tenant-1',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'П/10',
            'code' => 'p10',
            'tenant_id' => $tenant->id,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-1',
            'number' => 'П/10 от 01.02.2024',
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-2',
            'number' => 'А П/10 от 01.02.2024',
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'market_space_id' => $space->id,
            'period' => '2026-01-01',
            'source_place_code' => 'П/10',
            'currency' => 'RUB',
            'rent_amount' => 1000,
            'status' => 'imported',
            'source' => 'excel',
            'source_file' => 'test.csv',
            'source_row_number' => 1,
            'source_row_hash' => hash('sha256', 'accrual-2'),
            'payload' => json_encode(['x' => 1], JSON_UNESCAPED_UNICODE),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('accruals:link-contracts', [
            '--market' => $market->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS,
            'contract_link_source' => 'tenant_space_period',
        ]);
    }
}
