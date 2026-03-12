<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
            'period' => '2026-03-01',
            'rent_amount' => 11900,
            'total_no_vat' => 12300,
            'total_with_vat' => 12300,
        ]);
    }
}
