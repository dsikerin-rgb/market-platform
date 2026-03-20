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
        $this->postJson(route('api.1c.accruals.store'), $payload2, $headers)
            ->assertOk()
            ->assertJsonPath('inserted', 1);

        $sequenceName = DB::scalar("select pg_get_serial_sequence('tenant_accruals', 'id')");
        $this->assertIsString($sequenceName);
        DB::selectOne('select setval(?::regclass, 1, true)', [$sequenceName]);

        $payload3 = $basePayload;
        $payload3['items'][0]['contract_external_id'] = 'seq-contract-3';

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
