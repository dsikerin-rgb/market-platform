<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Debt;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Services\Debt\DebtDecisionPolicy;
use App\Services\Debt\DebtDecisionPreviewReport;
use App\Services\Debt\DebtStatusResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DebtDecisionPreviewReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_uses_residual_tenant_fallback_candidate_after_exact_space_contracts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $market = Market::create([
            'name' => 'Preview market',
            'slug' => 'preview-market',
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'yellow_after_days' => 1,
                    'red_after_days' => 30,
                    'minimum_debt_amount' => 500,
                    'use_settlement_balances_for_map' => true,
                ],
            ],
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Preview tenant',
            'external_id' => 'preview-tenant-001',
            'debt_status' => null,
        ]);

        $exactSpace = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'exact-preview-101',
            'code' => 'exact-preview-space-101',
            'is_active' => true,
        ]);

        $fallbackSpace = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'fallback-preview-102',
            'code' => 'fallback-preview-space-102',
            'is_active' => true,
        ]);

        $exactContract = TenantContract::withoutEvents(fn (): TenantContract => TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $exactSpace->id,
            'external_id' => 'preview-exact-contract-001',
            'number' => 'Preview exact contract',
            'status' => 'active',
            'starts_at' => Carbon::now()->subMonths(3)->toDateString(),
            'is_active' => true,
        ]));

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $exactContract->id,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $tenant->external_id,
            'tenant_name' => $tenant->name,
            'contract_external_id' => $exactContract->external_id,
            'contract_name' => 'Preview exact contract',
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
            'source_row_hash' => hash('sha256', 'preview-residual-exact-only-june'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $report = app(DebtDecisionPreviewReport::class)->build(
            marketId: (int) $market->id,
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        );

        $fallbackRow = collect($report['rows'])
            ->firstWhere('space_id', (int) $fallbackSpace->id);

        $this->assertIsArray($fallbackRow);
        $this->assertFalse($fallbackRow['mismatch']);
        $this->assertSame('green', $fallbackRow['current_map']['status']);
        $this->assertSame('green', $fallbackRow['osv_candidate']['status']);
        $this->assertSame('tenant_fallback', $fallbackRow['osv_candidate']['scope']);
        $this->assertSame('residual', $fallbackRow['osv_candidate']['fallback_mode']);
        $this->assertSame(0.0, $fallbackRow['osv_candidate']['debt_amount']);
        $this->assertSame([$exactContract->external_id], $fallbackRow['osv_candidate']['exact_space_contracts_excluded']);

        Carbon::setTestNow();
    }

    public function test_preview_keeps_shared_use_spaces_neutral_instead_of_suggesting_tenant_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-11 12:00:00'));

        $market = Market::create([
            'name' => 'Preview shared-use market',
            'slug' => 'preview-shared-use-market',
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'yellow_after_days' => 1,
                    'red_after_days' => 30,
                    'minimum_debt_amount' => 500,
                    'use_settlement_balances_for_map' => true,
                ],
            ],
        ]);

        $primaryTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Preview shared primary tenant',
            'external_id' => 'preview-shared-primary-001',
            'debt_status' => null,
        ]);

        $otherTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Preview shared other tenant',
            'external_id' => 'preview-shared-other-001',
            'debt_status' => null,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $primaryTenant->id,
            'number' => 'shared-preview-101',
            'code' => 'shared-preview-space-101',
            'is_active' => true,
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            [
                'market_id' => $market->id,
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
                'market_id' => $market->id,
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

        DB::table('tenant_settlement_balances')->insert([
            'market_id' => $market->id,
            'tenant_id' => $primaryTenant->id,
            'tenant_contract_id' => null,
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_external_id' => $primaryTenant->external_id,
            'tenant_name' => $primaryTenant->name,
            'contract_external_id' => 'preview-shared-unbound-contract-001',
            'contract_name' => 'Preview shared unbound contract',
            'settlement_document_name' => 'Realization 01.06.2026 14:00:00',
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
            'source_row_hash' => hash('sha256', 'preview-shared-use-tenant-fallback-june'),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        DebtStatusResolver::clearCache();

        $report = app(DebtDecisionPreviewReport::class)->build(
            marketId: (int) $market->id,
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
            onlyMismatches: true,
        );

        $this->assertSame(0, $report['summary']['mismatches']);
        $this->assertSame([], $report['rows']);

        $fullReport = app(DebtDecisionPreviewReport::class)->build(
            marketId: (int) $market->id,
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        );

        $row = collect($fullReport['rows'])->firstWhere('space_id', (int) $space->id);

        $this->assertIsArray($row);
        $this->assertFalse($row['mismatch']);
        $this->assertSame('gray', $row['current_map']['status']);
        $this->assertSame('shared_use', $row['current_map']['scope']);
        $this->assertSame('gray', $row['osv_candidate']['status']);
        $this->assertSame('shared_use', $row['osv_candidate']['scope']);

        Carbon::setTestNow();
    }
}
