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
}
