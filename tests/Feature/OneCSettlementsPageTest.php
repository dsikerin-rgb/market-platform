<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OneCSettlements;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OneCSettlementsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_settlements_page_filters_rows_by_tenant_id(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenantA = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant B',
            'is_active' => true,
        ]);

        $contractA = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantA->id,
            'external_id' => 'guid-contract-a',
            'number' => 'Contract A',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $contractB = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantB->id,
            'external_id' => 'guid-contract-b',
            'number' => 'Contract B',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'is_active' => true,
        ]);

        $this->insertSettlementBalance($market, $tenantA, $contractA, 'Contract A', 'Org A', 1200, 700, 500, 'row-a');
        $this->insertSettlementBalance($market, $tenantB, $contractB, 'Contract B', 'Org B', 9900, 100, 9800, 'row-b');

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-settlements@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        $response = $this->get(OneCSettlements::getUrl() . '?' . http_build_query([
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'account' => '62',
            'tenantId' => (int) $tenantA->id,
            'perPage' => 'all',
        ]));

        $response
            ->assertOk()
            ->assertSeeText('Расчёты 1С по арендатору')
            ->assertSeeText('Арендатор: Tenant A')
            ->assertSeeText('Договоры арендатора')
            ->assertSeeText('Начислено')
            ->assertSeeText('Оплачено')
            ->assertSeeText('Итог')
            ->assertSeeText('Contract A')
            ->assertSeeText('Org A')
            ->assertDontSeeText('Tenant B')
            ->assertDontSeeText('Contract B')
            ->assertDontSeeText('Контроль по организациям')
            ->assertDontSeeText('Непривязанные строки')
            ->assertDontSee('guid-contract-a', false)
            ->assertDontSee('guid-contract-b', false);

        $emptyResponse = $this->get(OneCSettlements::getUrl() . '?' . http_build_query([
            'from' => '2026-06-01',
            'to' => '2026-06-30',
            'account' => '62',
            'tenantId' => (int) $tenantA->id,
            'search' => 'not-found',
        ]));

        $emptyResponse
            ->assertOk()
            ->assertSeeText('По этому арендатору в выбранном периоде строк ОСВ не найдено.');
    }

    private function insertSettlementBalance(
        Market $market,
        Tenant $tenant,
        TenantContract $contract,
        string $contractName,
        string $organizationName,
        float $turnoverDebit,
        float $turnoverCredit,
        float $closingDebit,
        string $hashSeed,
    ): void {
        DB::table('tenant_settlement_balances')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => 'tenant-' . $tenant->id,
            'tenant_name' => (string) $tenant->name,
            'contract_external_id' => (string) $contract->external_id,
            'contract_name' => $contractName,
            'organization_external_id' => 'org-' . $tenant->id,
            'organization_name' => $organizationName,
            'account' => '62',
            'currency' => 'RUB',
            'opening_debit' => 0,
            'opening_credit' => 0,
            'turnover_debit' => $turnoverDebit,
            'turnover_credit' => $turnoverCredit,
            'closing_debit' => $closingDebit,
            'closing_credit' => 0,
            'source' => '1c',
            'source_file' => '1c:settlements',
            'imported_at' => '2026-06-11 08:00:00',
            'source_row_hash' => hash('sha256', $hashSeed),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
