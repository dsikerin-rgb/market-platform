<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntegrationExchange;
use App\Models\Market;
use App\Models\MarketIntegration;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\TenantPayment;
use App\Services\Tenants\OneCTenantResolver;
use App\Support\MarketContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OneCPaymentImportTest extends TestCase
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

        $this->token = 'test-1c-payment-token-'.uniqid();

        MarketIntegration::query()->create([
            'market_id' => (int) $this->market->id,
            'type' => MarketIntegration::TYPE_1C,
            'name' => '1C Payments Integration',
            'auth_token' => $this->token,
            'status' => 'active',
        ]);
    }

    public function test_payment_import_stores_payment_and_links_contract(): void
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

        $response = $this->postJson(route('api.1c.payments.store'), [
            'calculated_at' => '2026-06-09 19:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-001',
                    'contract_external_id' => 'contract-001',
                    'payment_external_id' => 'payment-001',
                    'document_number' => 'BP-1',
                    'payment_date' => '2026-06-08',
                    'period' => '2026-06',
                    'organization_external_id' => 'org-001',
                    'organization_name' => 'Test organization',
                    'account' => '62.01',
                    'debit_account' => '51',
                    'amount' => 1500.50,
                    'currency' => 'rub',
                    'purpose' => 'Rent payment',
                    'tenant_name' => 'Test tenant',
                    'inn' => '222222222222',
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('received', 1);
        $response->assertJsonPath('inserted', 1);
        $response->assertJsonPath('skipped', 0);
        $response->assertJsonPath('linked_contracts', 1);

        $this->assertDatabaseHas('tenant_payments', [
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'tenant_external_id' => 'tenant-001',
            'contract_external_id' => 'contract-001',
            'payment_external_id' => 'payment-001',
            'document_number' => 'BP-1',
            'payment_date' => '2026-06-08',
            'period' => '2026-06-01',
            'organization_external_id' => 'org-001',
            'organization_name' => 'Test organization',
            'account' => '62.01',
            'debit_account' => '51',
            'amount' => '1500.50',
            'currency' => 'RUB',
            'purpose' => 'Rent payment',
        ]);

        $this->assertDatabaseHas('integration_exchanges', [
            'market_id' => (int) $this->market->id,
            'entity_type' => 'payments',
            'status' => IntegrationExchange::STATUS_OK,
        ]);
    }

    public function test_repeated_payment_import_is_idempotent(): void
    {
        Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Test tenant',
            'external_id' => 'tenant-002',
        ]);

        $payload = [
            'calculated_at' => '2026-06-09 19:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-002',
                    'payment_external_id' => 'payment-002',
                    'document_number' => 'BP-2',
                    'payment_date' => '2026-06-08',
                    'period' => '2026-06',
                    'amount' => 2500,
                    'currency' => 'RUB',
                ],
            ],
        ];

        $headers = [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ];

        $this->postJson(route('api.1c.payments.store'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('skipped', 0);

        $this->postJson(route('api.1c.payments.store'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(1, TenantPayment::query()->count());
    }

    public function test_payment_import_sets_market_context_from_integration_token(): void
    {
        Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Context tenant',
            'external_id' => 'tenant-context',
        ]);

        $observedMarketId = null;

        $this->app->bind(OneCTenantResolver::class, function () use (&$observedMarketId): OneCTenantResolver {
            return new class($observedMarketId) extends OneCTenantResolver
            {
                private mixed $observedMarketId;

                public function __construct(mixed &$observedMarketId)
                {
                    $this->observedMarketId = &$observedMarketId;
                }

                public function resolve(
                    int $marketId,
                    string $tenantExternalId,
                    array $payload,
                    string $source,
                    CarbonInterface $now,
                    array $options = [],
                ): array {
                    $this->observedMarketId = app(MarketContext::class)->currentMarketId();

                    return parent::resolve($marketId, $tenantExternalId, $payload, $source, $now, $options);
                }
            };
        });

        $this->postJson(route('api.1c.payments.store'), [
            'calculated_at' => '2026-06-09 19:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-context',
                    'payment_external_id' => 'payment-context',
                    'document_number' => 'BP-CONTEXT',
                    'payment_date' => '2026-06-08',
                    'period' => '2026-06',
                    'amount' => 1250,
                    'currency' => 'RUB',
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])->assertOk();

        $this->assertSame((int) $this->market->id, $observedMarketId);
    }

    public function test_payment_import_requires_valid_token(): void
    {
        $this->postJson(route('api.1c.payments.store'), [
            'calculated_at' => '2026-06-09 19:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-003',
                    'payment_date' => '2026-06-08',
                    'period' => '2026-06',
                    'amount' => 100,
                ],
            ],
        ], [
            'Authorization' => 'Bearer wrong-token',
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    }

    public function test_successful_period_snapshot_deletes_missing_payments(): void
    {
        $tenant = Tenant::query()->create([
            'market_id' => (int) $this->market->id,
            'name' => 'Snapshot tenant',
            'external_id' => 'tenant-snapshot',
        ]);

        TenantPayment::query()->create([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_external_id' => 'tenant-snapshot',
            'payment_external_id' => 'old-payment',
            'document_number' => 'OLD',
            'payment_date' => '2026-06-01',
            'period' => '2026-06-01',
            'amount' => 1000,
            'currency' => 'RUB',
            'imported_at' => now(),
            'source_row_hash' => hash('sha256', 'old-payment'),
        ]);

        TenantPayment::query()->create([
            'market_id' => (int) $this->market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_external_id' => 'tenant-snapshot',
            'payment_external_id' => 'manual-payment',
            'document_number' => 'MANUAL',
            'payment_date' => '2026-06-03',
            'period' => '2026-06-01',
            'amount' => 500,
            'currency' => 'RUB',
            'source' => 'manual',
            'source_file' => 'manual',
            'imported_at' => now(),
            'source_row_hash' => hash('sha256', 'manual-payment'),
        ]);

        $this->postJson(route('api.1c.payments.store'), [
            'calculated_at' => '2026-06-09 19:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-snapshot',
                    'payment_external_id' => 'new-payment',
                    'document_number' => 'NEW',
                    'payment_date' => '2026-06-02',
                    'period' => '2026-06',
                    'account' => '62.01',
                    'debit_account' => '51',
                    'amount' => 2000,
                    'currency' => 'RUB',
                ],
            ],
        ], [
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('warnings.snapshot_deleted', 1);

        $this->assertDatabaseMissing('tenant_payments', [
            'payment_external_id' => 'old-payment',
        ]);

        $this->assertDatabaseHas('tenant_payments', [
            'payment_external_id' => 'new-payment',
            'period' => '2026-06-01',
            'debit_account' => '51',
        ]);

        $this->assertDatabaseHas('tenant_payments', [
            'payment_external_id' => 'manual-payment',
            'source' => 'manual',
        ]);
    }
}
