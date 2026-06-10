<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OneCReconciliation;
use App\Filament\Widgets\OneCAccrualPaymentReconciliationDetailWidget;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OneCAccrualPaymentReconciliationDetailWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_widget_shows_1c_documents_for_selected_period(): void
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
        Role::findOrCreate('market-operator', 'web');
        $user->assignRole('market-operator');

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
            'source_file' => '1c:accruals',
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

        $this->assertSame('01.05.2026 - 31.05.2026', $data['periodLabel']);
        $this->assertCount(2, $data['rows']);

        $this->assertSame(1000.00, $data['summary']['accrued']);
        $this->assertSame(400.00, $data['summary']['paid']);
        $this->assertSame(2, $data['summary']['rows_count']);
        $this->assertSame(1, $data['summary']['accrual_count']);
        $this->assertSame(1, $data['summary']['payment_count']);

        $this->get(OneCReconciliation::getUrl([
            'from' => '2026-05-01',
            'to' => '2026-05-31',
        ]))
            ->assertOk()
            ->assertSee('Журнал документов 1С')
            ->assertSee('Tenant short')
            ->assertSee('№ D-1')
            ->assertSee('P-1');
    }
}
