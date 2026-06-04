<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ContractDebt;
use App\Models\Market;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ContractDebtCurrentStateQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_state_keeps_older_unchanged_rows_and_latest_versions_only(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'slug' => 'test-market',
        ]);

        $olderPositiveTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Older positive tenant',
            'external_id' => 'tenant-old-positive',
            'is_active' => true,
        ]);

        $updatedToZeroTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Updated to zero tenant',
            'external_id' => 'tenant-updated-zero',
            'is_active' => true,
        ]);

        $latestSnapshotTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Latest snapshot tenant',
            'external_id' => 'tenant-latest',
            'is_active' => true,
        ]);

        $dayOne = Carbon::create(2026, 3, 17, 6, 53, 28);
        $dayTwo = Carbon::create(2026, 3, 19, 12, 35, 48);

        DB::table('contract_debts')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $olderPositiveTenant->id,
                'tenant_external_id' => (string) $olderPositiveTenant->external_id,
                'contract_external_id' => 'contract-old-positive',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => $dayOne,
                'created_at' => $dayOne,
                'hash' => sha1('contract-old-positive-v1'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $updatedToZeroTenant->id,
                'tenant_external_id' => (string) $updatedToZeroTenant->external_id,
                'contract_external_id' => 'contract-updated-zero',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 500,
                'paid_amount' => 0,
                'debt_amount' => 500,
                'calculated_at' => $dayOne,
                'created_at' => $dayOne,
                'hash' => sha1('contract-updated-zero-v1'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $updatedToZeroTenant->id,
                'tenant_external_id' => (string) $updatedToZeroTenant->external_id,
                'contract_external_id' => 'contract-updated-zero',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 500,
                'paid_amount' => 500,
                'debt_amount' => 0,
                'calculated_at' => $dayTwo,
                'created_at' => $dayTwo,
                'hash' => sha1('contract-updated-zero-v2'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $latestSnapshotTenant->id,
                'tenant_external_id' => (string) $latestSnapshotTenant->external_id,
                'contract_external_id' => 'contract-latest',
                'period' => '2026-03',
                'account' => '62',
                'accrued_amount' => 700,
                'paid_amount' => 700,
                'debt_amount' => 0,
                'calculated_at' => $dayTwo,
                'created_at' => $dayTwo,
                'hash' => sha1('contract-latest-v1'),
            ],
        ]);

        $positiveTenantIds = DB::query()
            ->fromSub(ContractDebt::currentStateQuery((int) $market->id), 'cd')
            ->where('cd.debt_amount', '>', 0)
            ->pluck('cd.tenant_id')
            ->all();

        $this->assertSame([(int) $olderPositiveTenant->id], array_values($positiveTenantIds));

        $this->assertSame(1, DB::query()
            ->fromSub(ContractDebt::currentStateQuery((int) $market->id), 'cd')
            ->where('cd.debt_amount', '>', 0)
            ->distinct()
            ->count('cd.tenant_id'));
    }

    public function test_latest_contract_state_does_not_sum_accumulated_balances_across_periods(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'slug' => 'test-market',
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Accumulated debt tenant',
            'external_id' => 'tenant-accumulated',
            'is_active' => true,
        ]);

        $snapshot = Carbon::create(2026, 6, 1, 8, 50, 0);

        DB::table('contract_debts')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-accumulated',
                'period' => '2026-03',
                'account' => '76.07',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => $snapshot->copy()->subMonths(3),
                'created_at' => $snapshot->copy()->subMonths(3),
                'hash' => sha1('contract-accumulated-2026-03'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-accumulated',
                'period' => '2026-04',
                'account' => '76.07',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => $snapshot->copy()->subMonths(2),
                'created_at' => $snapshot->copy()->subMonths(2),
                'hash' => sha1('contract-accumulated-2026-04'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-accumulated',
                'period' => '2026-05',
                'account' => '76.07',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => $snapshot->copy()->subMonth(),
                'created_at' => $snapshot->copy()->subMonth(),
                'hash' => sha1('contract-accumulated-2026-05'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-accumulated',
                'period' => '2026-06',
                'account' => '76.07',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1300,
                'calculated_at' => $snapshot,
                'created_at' => $snapshot,
                'hash' => sha1('contract-accumulated-2026-06'),
            ],
        ]);

        $periodStateDebt = (float) DB::query()
            ->fromSub(ContractDebt::currentStateQuery((int) $market->id), 'cd')
            ->where('cd.tenant_id', (int) $tenant->id)
            ->sum('cd.debt_amount');

        $latestContractDebt = (float) DB::query()
            ->fromSub(ContractDebt::latestContractStateQuery((int) $market->id), 'cd')
            ->where('cd.tenant_id', (int) $tenant->id)
            ->sum('cd.debt_amount');

        $this->assertSame(4300.0, $periodStateDebt);
        $this->assertSame(1300.0, $latestContractDebt);
    }

    public function test_current_state_uses_allowed_calculation_accounts_and_62_subaccounts(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
            'slug' => 'test-market',
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Account filtered tenant',
            'external_id' => 'tenant-account-filtered',
            'is_active' => true,
        ]);

        $snapshot = Carbon::create(2026, 6, 4, 13, 56, 51);

        DB::table('contract_debts')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-account-filtered-62',
                'period' => '2026-06',
                'account' => '62',
                'accrued_amount' => 1000,
                'paid_amount' => 0,
                'debt_amount' => 1000,
                'calculated_at' => $snapshot,
                'created_at' => $snapshot,
                'hash' => sha1('contract-account-filtered-62'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-account-filtered-7607',
                'period' => '2026-06',
                'account' => '76.07',
                'accrued_amount' => 500,
                'paid_amount' => 0,
                'debt_amount' => 500,
                'calculated_at' => $snapshot,
                'created_at' => $snapshot,
                'hash' => sha1('contract-account-filtered-7607'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-account-filtered-6201',
                'period' => '2026-06',
                'account' => '62.01',
                'accrued_amount' => 9000,
                'paid_amount' => 0,
                'debt_amount' => 9000,
                'calculated_at' => $snapshot,
                'created_at' => $snapshot,
                'hash' => sha1('contract-account-filtered-6201'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-account-filtered-6202',
                'period' => '2026-06',
                'account' => '62.02',
                'accrued_amount' => 7000,
                'paid_amount' => 0,
                'debt_amount' => 7000,
                'calculated_at' => $snapshot,
                'created_at' => $snapshot,
                'hash' => sha1('contract-account-filtered-6202'),
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_external_id' => (string) $tenant->external_id,
                'contract_external_id' => 'contract-account-filtered-7606',
                'period' => '2026-06',
                'account' => '76.06',
                'accrued_amount' => 0,
                'paid_amount' => 0,
                'debt_amount' => 3000,
                'calculated_at' => $snapshot,
                'created_at' => $snapshot,
                'hash' => sha1('contract-account-filtered-7606'),
            ],
        ]);

        $currentStateDebt = (float) DB::query()
            ->fromSub(ContractDebt::currentStateQuery((int) $market->id), 'cd')
            ->where('cd.tenant_id', (int) $tenant->id)
            ->sum('cd.debt_amount');

        $latestContractDebt = (float) DB::query()
            ->fromSub(ContractDebt::latestContractStateQuery((int) $market->id), 'cd')
            ->where('cd.tenant_id', (int) $tenant->id)
            ->sum('cd.debt_amount');

        $securityDepositAmount = (float) DB::query()
            ->fromSub(ContractDebt::securityDepositStateQuery((int) $market->id), 'cd')
            ->where('cd.tenant_id', (int) $tenant->id)
            ->sum('cd.debt_amount');

        $this->assertSame(17500.0, $currentStateDebt);
        $this->assertSame(17500.0, $latestContractDebt);
        $this->assertSame(3000.0, $securityDepositAmount);
        $this->assertSame(3000.0, ContractDebt::securityDepositAmountForTenant((int) $market->id, (int) $tenant->id));
    }
}
