<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Widgets\OneCAccrualPaymentReconciliationDetailWidget;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OneCAccrualPaymentReconciliationDetailWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_groups_1c_accruals_and_payments_by_tenant_contract(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Test tenant',
            'short_name' => 'Tenant short',
            'external_id' => 'tenant-detail-widget',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'external_id' => 'contract-detail-widget',
            'number' => 'D-1',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'reconciliation-detail-widget@example.test',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'contract_external_id' => 'contract-detail-widget',
            'period' => '2026-05-01',
            'currency' => 'RUB',
            'total_with_vat' => 1000.00,
            'status' => 'imported',
            'source' => '1c',
            'source_row_hash' => hash('sha256', 'reconciliation-detail-accrual'),
            'imported_at' => '2026-05-31 12:00:00',
            'created_at' => '2026-05-31 12:00:00',
            'updated_at' => '2026-05-31 12:00:00',
        ]);

        DB::table('tenant_payments')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'tenant_external_id' => 'tenant-detail-widget',
            'contract_external_id' => 'contract-detail-widget',
            'payment_external_id' => 'payment-detail-widget',
            'document_number' => 'P-1',
            'payment_date' => '2026-05-20',
            'period' => '2026-05-01',
            'amount' => 400.00,
            'currency' => 'RUB',
            'source' => '1c',
            'source_file' => '1c:payments',
            'source_row_hash' => hash('sha256', 'reconciliation-detail-payment'),
            'imported_at' => '2026-05-31 13:00:00',
            'created_at' => '2026-05-31 13:00:00',
            'updated_at' => '2026-05-31 13:00:00',
        ]);

        $this->actingAs($user)->withSession([
            'dashboard_month' => '2026-05',
            'dashboard_month_explicit' => true,
        ]);

        $this->assertTrue(OneCAccrualPaymentReconciliationDetailWidget::canView());

        $livewire = Livewire::test(OneCAccrualPaymentReconciliationDetailWidget::class);

        $method = new \ReflectionMethod($livewire->instance(), 'getViewData');
        $method->setAccessible(true);
        $data = $method->invoke($livewire->instance());

        $this->assertSame('05.2026', $data['monthLabel']);
        $this->assertCount(1, $data['rows']);

        $row = $data['rows'][0];

        $this->assertSame((int) $tenant->id, $row['tenant_id']);
        $this->assertSame((int) $contract->id, $row['contract_id']);
        $this->assertSame(1000.00, $row['accrued']);
        $this->assertSame(400.00, $row['paid']);
        $this->assertSame(600.00, $row['delta']);
        $this->assertSame('debt', $row['status']);
        $this->assertSame(1, $row['accrual_rows']);
        $this->assertSame(1, $row['payment_rows']);
        $this->assertSame(1000.00, $data['summary']['accrued']);
        $this->assertSame(400.00, $data['summary']['paid']);
        $this->assertSame(600.00, $data['summary']['delta']);
        $this->assertSame(1, $data['summary']['debt_count']);
    }
}
