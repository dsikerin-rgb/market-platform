<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\MarketIntegration;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\TenantSettlementBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OneCSettlementImportTest extends TestCase
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

        $this->token = 'test-1c-settlement-token-' . uniqid();

        MarketIntegration::query()->create([
            'market_id' => (int) $this->market->id,
            'type' => MarketIntegration::TYPE_1C,
            'name' => '1C Settlements Integration',
            'auth_token' => $this->token,
            'status' => 'active',
        ]);
    }

    public function test_settlement_import_stores_balance_and_links_contract(): void
    {
        $tenant = Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Test tenant',
            'external_id' => 'tenant-001',
            'inn' => '222222222222',
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'external_id' => 'contract-001',
            'number' => 'P1/2 from 01.01.2026',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $response = $this->postJson(route('api.1c.settlements.store'), [
            'calculated_at' => '2026-06-11 08:00:00',
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'account' => '62.01',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-001',
                    'tenant_name' => 'Test tenant',
                    'inn' => '222222222222',
                    'contract_external_id' => 'contract-001',
                    'contract_name' => 'P1/2 from 01.01.2026',
                    'settlement_document_external_id' => 'doc-001',
                    'settlement_document_name' => 'Реализация № 1 от 01.06.2026',
                    'organization_external_id' => 'org-001',
                    'organization_name' => 'Test organization',
                    'account' => '62.01',
                    'opening_debit' => 10,
                    'opening_credit' => 0,
                    'turnover_debit' => 1000,
                    'turnover_credit' => 700,
                    'closing_debit' => 310,
                    'closing_credit' => 0,
                    'currency' => 'rub',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('received', 1);
        $response->assertJsonPath('inserted', 1);
        $response->assertJsonPath('updated', 0);
        $response->assertJsonPath('skipped', 0);
        $response->assertJsonPath('linked_contracts', 1);

        $this->assertDatabaseHas('tenant_settlement_balances', [
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-001',
            'contract_external_id' => 'contract-001',
            'settlement_document_external_id' => 'doc-001',
            'settlement_document_name' => 'Реализация № 1 от 01.06.2026',
            'account' => '62.01',
            'opening_debit' => '10.00',
            'turnover_debit' => '1000.00',
            'turnover_credit' => '700.00',
            'closing_debit' => '310.00',
            'closing_credit' => '0.00',
            'currency' => 'RUB',
        ]);

        $this->assertDatabaseHas('integration_exchanges', [
            'market_id' => (int) $this->market->id,
            'entity_type' => 'settlements',
            'status' => IntegrationExchange::STATUS_OK,
        ]);
    }

    public function test_repeated_settlement_import_updates_existing_row_instead_of_creating_duplicate(): void
    {
        Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Test tenant',
            'external_id' => 'tenant-002',
        ]);

        $payload = [
            'calculated_at' => '2026-06-11 08:00:00',
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'account' => '62.01',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-002',
                    'contract_external_id' => 'contract-002',
                    'settlement_document_external_id' => 'doc-002',
                    'settlement_document_name' => 'Поступление № 2 от 02.06.2026',
                    'opening_debit' => 0,
                    'opening_credit' => 500,
                    'turnover_debit' => 100,
                    'turnover_credit' => 0,
                    'closing_debit' => 0,
                    'closing_credit' => 400,
                ],
            ],
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $this->postJson(route('api.1c.settlements.store'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('updated', 0);

        $this->postJson(route('api.1c.settlements.store'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1);

        $this->assertSame(1, TenantSettlementBalance::query()->count());
    }

    public function test_settlement_import_requires_valid_token(): void
    {
        $this->postJson(route('api.1c.settlements.store'), [
            'calculated_at' => '2026-06-11 08:00:00',
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'account' => '62.01',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-003',
                    'closing_debit' => 100,
                ],
            ],
        ], [
            'Authorization' => 'Bearer wrong-token',
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    }

    public function test_successful_period_snapshot_deletes_missing_one_c_settlement_rows(): void
    {
        $tenant = Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Snapshot tenant',
            'external_id' => 'tenant-snapshot',
        ]);

        TenantSettlementBalance::query()->create([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-snapshot',
            'account' => '62.01',
            'settlement_document_external_id' => 'old-doc',
            'closing_debit' => 1000,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => now(),
            'source_row_hash' => hash('sha256', 'old-doc'),
        ]);

        TenantSettlementBalance::query()->create([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-snapshot',
            'account' => '62.01',
            'settlement_document_external_id' => 'manual-doc',
            'closing_debit' => 500,
            'source' => 'manual',
            'source_file' => 'manual',
            'imported_at' => now(),
            'source_row_hash' => hash('sha256', 'manual-doc'),
        ]);

        $this->postJson(route('api.1c.settlements.store'), [
            'calculated_at' => '2026-06-11 08:00:00',
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'account' => '62.01',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-snapshot',
                    'settlement_document_external_id' => 'new-doc',
                    'settlement_document_name' => 'Реализация № 3 от 03.06.2026',
                    'closing_debit' => 2000,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('warnings.snapshot_deleted', 1);

        $this->assertDatabaseMissing('tenant_settlement_balances', [
            'settlement_document_external_id' => 'old-doc',
        ]);

        $this->assertDatabaseHas('tenant_settlement_balances', [
            'settlement_document_external_id' => 'new-doc',
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'closing_debit' => '2000.00',
        ]);

        $this->assertDatabaseHas('tenant_settlement_balances', [
            'settlement_document_external_id' => 'manual-doc',
            'source' => 'manual',
        ]);
    }

    public function test_period_snapshot_is_scoped_by_account(): void
    {
        $tenant = Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Account scope tenant',
            'external_id' => 'tenant-account-scope',
        ]);

        TenantSettlementBalance::query()->create([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-account-scope',
            'account' => '76.07',
            'settlement_document_external_id' => 'deposit-doc',
            'closing_credit' => 3000,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => now(),
            'source_row_hash' => hash('sha256', 'deposit-doc'),
        ]);

        $this->postJson(route('api.1c.settlements.store'), [
            'calculated_at' => '2026-06-11 08:00:00',
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'account' => '62.01',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-account-scope',
                    'settlement_document_external_id' => 'rent-doc',
                    'closing_debit' => 1000,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('warnings.snapshot_deleted', 0);

        $this->assertDatabaseHas('tenant_settlement_balances', [
            'settlement_document_external_id' => 'deposit-doc',
            'account' => '76.07',
            'closing_credit' => '3000.00',
        ]);

        $this->assertDatabaseHas('tenant_settlement_balances', [
            'settlement_document_external_id' => 'rent-doc',
            'account' => '62.01',
            'closing_debit' => '1000.00',
        ]);
    }
}
