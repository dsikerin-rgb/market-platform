<?php

namespace Tests\Unit\Services\Debt;

use App\Models\Market;
use App\Models\Tenant;
use App\Services\Debt\DebtStatusResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebtStatusResolverTest extends TestCase
{
    use RefreshDatabase;

    private DebtStatusResolver $resolver;
    private Market $market;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->resolver = app(DebtStatusResolver::class);
        
        $this->market = Market::create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'red_after_days' => 90,
                ],
            ],
        ]);
    }

    public function test_manual_status_green(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-001',
            'debt_status' => 'green',
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('manual', $result['mode']);
        $this->assertEquals('green', $result['status']);
        $this->assertEquals('Нет задолженности', $result['label']);
    }

    public function test_manual_status_orange(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-002',
            'debt_status' => 'orange',
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('manual', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertEquals('Задолженность до 3 месяцев', $result['label']);
    }

    public function test_manual_status_red(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-003',
            'debt_status' => 'red',
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('manual', $result['mode']);
        $this->assertEquals('red', $result['status']);
        $this->assertEquals('Задолженность свыше 3 месяцев', $result['label']);
    }

    public function test_auto_status_green_no_debt(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-004',
            'debt_status' => null,
        ]);

        // Создаём запись в contract_debts с нулевым долгом
        $hash = sha1($tenant->external_id . '|contract-' . $tenant->external_id . '|2026-03|10000|10000|0');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-' . $tenant->external_id,
            'period' => '2026-03',
            'accrued_amount' => 10000,
            'paid_amount' => 10000,
            'debt_amount' => 0,
            'calculated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'hash' => $hash,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('green', $result['status']);
        $this->assertEquals('Нет задолженности', $result['label']);
    }

    public function test_auto_status_pending_not_due_yet(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-005',
            'debt_status' => null,
        ]);

        // Создаём запись с долгом, но срок оплаты ещё не наступил (calculated_at + 3 дня)
        $hash = sha1($tenant->external_id . '|contract-' . $tenant->external_id . '|2026-03|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-' . $tenant->external_id,
            'period' => '2026-03',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(3),
            'created_at' => Carbon::now()->subDays(3),
            'hash' => $hash,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('К оплате / срок не наступил', $result['label']);
    }

    public function test_auto_status_orange_overdue_less_than_90_days(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-006',
            'debt_status' => null,
        ]);

        // Создаём запись с долгом, просрочка 30 дней
        $hash = sha1($tenant->external_id . '|contract-' . $tenant->external_id . '|2026-02|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-' . $tenant->external_id,
            'period' => '2026-02',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(35),
            'created_at' => Carbon::now()->subDays(35),
            'hash' => $hash,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertEquals('Задолженность до 3 месяцев', $result['label']);
    }

    public function test_auto_status_red_overdue_90_days_or_more(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-007',
            'debt_status' => null,
        ]);

        // Создаём запись с долгом, просрочка 100 дней
        $hash = sha1($tenant->external_id . '|contract-' . $tenant->external_id . '|2025-11|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-' . $tenant->external_id,
            'period' => '2025-11',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(105),
            'created_at' => Carbon::now()->subDays(105),
            'hash' => $hash,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('red', $result['status']);
        $this->assertEquals('Задолженность свыше 3 месяцев', $result['label']);
    }

    public function test_auto_status_green_no_records(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-008',
            'debt_status' => null,
        ]);

        // Нет записей в contract_debts — это означает green (нет долга)

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('green', $result['status']);
        $this->assertEquals('Нет задолженности', $result['label']);
    }

    public function test_auto_status_gray_cannot_determine_due_date(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-009',
            'debt_status' => null,
        ]);

        // Создаём запись с долгом, calculated_at, но с пустым period (невалидный формат)
        // Сервис не сможет корректно распарсить период → gray
        $hash = sha1($tenant->external_id . '|contract-' . $tenant->external_id . '||10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-' . $tenant->external_id,
            'period' => '',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'hash' => $hash,
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('gray', $result['status']);
        $this->assertEquals('Нет данных', $result['label']);
    }

    public function test_severity_levels(): void
    {
        // green = 0
        $tenantGreen = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-010',
            'debt_status' => 'green',
        ]);
        $resultGreen = $this->resolver->resolve($tenantGreen);
        $this->assertEquals(0, $resultGreen['severity']);

        // pending = 1
        $tenantPending = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-011',
            'debt_status' => null,
        ]);
        $hashPending = sha1($tenantPending->external_id . '|contract-' . $tenantPending->external_id . '|2026-03|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenantPending->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenantPending->external_id,
            'contract_external_id' => 'contract-' . $tenantPending->external_id,
            'period' => '2026-03',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(3),
            'created_at' => Carbon::now()->subDays(3),
            'hash' => $hashPending,
        ]);
        $resultPending = $this->resolver->resolve($tenantPending);
        $this->assertEquals(1, $resultPending['severity']);

        // orange = 2
        $tenantOrange = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-012',
            'debt_status' => null,
        ]);
        $hashOrange = sha1($tenantOrange->external_id . '|contract-' . $tenantOrange->external_id . '|2026-02|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenantOrange->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenantOrange->external_id,
            'contract_external_id' => 'contract-' . $tenantOrange->external_id,
            'period' => '2026-02',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(35),
            'created_at' => Carbon::now()->subDays(35),
            'hash' => $hashOrange,
        ]);
        $resultOrange = $this->resolver->resolve($tenantOrange);
        $this->assertEquals(2, $resultOrange['severity']);

        // red = 3
        $tenantRed = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-013',
            'debt_status' => null,
        ]);
        $hashRed = sha1($tenantRed->external_id . '|contract-' . $tenantRed->external_id . '|2025-11|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenantRed->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenantRed->external_id,
            'contract_external_id' => 'contract-' . $tenantRed->external_id,
            'period' => '2025-11',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(105),
            'created_at' => Carbon::now()->subDays(105),
            'hash' => $hashRed,
        ]);
        $resultRed = $this->resolver->resolve($tenantRed);
        $this->assertEquals(3, $resultRed['severity']);
    }

    public function test_cache_works(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-014',
            'debt_status' => 'green',
        ]);

        // Первый вызов
        $result1 = $this->resolver->resolve($tenant);

        // Второй вызов должен вернуть кэшированный результат (tenant не менялся)
        $result2 = $this->resolver->resolve($tenant);

        $this->assertEquals($result1, $result2);

        // Очищаем кеш и проверяем снова
        DebtStatusResolver::clearCache();
        $result3 = $this->resolver->resolve($tenant);

        // После очистки кеша результат должен быть тем же (данные не менялись)
        $this->assertEquals($result1['status'], $result3['status']);
        $this->assertEquals($result1['label'], $result3['label']);
    }
}
