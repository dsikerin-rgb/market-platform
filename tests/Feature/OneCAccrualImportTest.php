<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\IntegrationExchange;
use App\Services\TenantAccruals\TenantAccrualContractResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class OneCAccrualImportTest extends TestCase
{
    use RefreshDatabase;

    private Market $market;
    private MarketIntegration $integration;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
        ]);

        $this->token = 'test-1c-accrual-token-' . uniqid();

        $this->integration = MarketIntegration::create([
            'market_id' => $this->market->id,
            'type' => MarketIntegration::TYPE_1C,
            'name' => '1C Accruals Integration',
            'auth_token' => $this->token,
            'status' => 'active',
        ]);
    }

    public function test_accrual_import_links_tenant_contract_and_space(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'ООО Тест',
            'external_id' => 'tenant-001',
            'inn' => '222300420262',
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П/10',
            'code' => 'p10',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-001',
            'number' => 'П/10 от 01.02.2026',
            'status' => 'active',
            'starts_at' => '2026-02-01',
            'signed_at' => '2026-02-01',
            'is_active' => true,
        ]);

        $response = $this->postJson(route('api.1c.accruals.store'), [
            'calculated_at' => '2026-03-12 10:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-001',
                    'contract_external_id' => 'contract-001',
                    'period' => '2026-03',
                    'market_space_code' => 'П/10',
                    'source_place_code' => 'П/10',
                    'source_place_name' => 'П/10',
                    'activity_type' => 'rent',
                    'tenant_name' => 'ООО Тест',
                    'inn' => '222300420262',
                    'area_sqm' => 12.5,
                    'rent_rate' => 1200,
                    'rent_amount' => 15000,
                    'utilities_amount' => 500,
                    'electricity_amount' => 300,
                    'total_no_vat' => 15800,
                    'total_with_vat' => 15800,
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

        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'contract_external_id' => 'contract-001',
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_EXACT,
            'contract_link_source' => 'contract_external_id',
            'market_space_id' => $space->id,
            'period' => '2026-03-01',
            'source' => '1c',
            'source_place_code' => 'П/10',
        ]);
    }

    public function test_repeated_accrual_import_updates_same_row_instead_of_creating_duplicate(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'ООО Тест',
            'external_id' => 'tenant-002',
            'inn' => '333333333333',
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П/11',
            'code' => 'p11',
        ]);

        TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-002',
            'number' => 'П/11 от 01.02.2026',
            'status' => 'active',
            'starts_at' => '2026-02-01',
            'signed_at' => '2026-02-01',
            'is_active' => true,
        ]);

        $payload = [
            'calculated_at' => '2026-03-12 10:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-002',
                    'contract_external_id' => 'contract-002',
                    'period' => '2026-03',
                    'market_space_code' => 'П/11',
                    'source_place_code' => 'П/11',
                    'source_place_name' => 'П/11',
                    'activity_type' => 'rent',
                    'tenant_name' => 'ООО Тест',
                    'inn' => '333333333333',
                    'rent_amount' => 11000,
                    'utilities_amount' => 250,
                    'electricity_amount' => 150,
                    'total_no_vat' => 11400,
                    'total_with_vat' => 11400,
                    'currency' => 'RUB',
                ],
            ],
        ];

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $this->postJson(route('api.1c.accruals.store'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('updated', 0);

        $payload['items'][0]['rent_amount'] = 11900;
        $payload['items'][0]['total_no_vat'] = 12300;
        $payload['items'][0]['total_with_vat'] = 12300;

        $this->postJson(route('api.1c.accruals.store'), $payload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1);

        $this->assertSame(1, DB::table('tenant_accruals')->count());
        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'contract_external_id' => 'contract-002',
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_EXACT,
            'contract_link_source' => 'contract_external_id',
            'period' => '2026-03-01',
            'rent_amount' => 11900,
            'total_no_vat' => 12300,
            'total_with_vat' => 12300,
        ]);
    }

    public function test_accrual_import_updates_legacy_row_when_organization_fields_are_added(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Legacy Tenant',
            'external_id' => 'tenant-legacy-org',
            'inn' => '666666666666',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'external_id' => 'contract-legacy-org',
            'number' => 'Legacy contract',
            'status' => 'active',
            'starts_at' => '2026-02-01',
            'signed_at' => '2026-02-01',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'contract_external_id' => 'contract-legacy-org',
            'tenant_contract_id' => $contract->id,
            'market_space_id' => null,
            'period' => '2026-06-01',
            'source_place_code' => null,
            'source_place_name' => 'Legacy contract',
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
            'source_row_number' => 10,
            'source_row_hash' => hash('sha256', 'legacy-without-org'),
            'payload' => json_encode([
                'tenant_external_id' => 'tenant-legacy-org',
                'contract_external_id' => 'contract-legacy-org',
            ], JSON_UNESCAPED_UNICODE),
            'imported_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->postJson(route('api.1c.accruals.store'), [
            'calculated_at' => '2026-06-09 14:13:39',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-legacy-org',
                    'contract_external_id' => 'contract-legacy-org',
                    'period' => '2026-06',
                    'source_place_name' => 'Legacy contract',
                    'activity_type' => 'rent',
                    'organization_external_id' => 'org-001',
                    'organization_name' => 'IP Test',
                    'account' => '62.01',
                    'tenant_name' => 'Legacy Tenant',
                    'inn' => '666666666666',
                    'rent_amount' => 237510,
                    'management_fee' => 0,
                    'utilities_amount' => 0,
                    'electricity_amount' => 0,
                    'total_no_vat' => 237510,
                    'total_with_vat' => 237510,
                    'currency' => 'RUB',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('warnings.legacy_identity_matches', 1);

        $this->assertSame(1, DB::table('tenant_accruals')->count());
        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'contract_external_id' => 'contract-legacy-org',
            'organization_external_id' => 'org-001',
            'organization_name' => 'IP Test',
            'account' => '62.01',
            'tenant_contract_id' => $contract->id,
            'period' => '2026-06-01',
            'total_with_vat' => 237510,
        ]);
    }

    public function test_accrual_import_deletes_one_c_rows_missing_from_successful_period_snapshot(): void
    {
        $tenantA = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant A',
            'external_id' => 'tenant-snapshot-a',
            'inn' => '777777777771',
        ]);

        $tenantB = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant B',
            'external_id' => 'tenant-snapshot-b',
            'inn' => '777777777772',
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $items = [
            [
                'tenant_external_id' => 'tenant-snapshot-a',
                'contract_external_id' => 'contract-snapshot-a',
                'period' => '2026-06',
                'source_place_name' => 'A/1',
                'activity_type' => 'rent',
                'tenant_name' => 'Tenant A',
                'inn' => '777777777771',
                'total_no_vat' => 1000,
                'total_with_vat' => 1000,
                'currency' => 'RUB',
            ],
            [
                'tenant_external_id' => 'tenant-snapshot-b',
                'contract_external_id' => 'contract-snapshot-b',
                'period' => '2026-06',
                'source_place_name' => 'B/1',
                'activity_type' => 'rent',
                'tenant_name' => 'Tenant B',
                'inn' => '777777777772',
                'total_no_vat' => 2000,
                'total_with_vat' => 2000,
                'currency' => 'RUB',
            ],
        ];

        $this->postJson(route('api.1c.accruals.store'), [
            'calculated_at' => '2026-06-09 10:00:00',
            'items' => $items,
        ], $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 2)
            ->assertJsonPath('warnings.snapshot_deleted', 0);

        $this->assertSame(2, DB::table('tenant_accruals')->count());

        $this->postJson(route('api.1c.accruals.store'), [
            'calculated_at' => '2026-06-09 11:00:00',
            'items' => [$items[0]],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('warnings.snapshot_deleted', 1);

        $this->assertSame(1, DB::table('tenant_accruals')->count());
        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $this->market->id,
            'tenant_id' => $tenantA->id,
            'period' => '2026-06-01',
            'total_with_vat' => 1000,
        ]);
        $this->assertDatabaseMissing('tenant_accruals', [
            'market_id' => $this->market->id,
            'tenant_id' => $tenantB->id,
            'period' => '2026-06-01',
        ]);
    }

    public function test_snapshot_cleanup_does_not_touch_other_periods(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Period Tenant',
            'external_id' => 'tenant-period-scope',
            'inn' => '777777777773',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'contract_external_id' => 'contract-period-scope',
            'period' => '2026-05-01',
            'currency' => 'RUB',
            'total_no_vat' => 5000,
            'total_with_vat' => 5000,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => '1c:accruals',
            'source_row_number' => 1,
            'source_row_hash' => hash('sha256', 'may-snapshot-row'),
            'payload' => '{}',
            'imported_at' => now()->subDay(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->postJson(route('api.1c.accruals.store'), [
            'calculated_at' => '2026-06-09 12:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-period-scope',
                    'contract_external_id' => 'contract-period-scope',
                    'period' => '2026-06',
                    'tenant_name' => 'Period Tenant',
                    'inn' => '777777777773',
                    'total_no_vat' => 6000,
                    'total_with_vat' => 6000,
                    'currency' => 'RUB',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('warnings.snapshot_deleted', 0);

        $this->assertSame(2, DB::table('tenant_accruals')->count());
        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'period' => '2026-05-01',
            'total_with_vat' => 5000,
        ]);
    }

    public function test_backfill_payload_without_organization_fields_preserves_existing_org_context(): void
    {
        Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Backfill Tenant',
            'external_id' => 'tenant-backfill-org',
            'inn' => '777777777774',
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $currentPayload = [
            'calculated_at' => '2026-06-09 13:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-backfill-org',
                    'contract_external_id' => 'contract-backfill-org',
                    'period' => '2026-06',
                    'source_place_name' => 'Backfill place',
                    'activity_type' => 'rent',
                    'organization_external_id' => 'org-current',
                    'organization_name' => 'Current Org',
                    'account' => '62.01',
                    'tenant_name' => 'Backfill Tenant',
                    'inn' => '777777777774',
                    'total_no_vat' => 7000,
                    'total_with_vat' => 7000,
                    'currency' => 'RUB',
                ],
            ],
        ];

        $this->postJson(route('api.1c.accruals.store'), $currentPayload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $backfillPayload = $currentPayload;
        $backfillPayload['calculated_at'] = '2026-06-09 14:00:00';
        unset(
            $backfillPayload['items'][0]['organization_external_id'],
            $backfillPayload['items'][0]['organization_name'],
            $backfillPayload['items'][0]['account']
        );
        $backfillPayload['items'][0]['total_no_vat'] = 7100;
        $backfillPayload['items'][0]['total_with_vat'] = 7100;

        $this->postJson(route('api.1c.accruals.store'), $backfillPayload, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 0)
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('warnings.legacy_identity_matches', 1);

        $this->assertSame(1, DB::table('tenant_accruals')->count());
        $this->assertDatabaseHas('tenant_accruals', [
            'market_id' => $this->market->id,
            'organization_external_id' => 'org-current',
            'organization_name' => 'Current Org',
            'account' => '62.01',
            'total_with_vat' => 7100,
        ]);
    }

    public function test_sequence_preflight_corrects_lag_and_import_stays_successful(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequence preflight test is for PostgreSQL only.');
        }

        Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'ООО Sequence Test',
            'external_id' => 'tenant-seq-1',
            'inn' => '444444444444',
        ]);

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $basePayload = [
            'calculated_at' => '2026-03-20 10:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-seq-1',
                    'period' => '2026-03',
                    'market_space_code' => 'ТЕСТ/1',
                    'source_place_code' => 'ТЕСТ/1',
                    'source_place_name' => 'ТЕСТ/1',
                    'activity_type' => 'rent',
                    'tenant_name' => 'ООО Sequence Test',
                    'inn' => '444444444444',
                    'rent_amount' => 1000,
                    'utilities_amount' => 0,
                    'electricity_amount' => 0,
                    'total_no_vat' => 1000,
                    'total_with_vat' => 1000,
                    'currency' => 'RUB',
                ],
            ],
        ];

        $payload1 = $basePayload;
        $payload1['items'][0]['contract_external_id'] = 'seq-contract-1';
        $this->postJson(route('api.1c.accruals.store'), $payload1, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $payload2 = $basePayload;
        $payload2['items'][0]['contract_external_id'] = 'seq-contract-2';
        $payload2['items'][0]['period'] = '2026-04';
        $this->postJson(route('api.1c.accruals.store'), $payload2, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $sequenceName = DB::scalar("select pg_get_serial_sequence('tenant_accruals', 'id')");
        $this->assertIsString($sequenceName);
        DB::selectOne('select setval(?::regclass, 1, true)', [$sequenceName]);

        $payload3 = $basePayload;
        $payload3['items'][0]['contract_external_id'] = 'seq-contract-3';
        $payload3['items'][0]['period'] = '2026-05';

        $this->postJson(route('api.1c.accruals.store'), $payload3, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1)
            ->assertJsonPath('warnings.sequence_preflight.corrected', true);

        $this->assertSame(3, DB::table('tenant_accruals')->count());
    }

    public function test_import_exception_rolls_back_and_marks_exchange_as_error(): void
    {
        $this->mock(TenantAccrualContractResolver::class, function ($mock): void {
            $mock->shouldReceive('resolveMatch')
                ->andThrow(new RuntimeException('forced accrual resolver failure'));
        });

        $headers = [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ];

        $response = $this->postJson(route('api.1c.accruals.store'), [
            'calculated_at' => '2026-03-20 10:00:00',
            'items' => [
                [
                    'tenant_external_id' => 'tenant-error-1',
                    'contract_external_id' => 'contract-error-1',
                    'period' => '2026-03',
                    'source_place_name' => 'ERROR/1',
                    'activity_type' => 'rent',
                    'tenant_name' => 'ООО Error Test',
                    'inn' => '555555555555',
                    'rent_amount' => 1500,
                    'utilities_amount' => 0,
                    'electricity_amount' => 0,
                    'total_no_vat' => 1500,
                    'total_with_vat' => 1500,
                    'currency' => 'RUB',
                ],
            ],
        ], $headers);

        $response->assertStatus(500);
        $this->assertSame(0, DB::table('tenant_accruals')->count());

        $exchange = IntegrationExchange::query()
            ->where('entity_type', 'accruals')
            ->latest('id')
            ->first();

        $this->assertNotNull($exchange);
        $this->assertSame(IntegrationExchange::STATUS_ERROR, $exchange->status);
        $this->assertNotNull($exchange->finished_at);
        $this->assertStringContainsString('forced accrual resolver failure', (string) $exchange->error);
        $this->assertSame(
            0,
            IntegrationExchange::query()
                ->where('entity_type', 'accruals')
                ->where('status', IntegrationExchange::STATUS_IN_PROGRESS)
                ->count()
        );
    }
}
