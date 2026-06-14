<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\TenantPayment;
use App\Models\TenantSettlementBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CabinetFinanceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_see_finance_summary_accruals_and_payments(): void
    {
        [$market, $tenant, $space, $contract] = $this->createTenantContext();
        $merchant = $this->createCabinetUser((int) $market->id, (int) $tenant->id, 'merchant');

        TenantSettlementBalance::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-1c',
            'tenant_name' => (string) $tenant->name,
            'contract_external_id' => 'contract-1c',
            'contract_name' => 'Договор аренды П1',
            'settlement_document_name' => 'Расчеты по договору аренды П1',
            'organization_name' => 'Эко Ярмарка',
            'account' => '62',
            'turnover_debit' => 10000,
            'turnover_credit' => 7000,
            'closing_debit' => 3000,
            'closing_credit' => 0,
            'imported_at' => '2026-07-01 08:30:00',
            'source_row_hash' => hash('sha256', 'settlement-row'),
        ]);

        TenantAccrual::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'market_space_id' => (int) $space->id,
            'period' => '2026-06-01',
            'source_place_code' => 'П1',
            'source_place_name' => 'Павильон П1',
            'activity_type' => 'Аренда места',
            'total_with_vat' => 10000,
            'status' => 'imported',
            'source' => '1c',
            'source_file' => '1c:accruals',
            'source_row_hash' => hash('sha256', 'accrual-row'),
        ]);

        TenantPayment::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'tenant_external_id' => 'tenant-1c',
            'contract_external_id' => 'contract-1c',
            'payment_external_id' => 'payment-1c',
            'document_number' => '42',
            'payment_date' => '2026-06-12',
            'period' => '2026-06-01',
            'organization_name' => 'Эко Ярмарка',
            'account' => '51',
            'amount' => 7000,
            'purpose' => 'Оплата аренды за июнь',
            'source_row_hash' => hash('sha256', 'payment-row'),
        ]);

        $this->actingAs($merchant, 'web');

        $this->get(route('cabinet.payments', ['month' => '2026-06']))
            ->assertOk()
            ->assertSee('Финансы 1С')
            ->assertSee('Есть задолженность')
            ->assertSee('3 000,00 ₽')
            ->assertSee('Начисления')
            ->assertSee('10 000,00 ₽')
            ->assertSee('Оплаты')
            ->assertSee('7 000,00 ₽')
            ->assertSee('Павильон П1')
            ->assertSee('Оплата аренды за июнь');
    }

    public function test_cabinet_hides_closed_settlement_contracts_without_period_movement(): void
    {
        [$market, $tenant, $space, $contract] = $this->createTenantContext();
        $merchant = $this->createCabinetUser((int) $market->id, (int) $tenant->id, 'merchant');

        TenantSettlementBalance::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-1c',
            'tenant_name' => (string) $tenant->name,
            'contract_external_id' => 'current-contract-1c',
            'contract_name' => 'Договор аренды П1',
            'settlement_document_name' => 'Реализация за июнь',
            'organization_name' => 'Эко Ярмарка',
            'account' => '62',
            'turnover_debit' => 10000,
            'turnover_credit' => 0,
            'closing_debit' => 10000,
            'closing_credit' => 0,
            'imported_at' => '2026-07-01 08:30:00',
            'source_row_hash' => hash('sha256', 'current-visible-settlement-row'),
        ]);

        TenantSettlementBalance::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => null,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-1c',
            'tenant_name' => (string) $tenant->name,
            'contract_external_id' => 'old-contract-1c',
            'contract_name' => 'П/Э старый',
            'settlement_document_name' => 'Старый закрытый документ',
            'organization_name' => 'Эко Ярмарка',
            'account' => '62',
            'turnover_debit' => 0,
            'turnover_credit' => 0,
            'closing_debit' => 0,
            'closing_credit' => 0,
            'imported_at' => '2026-07-01 08:30:00',
            'source_row_hash' => hash('sha256', 'old-hidden-settlement-row'),
        ]);

        $this->actingAs($merchant, 'web');

        $this->get(route('cabinet.payments', ['month' => '2026-06']))
            ->assertOk()
            ->assertSee('П1/2026')
            ->assertSee('10 000,00 ₽')
            ->assertSee('Скрыто строк ОСВ без движения: 1.')
            ->assertDontSee('П/Э старый')
            ->assertDontSee('Старый закрытый документ');
    }

    private function createTenantContext(): array
    {
        $market = Market::query()->create([
            'name' => 'Тестовая ярмарка',
            'slug' => 'test-fair',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
            'slug' => 'tenant-test',
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'П1',
            'display_name' => 'Павильон П1',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'number' => 'П1/2026',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'monthly_rent' => 10000,
            'currency' => 'RUB',
            'is_active' => true,
        ]);

        return [$market, $tenant, $space, $contract];
    }

    private function createCabinetUser(int $marketId, int $tenantId, string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
