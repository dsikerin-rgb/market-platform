<?php

declare(strict_types=1);

namespace Tests\Unit\Filament\Widgets;

use App\Filament\Widgets\OneCAccrualPaymentReconciliationWidget;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OneCAccrualPaymentReconciliationWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_chart_prefers_settlement_turnovers_over_document_registers(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 3, 15, 12, 0, 0, 'UTC'));

        $market = Market::query()->create([
            'name' => 'OSV Widget Market',
            'timezone' => 'UTC',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $user = User::factory()->create(['market_id' => (int) $market->id]);
        auth()->login($user);

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period_from' => '2026-03-01',
            'period_to' => '2026-03-31',
            'tenant_external_id' => 'TEN-1',
            'tenant_name' => 'Tenant',
            'contract_external_id' => 'CON-1',
            'contract_name' => 'Contract',
            'organization_external_id' => 'ORG-1',
            'organization_name' => 'Org',
            'account' => '62',
            'currency' => 'RUB',
            'turnover_debit' => 1000,
            'turnover_credit' => 1500,
            'source_row_hash' => hash('sha256', 'osv-widget-test'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-03-01',
            'currency' => 'RUB',
            'total_with_vat' => 9000,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => '1c:accruals',
            'source_row_hash' => hash('sha256', 'accrual-widget-test'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_payments')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_external_id' => 'TEN-1',
            'contract_external_id' => 'CON-1',
            'payment_date' => '2026-03-10',
            'period' => '2026-03-01',
            'amount' => 100,
            'currency' => 'RUB',
            'source' => '1c',
            'source_file' => '1c:payments',
            'source_row_hash' => hash('sha256', 'payment-widget-test'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = (new class extends OneCAccrualPaymentReconciliationWidget
        {
            public ?array $pageFilters = null;

            public ?array $filters = null;

            public function exposedGetData(): array
            {
                return $this->getData();
            }
        })->exposedGetData();

        self::assertSame('03.2026', $data['labels'][12]);
        self::assertCount(2, $data['datasets']);
        self::assertSame(1000.0, $data['datasets'][0]['data'][12]);
        self::assertSame(1500.0, $data['datasets'][1]['data'][12]);
        self::assertSame(-500.0, $data['deltaBars'][12]['value']);
    }
}
