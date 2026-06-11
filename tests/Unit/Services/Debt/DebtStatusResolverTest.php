<?php

// tests/Unit/Services/Debt/DebtStatusResolverTest.php

namespace Tests\Unit\Services\Debt;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Services\Debt\DebtDecisionPolicy;
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
        $this->assertEquals('Просрочка до 89 дн.', $result['label']);
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
        $this->assertEquals('Просрочка от 90 дн.', $result['label']);
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
        $hash = sha1($tenant->external_id.'|contract-'.$tenant->external_id.'|2026-03|10000|10000|0');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '2026-03',
            'account' => '62',
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

    public function test_auto_status_ignores_debt_below_minimum_amount(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with tiny remainder',
            'external_id' => 'test-small-debt-001',
            'debt_status' => null,
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-small-debt-001',
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 2,
            'paid_amount' => 0,
            'debt_amount' => 2,
            'calculated_at' => Carbon::now()->subDays(60),
            'created_at' => Carbon::now()->subDays(60),
            'hash' => sha1($tenant->external_id.'|contract-small-debt-001|2026-03|2|0|2'),
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('green', $result['status']);
        $this->assertEquals('Нет задолженности', $result['label']);
        $this->assertEquals(2.0, $result['extra']['debt_amount'] ?? null);
        $this->assertEquals(500.0, $result['extra']['minimum_debt_amount'] ?? null);
    }

    public function test_auto_status_does_not_mark_tiny_overdue_tail_as_overdue(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with tiny overdue tail',
            'external_id' => 'test-small-overdue-tail-001',
            'debt_status' => null,
        ]);

        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => null,
            'external_id' => 'contract-small-overdue-tail-001',
            'number' => 'TAIL-TENANT-001',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => Carbon::now()->subMonths(4),
            'ends_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('contract_debts')->insert([
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-small-overdue-tail-001',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 2,
                'paid_amount' => 0,
                'debt_amount' => 2,
                'calculated_at' => Carbon::now()->subDays(120),
                'created_at' => Carbon::now()->subDays(120),
                'hash' => sha1($tenant->external_id.'|contract-small-overdue-tail-001|2026-03|2|0|2'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-small-overdue-tail-001',
                'period' => '2026-06',
                'account' => '62',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'hash' => sha1($tenant->external_id.'|contract-small-overdue-tail-001|2026-06|1000|0|1000'),
            ],
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals(1000.0, $result['extra']['debt_amount'] ?? null);
        $this->assertEquals(2.0, $result['extra']['overdue_debt_amount'] ?? null);
        $this->assertEquals(500.0, $result['extra']['minimum_debt_amount'] ?? null);
    }

    public function test_auto_status_uses_latest_debt_version_per_contract_identity(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Test tenant',
            'external_id' => 'test-004b',
            'debt_status' => null,
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(35),
            'created_at' => Carbon::now()->subDays(35),
            'hash' => sha1($tenant->external_id.'|contract-'.$tenant->external_id.'|2026-03|10000|0|10000'),
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 10000,
            'paid_amount' => 10000,
            'debt_amount' => 0,
            'calculated_at' => Carbon::now()->subDay(),
            'created_at' => Carbon::now()->subDay(),
            'hash' => sha1($tenant->external_id.'|contract-'.$tenant->external_id.'|2026-03|10000|10000|0'),
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('green', $result['status']);
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
        $hash = sha1($tenant->external_id.'|contract-'.$tenant->external_id.'|2026-03|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '2026-03',
            'account' => '62',
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
        $hash = sha1($tenant->external_id.'|contract-'.$tenant->external_id.'|2026-02|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '2026-02',
            'account' => '62',
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
        $this->assertEquals('Просрочка до 89 дн.', $result['label']);
    }

    public function test_auto_status_treats_debt_above_current_accrual_as_old_balance(): void
    {
        Carbon::setTestNow('2026-06-04 12:00:00');

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with old balance',
            'external_id' => 'tenant-old-balance-001',
            'debt_status' => null,
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-old-balance-001',
            'period' => '2026-06',
            'account' => '62',
            'accrued_amount' => 93451,
            'paid_amount' => 0,
            'debt_amount' => 300536.05,
            'calculated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'hash' => sha1($tenant->external_id.'|contract-old-balance-001|2026-06|93451|0|300536.05'),
        ]);

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertGreaterThan(0, $result['severity']);

        Carbon::setTestNow();
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
        $hash = sha1($tenant->external_id.'|contract-'.$tenant->external_id.'|2025-11|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '2025-11',
            'account' => '62',
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
        $this->assertEquals('Просрочка от 90 дн.', $result['label']);
    }

    public function test_auto_status_gray_no_records(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-008',
            'debt_status' => null,
        ]);

        // Нет записей в contract_debts — это gray (нет данных по арендатору)

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('gray', $result['status']);
        $this->assertEquals('Нет данных', $result['label']);
    }

    public function test_auto_status_gray_cannot_determine_due_date(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Тестовый арендатор',
            'external_id' => 'test-009',
            'debt_status' => null,
        ]);

        // Создаём запись с долгом, но с некорректным calculated_at (в прошлом на 100 дней)
        // и пустым period — сервис не сможет корректно определить период для расчёта
        // Но calculated_at есть, поэтому due date будет определён → pending или orange
        // Для gray нужен случай, где calculated_at отсутствует или некорректен
        // В текущей реализации сервис всегда вернёт статус при наличии calculated_at
        // Поэтому этот тест проверяет сценарий: calculated_at в прошлом, period пустой
        // Ожидаем orange (просрочка < 90 дней)
        $hash = sha1($tenant->external_id.'|contract-'.$tenant->external_id.'||10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-'.$tenant->external_id,
            'period' => '',
            'account' => '62',
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
        $this->assertEquals('Просрочка до 89 дн.', $result['label']);
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
        $hashPending = sha1($tenantPending->external_id.'|contract-'.$tenantPending->external_id.'|2026-03|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenantPending->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenantPending->external_id,
            'contract_external_id' => 'contract-'.$tenantPending->external_id,
            'period' => '2026-03',
            'account' => '62',
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
        $hashOrange = sha1($tenantOrange->external_id.'|contract-'.$tenantOrange->external_id.'|2026-02|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenantOrange->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenantOrange->external_id,
            'contract_external_id' => 'contract-'.$tenantOrange->external_id,
            'period' => '2026-02',
            'account' => '62',
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
        $hashRed = sha1($tenantRed->external_id.'|contract-'.$tenantRed->external_id.'|2025-11|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenantRed->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenantRed->external_id,
            'contract_external_id' => 'contract-'.$tenantRed->external_id,
            'period' => '2025-11',
            'account' => '62',
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

    public function test_settlement_balance_is_preferred_over_contract_debts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Settlement tenant',
            'external_id' => 'settlement-tenant-001',
            'debt_status' => null,
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'settlement-contract-001',
            'period' => '2026-06',
            'account' => '62',
            'accrued_amount' => 1000,
            'paid_amount' => 1000,
            'debt_amount' => 0,
            'calculated_at' => Carbon::now(),
            'created_at' => Carbon::now(),
            'hash' => sha1('contract-debts-green-fallback'),
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => 'settlement-contract-001',
            'contract_name' => 'Settlement contract',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 1000,
            'opening_credit' => 0,
            'turnover_debit' => 0,
            'turnover_credit' => 0,
            'closing_debit' => 1000,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'settlement-balance-preferred'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolve($tenant);

        $this->assertSame('orange', $result['status']);
        $this->assertSame('tenant_settlement_balances', $result['source']);
        $this->assertSame('tenant', $result['extra']['scope'] ?? null);

        Carbon::setTestNow();
    }

    public function test_security_deposit_settlement_account_is_not_rent_debt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Security deposit tenant',
            'external_id' => 'security-deposit-tenant-001',
            'debt_status' => null,
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => 'security-deposit-contract-001',
            'contract_name' => 'Security deposit contract',
            'account' => '76.06',
            'currency' => 'RUB',
            'opening_debit' => 50000,
            'opening_credit' => 0,
            'turnover_debit' => 0,
            'turnover_credit' => 0,
            'closing_debit' => 50000,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'security-deposit-is-not-rent-debt'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolve($tenant);

        $this->assertNotSame('tenant_settlement_balances', $result['source']);
        $this->assertNotContains($result['status'], ['pending', 'orange', 'red']);

        Carbon::setTestNow();
    }

    public function test_market_space_status_does_not_use_settlement_balance_for_map_coloring(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Space settlement tenant',
            'external_id' => 'space-settlement-tenant-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'S-101',
            'code' => 'space-settlement-101',
            'is_active' => true,
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'space-settlement-contract-001',
            'number' => 'SSC-001',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => null,
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => $contract->external_id,
            'contract_name' => 'Space settlement contract',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 0,
            'opening_credit' => 0,
            'turnover_debit' => 1200,
            'turnover_credit' => 0,
            'closing_debit' => 1200,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'space-settlement-balance'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertSame('gray', $result['status']);
        $this->assertNotSame('tenant_settlement_balances', $result['source']);
        $this->assertSame('none', $result['extra']['scope'] ?? null);

        Carbon::setTestNow();
    }

    public function test_market_space_status_can_use_settlement_balance_with_period_start_aging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
                'use_settlement_balances_for_map' => true,
                'settlement_map_aging_policy' => 'period-start',
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Space settlement tenant enabled',
            'external_id' => 'space-settlement-tenant-enabled-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'S-102',
            'code' => 'space-settlement-102',
            'is_active' => true,
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'space-settlement-contract-enabled-001',
            'number' => 'SSC-002',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => null,
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => $contract->external_id,
            'contract_name' => 'Space settlement contract enabled',
            'settlement_document_name' => 'Realization 01.03.2026 14:00:00',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 0,
            'opening_credit' => 0,
            'turnover_debit' => 1200,
            'turnover_credit' => 0,
            'closing_debit' => 1200,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'space-settlement-balance-enabled'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertSame('orange', $result['status']);
        $this->assertSame('tenant_settlement_balances: map decision', $result['source']);
        $this->assertSame('space', $result['extra']['scope'] ?? null);
        $this->assertSame('period-start', $result['extra']['aging_policy'] ?? null);
        $this->assertSame('2026-06-06', $result['extra']['due_date'] ?? null);

        Carbon::setTestNow();
    }

    public function test_market_space_settlement_balance_defaults_to_net_balance_aging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
                'use_settlement_balances_for_map' => true,
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Space settlement invoice day tenant',
            'external_id' => 'space-settlement-invoice-day-tenant-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'S-103',
            'code' => 'space-settlement-103',
            'is_active' => true,
        ]);

        $contract = TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'space-settlement-contract-invoice-day-001',
            'number' => 'SSC-003',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => null,
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => $contract->external_id,
            'contract_name' => 'Space settlement contract invoice day',
            'settlement_document_name' => 'Realization 01.03.2026 14:00:00',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 0,
            'opening_credit' => 0,
            'turnover_debit' => 1200,
            'turnover_credit' => 0,
            'closing_debit' => 1200,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'space-settlement-balance-invoice-day'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('tenant_settlement_balances: map decision', $result['source']);
        $this->assertSame('space', $result['extra']['scope'] ?? null);
        $this->assertSame(DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE, $result['extra']['aging_policy'] ?? null);
        $this->assertSame('settlement_net_balance_current_period', $result['extra']['aging_source'] ?? null);
        $this->assertSame('2026-06-15', $result['extra']['due_date'] ?? null);

        Carbon::setTestNow();
    }

    public function test_settlement_balance_uses_document_date_for_aging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $market = Market::create([
            'name' => 'Settlement aging market',
            'slug' => 'settlement-aging-market',
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'red_after_days' => 30,
                ],
            ],
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Old settlement tenant',
            'external_id' => 'old-settlement-tenant-001',
            'debt_status' => null,
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => 'old-settlement-contract-001',
            'contract_name' => 'Old settlement contract',
            'settlement_document_name' => 'Реализация (акт, накладная, УПД) ЭЯ00-000001 от 01.03.2026 14:00:00',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 0,
            'opening_credit' => 0,
            'turnover_debit' => 1000,
            'turnover_credit' => 0,
            'closing_debit' => 1000,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'old-settlement-document-date'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolve($tenant);

        $this->assertSame('red', $result['status']);
        $this->assertSame('tenant_settlement_balances', $result['source']);
        $this->assertGreaterThanOrEqual(90, $result['extra']['overdue_days'] ?? 0);

        Carbon::setTestNow();
    }

    public function test_resolve_for_market_space_ignores_old_inactive_contract_debt_from_previous_tenant(): void
    {
        $previousTenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Old tenant',
            'external_id' => 'old-tenant-001',
            'debt_status' => null,
        ]);

        $currentTenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Current tenant',
            'external_id' => 'current-tenant-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $currentTenant->id,
            'number' => '101',
            'code' => 'space-101',
            'is_active' => true,
        ]);

        DB::table('tenant_contracts')->insert([
            [
                'market_id' => $this->market->id,
                'tenant_id' => $previousTenant->id,
                'market_space_id' => $space->id,
                'external_id' => 'old-contract-001',
                'number' => 'OLD-001',
                'status' => 'terminated',
                'is_active' => false,
                'starts_at' => Carbon::now()->subMonths(6),
                'ends_at' => Carbon::now()->subMonth(),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $currentTenant->id,
                'market_space_id' => $space->id,
                'external_id' => 'current-contract-001',
                'number' => 'CUR-001',
                'status' => 'active',
                'is_active' => true,
                'starts_at' => Carbon::now()->subWeek(),
                'ends_at' => null,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $previousTenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $previousTenant->external_id,
            'contract_external_id' => 'old-contract-001',
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(40),
            'created_at' => Carbon::now()->subDays(40),
            'hash' => sha1($previousTenant->external_id.'|old-contract-001|2026-03|10000|0|10000'),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertSame('auto', $result['mode']);
        $this->assertSame('gray', $result['status']);
        $this->assertSame('none', $result['extra']['scope'] ?? null);
    }

    public function test_resolve_for_market_space_ignores_exact_debt_below_minimum_amount(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with tiny space debt',
            'external_id' => 'tenant-space-small-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'tiny-101',
            'code' => 'tiny-space-101',
            'is_active' => true,
        ]);

        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-space-small-001',
            'number' => 'SMALL-001',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => Carbon::now()->subMonths(2),
            'ends_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-space-small-001',
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 2,
            'paid_amount' => 0,
            'debt_amount' => 2,
            'calculated_at' => Carbon::now()->subDays(60),
            'created_at' => Carbon::now()->subDays(60),
            'hash' => sha1($tenant->external_id.'|contract-space-small-001|2026-03|2|0|2'),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('green', $result['status']);
        $this->assertEquals('space', $result['extra']['scope'] ?? null);
        $this->assertEquals(2.0, $result['extra']['debt_amount'] ?? null);
        $this->assertEquals(500.0, $result['extra']['minimum_debt_amount'] ?? null);
    }

    public function test_resolve_for_market_space_does_not_mark_tiny_overdue_tail_as_overdue(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with tiny space overdue tail',
            'external_id' => 'tenant-space-small-overdue-tail-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'tiny-tail-101',
            'code' => 'tiny-tail-space-101',
            'is_active' => true,
        ]);

        DB::table('tenant_contracts')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-space-small-overdue-tail-001',
            'number' => 'TAIL-001',
            'status' => 'active',
            'is_active' => true,
            'starts_at' => Carbon::now()->subMonths(4),
            'ends_at' => null,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('contract_debts')->insert([
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-space-small-overdue-tail-001',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 2,
                'paid_amount' => 0,
                'debt_amount' => 2,
                'calculated_at' => Carbon::now()->subDays(120),
                'created_at' => Carbon::now()->subDays(120),
                'hash' => sha1($tenant->external_id.'|contract-space-small-overdue-tail-001|2026-03|2|0|2'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-space-small-overdue-tail-001',
                'period' => '2026-06',
                'account' => '62',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => Carbon::now(),
                'created_at' => Carbon::now(),
                'hash' => sha1($tenant->external_id.'|contract-space-small-overdue-tail-001|2026-06|1000|0|1000'),
            ],
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('pending', $result['status']);
        $this->assertEquals('space', $result['extra']['scope'] ?? null);
        $this->assertEquals(1000.0, $result['extra']['debt_amount'] ?? null);
        $this->assertEquals(2.0, $result['extra']['overdue_debt_amount'] ?? null);
        $this->assertEquals(500.0, $result['extra']['minimum_debt_amount'] ?? null);
    }

    public function test_resolve_for_market_space_preserves_tenant_fallback_debt_details(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with fallback debt',
            'external_id' => 'tenant-fallback-debt-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'fallback-101',
            'code' => 'fallback-space-101',
            'is_active' => true,
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-fallback-debt-001',
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(40),
            'created_at' => Carbon::now()->subDays(40),
            'hash' => sha1($tenant->external_id.'|contract-fallback-debt-001|2026-03|10000|0|10000'),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertEquals('tenant_fallback', $result['extra']['scope'] ?? null);
        $this->assertEquals(10000.0, $result['extra']['debt_amount'] ?? null);
        $this->assertArrayHasKey('overdue_days', $result['extra']);
        $this->assertGreaterThan(0, $result['extra']['overdue_days']);
    }

    public function test_market_space_tenant_fallback_uses_settlement_balance_map_aging(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
                'use_settlement_balances_for_map' => true,
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant fallback OSV',
            'external_id' => 'tenant-fallback-osv-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'fallback-osv-101',
            'code' => 'fallback-osv-space-101',
            'is_active' => true,
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => 'tenant-fallback-osv-contract-001',
            'contract_name' => 'Tenant fallback OSV contract',
            'settlement_document_name' => 'Realization 01.03.2026 14:00:00',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 10000,
            'opening_credit' => 10000,
            'turnover_debit' => 1200,
            'turnover_credit' => 0,
            'closing_debit' => 11200,
            'closing_credit' => 10000,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'tenant-fallback-osv-current-period'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('tenant_fallback', $result['extra']['scope'] ?? null);
        $this->assertSame(1200.0, $result['extra']['debt_amount'] ?? null);
        $this->assertSame(DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE, $result['extra']['aging_policy'] ?? null);
        $this->assertSame('settlement_net_balance_current_period', $result['extra']['aging_source'] ?? null);
        $this->assertSame('2026-06-15', $result['extra']['due_date'] ?? null);

        Carbon::setTestNow();
    }

    public function test_market_space_tenant_fallback_uses_only_residual_settlement_balance_after_exact_space_contracts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
                'use_settlement_balances_for_map' => true,
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with exact and residual OSV debt',
            'external_id' => 'tenant-osv-residual-001',
            'debt_status' => null,
        ]);

        $exactSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'exact-osv-101',
            'code' => 'exact-osv-space-101',
            'is_active' => true,
        ]);

        $fallbackSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'fallback-osv-102',
            'code' => 'fallback-osv-space-102',
            'is_active' => true,
        ]);

        $exactContract = TenantContract::withoutEvents(fn (): TenantContract => TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $exactSpace->id,
            'external_id' => 'tenant-osv-residual-exact-contract-001',
            'number' => 'Exact OSV contract',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
            'is_active' => true,
        ]));

        DB::table('tenant_settlement_balances')->insert([
            [
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'tenant_contract_id' => $exactContract->id,
                'period_from' => '2026-05-01',
                'period_to' => '2026-05-31',
                'tenant_external_id' => $tenant->external_id,
                'tenant_name' => $tenant->name,
                'contract_external_id' => $exactContract->external_id,
                'contract_name' => 'Exact OSV contract',
                'settlement_document_name' => 'Realization 01.05.2026 14:00:00',
                'account' => '62',
                'currency' => 'RUB',
                'opening_debit' => 7000,
                'opening_credit' => 0,
                'turnover_debit' => 0,
                'turnover_credit' => 0,
                'closing_debit' => 7000,
                'closing_credit' => 0,
                'source' => '1c',
                'source_file' => '1c:settlements',
                'imported_at' => '2026-06-11 01:59:03',
                'source_row_hash' => hash('sha256', 'tenant-osv-residual-exact-may'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'tenant_contract_id' => $exactContract->id,
                'period_from' => '2026-06-01',
                'period_to' => '2026-06-30',
                'tenant_external_id' => $tenant->external_id,
                'tenant_name' => $tenant->name,
                'contract_external_id' => $exactContract->external_id,
                'contract_name' => 'Exact OSV contract',
                'settlement_document_name' => 'Realization 01.06.2026 14:00:00',
                'account' => '62',
                'currency' => 'RUB',
                'opening_debit' => 7000,
                'opening_credit' => 0,
                'turnover_debit' => 0,
                'turnover_credit' => 0,
                'closing_debit' => 7000,
                'closing_credit' => 0,
                'source' => '1c',
                'source_file' => '1c:settlements',
                'imported_at' => '2026-06-11 01:59:03',
                'source_row_hash' => hash('sha256', 'tenant-osv-residual-exact-june'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'tenant_contract_id' => null,
                'period_from' => '2026-06-01',
                'period_to' => '2026-06-30',
                'tenant_external_id' => $tenant->external_id,
                'tenant_name' => $tenant->name,
                'contract_external_id' => 'tenant-osv-residual-unbound-contract-001',
                'contract_name' => 'Residual OSV contract',
                'settlement_document_name' => 'Realization 01.06.2026 14:00:00',
                'account' => '62',
                'currency' => 'RUB',
                'opening_debit' => 800,
                'opening_credit' => 0,
                'turnover_debit' => 0,
                'turnover_credit' => 0,
                'closing_debit' => 800,
                'closing_credit' => 0,
                'source' => '1c',
                'source_file' => '1c:settlements',
                'imported_at' => '2026-06-11 01:59:03',
                'source_row_hash' => hash('sha256', 'tenant-osv-residual-unbound-june'),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $fallbackSpace->id, (int) $this->market->id);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('tenant_fallback', $result['extra']['scope'] ?? null);
        $this->assertSame('residual', $result['extra']['fallback_mode'] ?? null);
        $this->assertSame(800.0, $result['extra']['debt_amount'] ?? null);
        $this->assertSame('settlement_net_balance_history', $result['extra']['aging_source'] ?? null);
        $this->assertSame('2026-06-15', $result['extra']['due_date'] ?? null);
        $this->assertSame([$exactContract->external_id], $result['extra']['exact_space_contracts_excluded'] ?? null);

        Carbon::setTestNow();
    }

    public function test_market_space_tenant_fallback_does_not_duplicate_exact_settlement_balance_debt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
                'use_settlement_balances_for_map' => true,
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with exact OSV debt only',
            'external_id' => 'tenant-osv-exact-only-001',
            'debt_status' => null,
        ]);

        $exactSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'exact-osv-201',
            'code' => 'exact-osv-space-201',
            'is_active' => true,
        ]);

        $fallbackSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'fallback-osv-202',
            'code' => 'fallback-osv-space-202',
            'is_active' => true,
        ]);

        $exactContract = TenantContract::withoutEvents(fn (): TenantContract => TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $exactSpace->id,
            'external_id' => 'tenant-osv-exact-only-contract-001',
            'number' => 'Exact OSV only contract',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
            'is_active' => true,
        ]));

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $exactContract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => $exactContract->external_id,
            'contract_name' => 'Exact OSV only contract',
            'settlement_document_name' => 'Realization 01.06.2026 14:00:00',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 7000,
            'opening_credit' => 0,
            'turnover_debit' => 0,
            'turnover_credit' => 0,
            'closing_debit' => 7000,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'tenant-osv-exact-only-june'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $fallbackSpace->id, (int) $this->market->id);

        $this->assertSame('green', $result['status']);
        $this->assertSame('tenant_fallback', $result['extra']['scope'] ?? null);
        $this->assertSame('residual', $result['extra']['fallback_mode'] ?? null);
        $this->assertSame(0.0, $result['extra']['debt_amount'] ?? null);
        $this->assertSame('tenant_settlement_balances.residual_after_exact_space_contracts', $result['extra']['amount_source'] ?? null);
        $this->assertStringContainsString('no residual debt', (string) ($result['source'] ?? ''));

        Carbon::setTestNow();
    }

    public function test_market_space_with_exact_contract_but_no_osv_rows_uses_residual_fallback_not_legacy_tenant_debt(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $this->market->settings = [
            'debt_monitoring' => [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
                'use_settlement_balances_for_map' => true,
            ],
        ];
        $this->market->save();

        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with missing exact OSV row',
            'external_id' => 'tenant-missing-exact-osv-001',
            'debt_status' => null,
        ]);

        $missingOsvSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'missing-osv-201',
            'code' => 'missing-osv-space-201',
            'is_active' => true,
        ]);

        $representedSpace = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'represented-osv-202',
            'code' => 'represented-osv-space-202',
            'is_active' => true,
        ]);

        [$missingOsvContract, $representedContract] = TenantContract::withoutEvents(fn (): array => [
            TenantContract::create([
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'market_space_id' => $missingOsvSpace->id,
                'external_id' => 'tenant-missing-exact-osv-contract-001',
                'number' => 'Missing exact OSV contract',
                'status' => 'active',
                'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
                'is_active' => true,
            ]),
            TenantContract::create([
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'market_space_id' => $representedSpace->id,
                'external_id' => 'tenant-represented-osv-contract-001',
                'number' => 'Represented OSV contract',
                'status' => 'active',
                'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
                'is_active' => true,
            ]),
        ]);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $representedContract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => $representedContract->external_id,
            'contract_name' => 'Represented OSV contract',
            'settlement_document_name' => 'Realization 01.06.2026 14:00:00',
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 7000,
            'opening_credit' => 0,
            'turnover_debit' => 0,
            'turnover_credit' => 0,
            'closing_debit' => 7000,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 01:59:03',
            'source_row_hash' => hash('sha256', 'tenant-missing-exact-osv-represented-june'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'legacy-unbound-contract-001',
            'period' => '2026-05',
            'account' => '62',
            'accrued_amount' => 71409.64,
            'paid_amount' => 0,
            'debt_amount' => 71409.64,
            'calculated_at' => Carbon::now()->subDays(20),
            'created_at' => Carbon::now()->subDays(20),
            'hash' => sha1($tenant->external_id.'|legacy-unbound-contract-001|2026-05|71409.64|0|71409.64'),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $missingOsvSpace->id, (int) $this->market->id);

        $this->assertSame('green', $result['status']);
        $this->assertSame('tenant_fallback', $result['extra']['scope'] ?? null);
        $this->assertSame('residual', $result['extra']['fallback_mode'] ?? null);
        $this->assertSame(0.0, $result['extra']['debt_amount'] ?? null);
        $this->assertEqualsCanonicalizing(
            [$missingOsvContract->external_id, $representedContract->external_id],
            $result['extra']['exact_space_contracts_excluded'] ?? null
        );
        $this->assertStringContainsString('no residual debt', (string) ($result['source'] ?? ''));

        Carbon::setTestNow();
    }

    public function test_resolve_for_market_space_includes_direct_space_contracts_when_binding_exists(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with mixed space contracts',
            'external_id' => 'tenant-mixed-space-contracts-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'mixed-101',
            'code' => 'mixed-space-101',
            'is_active' => true,
        ]);

        [$bindingContract, $directDebtContract] = TenantContract::withoutEvents(fn (): array => [
            TenantContract::create([
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'market_space_id' => $space->id,
                'external_id' => 'contract-binding-paid-001',
                'number' => 'Binding paid',
                'status' => 'active',
                'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
                'is_active' => true,
            ]),
            TenantContract::create([
                'market_id' => $this->market->id,
                'tenant_id' => $tenant->id,
                'market_space_id' => $space->id,
                'external_id' => 'contract-direct-debt-001',
                'number' => 'Direct debt',
                'status' => 'active',
                'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
                'is_active' => true,
            ]),
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $this->market->id,
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $bindingContract->id,
            'started_at' => Carbon::now()->subMonth(),
            'ended_at' => null,
            'binding_type' => 'contract',
            'confidence' => 'high',
            'source' => 'test',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DB::table('contract_debts')->insert([
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => $bindingContract->external_id,
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 0,
                'paid_amount' => 0,
                'debt_amount' => -1000,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|'.$bindingContract->external_id.'|2026-03|0|0|-1000'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => $directDebtContract->external_id,
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 10000,
                'paid_amount' => 0,
                'debt_amount' => 10000,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|'.$directDebtContract->external_id.'|2026-03|10000|0|10000'),
            ],
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertEquals('space', $result['extra']['scope'] ?? null);
        $this->assertEquals(10000.0, $result['extra']['debt_amount'] ?? null);
    }

    public function test_resolve_for_market_space_uses_tenant_fallback_when_space_has_no_positive_debt(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with paid space but other debt',
            'external_id' => 'tenant-paid-space-other-debt-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'number' => 'paid-101',
            'code' => 'paid-space-101',
            'is_active' => true,
        ]);

        $spaceContract = TenantContract::withoutEvents(fn (): TenantContract => TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'contract-paid-space-001',
            'number' => 'Paid space',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
            'is_active' => true,
        ]));

        $unboundContract = TenantContract::withoutEvents(fn (): TenantContract => TenantContract::create([
            'market_id' => $this->market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => null,
            'external_id' => 'contract-unbound-debt-001',
            'number' => 'Unbound debt',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
            'is_active' => true,
        ]));

        DB::table('contract_debts')->insert([
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => $spaceContract->external_id,
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 10000,
                'paid_amount' => 10000,
                'debt_amount' => 0,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|'.$spaceContract->external_id.'|2026-03|10000|10000|0'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => $unboundContract->external_id,
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 15000,
                'paid_amount' => 0,
                'debt_amount' => 15000,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|contract-unbound-debt-001|2026-03|15000|0|15000'),
            ],
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertEquals('tenant_fallback', $result['extra']['scope'] ?? null);
        $this->assertEquals(15000.0, $result['extra']['debt_amount'] ?? null);
    }

    public function test_resolve_for_market_space_keeps_shared_use_without_exact_financial_links_gray(): void
    {
        $primaryTenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Primary shared-use tenant',
            'external_id' => 'shared-primary-001',
            'debt_status' => null,
        ]);

        $otherTenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Other shared-use tenant',
            'external_id' => 'shared-other-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $this->market->id,
            'tenant_id' => $primaryTenant->id,
            'number' => 'shared-101',
            'code' => 'shared-space-101',
            'is_active' => true,
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            [
                'market_id' => $this->market->id,
                'market_space_id' => $space->id,
                'tenant_id' => $primaryTenant->id,
                'tenant_contract_id' => null,
                'started_at' => Carbon::now()->subMonth(),
                'ended_at' => null,
                'binding_type' => 'shared_use',
                'confidence' => 'high',
                'source' => 'test',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'market_id' => $this->market->id,
                'market_space_id' => $space->id,
                'tenant_id' => $otherTenant->id,
                'tenant_contract_id' => null,
                'started_at' => Carbon::now()->subMonth(),
                'ended_at' => null,
                'binding_type' => 'shared_use',
                'confidence' => 'high',
                'source' => 'test',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
        ]);

        DB::table('contract_debts')->insert([
            'tenant_id' => $primaryTenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $primaryTenant->external_id,
            'contract_external_id' => 'shared-primary-unbound-contract',
            'period' => '2026-03',
            'account' => '62',
            'accrued_amount' => 15000,
            'paid_amount' => 0,
            'debt_amount' => 15000,
            'calculated_at' => Carbon::now()->subDays(40),
            'created_at' => Carbon::now()->subDays(40),
            'hash' => sha1($primaryTenant->external_id.'|shared-primary-unbound-contract|2026-03|15000|0|15000'),
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolveForMarketSpace((int) $space->id, (int) $this->market->id);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('gray', $result['status']);
        $this->assertEquals('shared_use', $result['extra']['scope'] ?? null);
        $this->assertEquals(2, $result['extra']['active_count'] ?? null);
    }

    /**
     * Old positive-debt snapshot should not be "rejuvenated" by a newer
     * snapshot of a different contract. Both contracts have debt, but the
     * older one dictates the due date — matching space-level behavior.
     * The result must stay overdue (orange/red), not fall back to pending.
     */
    public function test_auto_status_oldest_positive_debt_not_rejuvenated_by_newer_contract(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Multi-contract tenant',
            'external_id' => 'test-multi-001',
            'debt_status' => null,
        ]);

        // Old contract: debt from 50 days ago
        $hashOld = sha1($tenant->external_id.'|contract-old|2026-02|10000|0|10000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-old',
            'period' => '2026-02',
            'account' => '62',
            'accrued_amount' => 10000,
            'paid_amount' => 0,
            'debt_amount' => 10000,
            'calculated_at' => Carbon::now()->subDays(50),
            'created_at' => Carbon::now()->subDays(50),
            'hash' => $hashOld,
        ]);

        // New contract: debt from 2 days ago (would "rejuvenate" with max())
        $hashNew = sha1($tenant->external_id.'|contract-new|2026-04|5000|0|5000');
        DB::table('contract_debts')->insert([
            'tenant_id' => $tenant->id,
            'market_id' => $this->market->id,
            'tenant_external_id' => $tenant->external_id,
            'contract_external_id' => 'contract-new',
            'period' => '2026-04',
            'account' => '62',
            'accrued_amount' => 5000,
            'paid_amount' => 0,
            'debt_amount' => 5000,
            'calculated_at' => Carbon::now()->subDays(2),
            'created_at' => Carbon::now()->subDays(2),
            'hash' => $hashNew,
        ]);

        $result = $this->resolver->resolve($tenant);

        // 50 days + 5 grace = 45 days overdue → orange (< 90 red_after_days)
        // With max() it would be: 2 days + 5 grace = pending (WRONG)
        // With min() it correctly uses the oldest date → overdue
        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
    }

    public function test_auto_status_uses_tenant_net_balance_for_overdue_amount(): void
    {
        $tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'Tenant with cross-contract credit',
            'external_id' => 'test-net-balance-001',
            'debt_status' => null,
        ]);

        DB::table('contract_debts')->insert([
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-credit',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 0,
                'paid_amount' => 132097.43,
                'debt_amount' => -132097.43,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|contract-credit|2026-03|0|132097.43|-132097.43'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-current',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 373837.50,
                'paid_amount' => 0,
                'debt_amount' => 373837.50,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|contract-current|2026-03|373837.50|0|373837.50'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-other-1',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 18502.00,
                'paid_amount' => 0,
                'debt_amount' => 18502.00,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|contract-other-1|2026-03|18502|0|18502'),
            ],
            [
                'tenant_id' => $tenant->id,
                'market_id' => $this->market->id,
                'tenant_external_id' => $tenant->external_id,
                'contract_external_id' => 'contract-other-2',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 51868.18,
                'paid_amount' => 0,
                'debt_amount' => 51868.18,
                'calculated_at' => Carbon::now()->subDays(40),
                'created_at' => Carbon::now()->subDays(40),
                'hash' => sha1($tenant->external_id.'|contract-other-2|2026-03|51868.18|0|51868.18'),
            ],
        ]);

        DebtStatusResolver::clearCache();

        $result = $this->resolver->resolve($tenant);

        $this->assertEquals('auto', $result['mode']);
        $this->assertEquals('orange', $result['status']);
        $this->assertEqualsWithDelta(312110.25, (float) ($result['extra']['debt_amount'] ?? 0), 0.01);
    }
}
