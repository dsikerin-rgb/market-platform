<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketIntegration;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OneCContractDebtImportTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->market = Market::query()->create([
            'name' => 'Test market',
            'slug' => 'test-market',
        ]);

        $this->token = 'test-1c-debt-token-' . uniqid();

        MarketIntegration::query()->create([
            'market_id' => (int) $this->market->id,
            'type' => MarketIntegration::TYPE_1C,
            'name' => '1C Debt Integration',
            'auth_token' => $this->token,
            'status' => 'active',
        ]);
    }

    public function test_repeated_debt_import_updates_existing_account_metadata(): void
    {
        $tenant = Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Test tenant',
            'external_id' => 'tenant-001',
            'inn' => '222222222222',
        ]);

        DB::table('contract_debts')->insert([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_external_id' => 'tenant-001',
            'contract_external_id' => 'contract-001',
            'period' => '2026-06',
            'organization_external_id' => null,
            'organization_name' => null,
            'account' => null,
            'accrued_amount' => '1000.00',
            'paid_amount' => '0.00',
            'debt_amount' => '1000.00',
            'calculated_at' => '2026-06-04 11:17:26',
            'source' => '1c',
            'currency' => 'RUB',
            'hash' => sha1('old-contract-debt-without-account'),
            'created_at' => now(),
        ]);

        $response = $this->postJson(route('api.1c.contract-debts.store'), [
            'calculated_at' => '2026-06-04 13:56:51',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-001',
                    'contract_external_id' => 'contract-001',
                    'organization_external_id' => 'org-001',
                    'organization_name' => 'Test organization',
                    'account' => '62',
                    'inn' => '222222222222',
                    'tenant_name' => 'Test tenant',
                    'accrued_amount' => 1000,
                    'paid_amount' => 0,
                    'debt_amount' => 1000,
                    'period' => '2026-06',
                    'currency' => 'RUB',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('received', 1);
        $response->assertJsonPath('inserted', 0);
        $response->assertJsonPath('skipped', 1);
        $response->assertJsonPath('metadata_updated', 1);

        $this->assertSame(1, DB::table('contract_debts')->count());
        $this->assertDatabaseHas('contract_debts', [
            'market_id' => (int) $this->market->id,
            'tenant_external_id' => 'tenant-001',
            'contract_external_id' => 'contract-001',
            'period' => '2026-06',
            'organization_external_id' => 'org-001',
            'organization_name' => 'Test organization',
            'account' => '62',
            'debt_amount' => '1000.00',
        ]);
    }
}
