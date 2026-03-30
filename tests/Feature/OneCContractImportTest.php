<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OneCContractImportTest extends TestCase
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

        $this->token = 'test-1c-token-' . uniqid();

        $this->integration = MarketIntegration::create([
            'market_id' => $this->market->id,
            'type' => MarketIntegration::TYPE_1C,
            'name' => '1C Integration',
            'auth_token' => $this->token,
            'status' => 'active',
        ]);
    }

    /**
     * Тест: договор успешно привязывается к месту по market_space_code
     */
    public function test_contract_linked_successfully_by_market_space_code(): void
    {
        // Создаём место с кодом "П3/2"
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П3/2',
            'code' => 'p3-2',
        ]);

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-001',
            'name' => 'ООО Тест',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-001',
                    'tenant_external_id' => 'tenant-001',
                    'market_space_code' => 'П3/2',  // точное совпадение
                    'contract_number' => '1',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonPath('linkage_stats.linked_contracts', 1);
        $response->assertJsonPath('linkage_stats.contracts_without_space_key', 0);
        $response->assertJsonPath('linkage_stats.contracts_space_not_found', 0);
        $response->assertJsonPath('linkage_stats.contracts_space_ambiguous', 0);

        // Проверяем, что договор создан и привязан к месту
        $contract = TenantContract::query()
            ->where('external_id', 'contract-001')
            ->first();

        $this->assertNotNull($contract);
        $this->assertEquals($space->id, $contract->market_space_id);
        $this->assertEquals($tenant->id, $contract->tenant_id);
    }

    /**
     * Тест: договор без market_space_code остаётся непривязанным
     */
    public function test_contract_without_market_space_code_stays_unlinked(): void
    {
        Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-002',
            'name' => 'ООО Тест 2',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-002',
                    'tenant_external_id' => 'tenant-002',
                    // market_space_code отсутствует
                    'contract_number' => '2',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('linkage_stats.contracts_without_space_key', 1);

        // Проверяем, что договор создан, но НЕ привязан к месту
        $contract = TenantContract::query()
            ->where('external_id', 'contract-002')
            ->first();

        $this->assertNotNull($contract);
        $this->assertNull($contract->market_space_id);
        
        // Проверяем, что в diagnostics есть пример
        $response->assertJsonPath('warnings.diagnostics.missing_space_key.count', 1);
    }

    /**
     * Тест: договор с неизвестным market_space_code остаётся непривязанным
     */
    public function test_contract_with_unknown_market_space_code_stays_unlinked(): void
    {
        Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-003',
            'name' => 'ООО Тест 3',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-003',
                    'tenant_external_id' => 'tenant-003',
                    'market_space_code' => 'UNKNOWN-999',  // такого места нет
                    'contract_number' => '3',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('linkage_stats.contracts_space_not_found', 1);

        // Проверяем, что договор создан, но НЕ привязан к месту
        $contract = TenantContract::query()
            ->where('external_id', 'contract-003')
            ->first();

        $this->assertNotNull($contract);
        $this->assertNull($contract->market_space_id);
        
        // Проверяем, что в diagnostics есть пример
        $response->assertJsonPath('warnings.diagnostics.space_not_found.count', 1);
    }

    /**
     * Тест: договор с неоднозначным market_space_code остаётся непривязанным
     */
    public function test_contract_with_ambiguous_market_space_code_stays_unlinked(): void
    {
        // Создаём ДВА места с одинаковым кодом (коллизия)
        $space1 = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П5',
            'code' => 'p5',
        ]);

        $space2 = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П5',  // тот же номер!
            'code' => 'p5-duplicate',
        ]);

        Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-004',
            'name' => 'ООО Тест 4',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-004',
                    'tenant_external_id' => 'tenant-004',
                    'market_space_code' => 'П5',  // неоднозначный ключ
                    'contract_number' => '4',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('linkage_stats.contracts_space_ambiguous', 1);

        // Проверяем, что договор создан, но НЕ привязан к месту
        $contract = TenantContract::query()
            ->where('external_id', 'contract-004')
            ->first();

        $this->assertNotNull($contract);
        $this->assertNull($contract->market_space_id);
        
        // Проверяем, что в diagnostics есть пример
        $response->assertJsonPath('warnings.diagnostics.space_ambiguous.count', 1);
    }

    /**
     * Тест: повторный импорт обновляет ранее непривязанный договор
     */
    public function test_repeated_import_updates_previously_unlinked_contract(): void
    {
        // Создаём место
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П10',
            'code' => 'p10',
        ]);

        Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-005',
            'name' => 'ООО Тест 5',
        ]);

        // Первый импорт: без market_space_code
        $response1 = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-005',
                    'tenant_external_id' => 'tenant-005',
                    // нет market_space_code
                    'contract_number' => '5',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response1->assertOk();
        $response1->assertJsonPath('linkage_stats.contracts_without_space_key', 1);

        // Проверяем, что договор создан, но НЕ привязан
        $contract = TenantContract::query()
            ->where('external_id', 'contract-005')
            ->first();

        $this->assertNotNull($contract);
        $this->assertNull($contract->market_space_id);

        // Второй импорт: с правильным market_space_code
        $response2 = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-005',  // тот же договор
                    'tenant_external_id' => 'tenant-005',
                    'market_space_code' => 'П10',  // теперь есть ключ
                    'contract_number' => '5',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response2->assertOk();
        $response2->assertJsonPath('linkage_stats.linked_contracts', 1);

        // Проверяем, что договор ОБНОВЛЁН и привязан к месту
        $contract->refresh();
        $this->assertEquals($space->id, $contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_AUTO, $contract->space_mapping_mode);
    }

    /**
     * Тест: ручная локальная привязка не перезаписывается следующим импортом 1С
     */
    public function test_manual_space_mapping_is_preserved_on_repeated_import(): void
    {
        $lockedSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П11',
            'code' => 'p11',
        ]);

        $oneCSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П12',
            'code' => 'p12',
        ]);

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-007',
            'name' => 'ООО Тест 7',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $lockedSpace->id,
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_MANUAL,
            'external_id' => 'contract-007',
            'number' => '7',
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'is_active' => true,
            'notes' => 'Manual mapping lock',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-007',
                    'tenant_external_id' => 'tenant-007',
                    'market_space_code' => 'П12',
                    'contract_number' => '7',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('warnings.manual_space_mappings_preserved', 1);

        $contract->refresh();

        $this->assertSame($lockedSpace->id, $contract->market_space_id);
        $this->assertNotSame($oneCSpace->id, $contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_MANUAL, $contract->space_mapping_mode);
    }

    /**
     * Тест: договор, исключенный из привязки к месту, не получает место при следующем импорте 1С.
     */
    public function test_excluded_space_mapping_is_preserved_on_repeated_import(): void
    {
        $oneCSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'P13',
            'code' => 'p13',
        ]);

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-008',
            'name' => 'ООО Тест 8',
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => null,
            'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_EXCLUDED,
            'external_id' => 'contract-008',
            'number' => 'Договор на возмещение коммунальных услуг от 01.07.',
            'status' => 'active',
            'starts_at' => now()->toDateString(),
            'is_active' => true,
            'notes' => 'Excluded from space mapping',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-008',
                    'tenant_external_id' => 'tenant-008',
                    'market_space_code' => 'P13',
                    'contract_number' => 'Договор на возмещение коммунальных услуг от 01.07.',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('warnings.manual_space_mappings_preserved', 1);

        $contract->refresh();

        $this->assertNull($contract->market_space_id);
        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_EXCLUDED, $contract->space_mapping_mode);
        $this->assertNotSame($oneCSpace->id, $contract->market_space_id);
    }

    /**
     * Тест: нормализация ключа (uppercase, trim)
     */
    /**
     * Тест: импорт договора не создаёт дубль арендатора, если тот уже найден по ИНН.
     */
    public function test_contract_import_reuses_existing_tenant_found_by_inn(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'ООО Старый арендатор',
            'inn' => '222300420262',
            'external_id' => null,
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-inn-001',
                    'tenant_external_id' => 'tenant-inn-001',
                    'contract_number' => 'ИНН-1',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                    'inn' => '222300420262',
                    'tenant_name' => 'ООО Новый арендатор',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');

        $this->assertSame(1, Tenant::query()->count());

        $tenant->refresh();
        $this->assertSame('tenant-inn-001', $tenant->external_id);
        $this->assertSame('222300420262', $tenant->inn);

        $contract = TenantContract::query()
            ->where('external_id', 'contract-inn-001')
            ->first();

        $this->assertNotNull($contract);
        $this->assertSame((int) $tenant->id, (int) $contract->tenant_id);
    }

    public function test_contract_import_warns_about_suspicious_current_duplicates(): void
    {
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П/75',
            'code' => 'p-75',
        ]);

        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-dup-001',
                    'tenant_external_id' => 'tenant-dup-001',
                    'market_space_code' => 'П/75',
                    'contract_number' => 'П/75 от 01.04.2025',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                    'tenant_name' => 'ООО Дубль',
                ],
                [
                    'contract_external_id' => 'contract-dup-002',
                    'tenant_external_id' => 'tenant-dup-001',
                    'market_space_code' => 'П/75',
                    'contract_number' => 'П/75 к от 01.04.2025',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                    'tenant_name' => 'ООО Дубль',
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('warnings.suspected_current_duplicate_contract_groups', 1);
        $response->assertJsonPath('warnings.suspected_current_duplicate_contract_rows', 2);
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.count', 1);
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.rows', 2);
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.samples.0.market_space_id', $space->id);
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.samples.0.place_token', 'П/75');
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.samples.0.document_date', '2025-04-01');
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.samples.0.contract_ids', [1, 2]);
        $response->assertJsonPath('warnings.suspected_current_duplicate_contracts.samples.0.external_ids', [
            'contract-dup-001',
            'contract-dup-002',
        ]);
    }

    public function test_key_normalization_uppercase_trim(): void
    {
        // Создаём место с кодом "П3/2"
        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'number' => 'П3/2',
            'code' => 'p3-2',
        ]);

        Tenant::create([
            'market_id' => $this->market->id,
            'external_id' => 'tenant-006',
            'name' => 'ООО Тест 6',
        ]);

        // Отправляем ключ в разных форматах
        $response = $this->postJson(route('api.1c.contracts.store'), [
            'calculated_at' => now()->toDateTimeString(),
            'items' => [
                [
                    'contract_external_id' => 'contract-006',
                    'tenant_external_id' => 'tenant-006',
                    'market_space_code' => '  п3/2  ',  // lowercase + пробелы
                    'contract_number' => '6',
                    'status' => 'active',
                    'starts_at' => now()->toDateString(),
                    'is_active' => true,
                ],
            ],
        ], [
            'Authorization' => 'Bearer ' . $this->token,
            'Accept' => 'application/json',
        ]);

        $response->assertOk();
        $response->assertJsonPath('linkage_stats.linked_contracts', 1);

        $contract = TenantContract::query()
            ->where('external_id', 'contract-006')
            ->first();

        $this->assertNotNull($contract);
        $this->assertEquals($space->id, $contract->market_space_id);
    }
}
