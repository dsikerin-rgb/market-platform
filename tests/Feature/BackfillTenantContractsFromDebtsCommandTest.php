<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillTenantContractsFromDebtsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_placeholder_contract_for_real_debt_only_contract(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'tenant-real-001',
            'inn' => '123456789012',
        ]);

        DB::table('contract_debts')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_external_id' => 'tenant-real-001',
            'contract_external_id' => 'contract-real-001',
            'period' => '2026-03',
            'accrued_amount' => 1000,
            'paid_amount' => 0,
            'debt_amount' => 1000,
            'calculated_at' => '2026-03-10 10:00:00',
            'currency' => 'RUB',
            'source' => '1c',
            'raw_payload' => json_encode(['x' => 1], JSON_UNESCAPED_UNICODE),
            'hash' => hash('sha256', 'real-001'),
            'created_at' => '2026-03-10 10:00:00',
        ]);

        $this->artisan('contracts:backfill-from-debts', [
            '--market' => $market->id,
            '--execute' => true,
        ])->assertSuccessful();

        $contract = TenantContract::query()
            ->where('market_id', $market->id)
            ->where('external_id', 'contract-real-001')
            ->first();

        $this->assertNotNull($contract);
        $this->assertSame($tenant->id, $contract->tenant_id);
        $this->assertSame('[1С долг] contract-real-001', $contract->number);
        $this->assertSame('2026-03-01', $contract->starts_at?->format('Y-m-d'));
        $this->assertSame('active', $contract->status);
        $this->assertTrue((bool) $contract->is_active);
    }

    public function test_command_skips_test_external_ids_by_default(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'TEST_001',
        ]);

        DB::table('contract_debts')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_external_id' => 'TEST_001',
            'contract_external_id' => 'TEST-CONTRACT-001',
            'period' => '2026-03',
            'accrued_amount' => 1000,
            'paid_amount' => 0,
            'debt_amount' => 1000,
            'calculated_at' => '2026-03-10 10:00:00',
            'currency' => 'RUB',
            'source' => '1c',
            'raw_payload' => json_encode(['x' => 1], JSON_UNESCAPED_UNICODE),
            'hash' => hash('sha256', 'test-001'),
            'created_at' => '2026-03-10 10:00:00',
        ]);

        $this->artisan('contracts:backfill-from-debts', [
            '--market' => $market->id,
            '--execute' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('tenant_contracts', [
            'market_id' => $market->id,
            'external_id' => 'TEST-CONTRACT-001',
        ]);
    }
}
