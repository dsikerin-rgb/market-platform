<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Debt;

use App\Models\Market;
use App\Models\Tenant;
use App\Services\Debt\DebtDecisionPolicy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtDecisionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DebtDecisionPolicy $policy;

    private Market $market;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = app(DebtDecisionPolicy::class);
        $this->market = Market::create([
            'name' => 'Test market',
            'slug' => 'test-market',
            'settings' => [
                'debt_monitoring' => [
                    'grace_days' => 5,
                    'yellow_after_days' => 1,
                    'red_after_days' => 30,
                    'minimum_debt_amount' => 500,
                ],
            ],
        ]);

        $this->tenant = Tenant::create([
            'market_id' => $this->market->id,
            'name' => 'History tenant',
            'external_id' => 'history-tenant-1',
            'debt_status' => null,
        ]);
    }

    public function test_settlement_document_policy_uses_document_date_for_aging(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 01.05.2026 00:00:00',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT,
        );

        $this->assertSame('red', $candidate['status']);
        $this->assertSame('2026-05-06', $candidate['due_date']);
        $this->assertSame('settlement_document_name', $candidate['aging_source']);
        $this->assertSame(10000.0, $candidate['debt_amount']);
        $this->assertSame('tenant_settlement_balances.closing_debit_minus_closing_credit', $candidate['amount_source']);

        Carbon::setTestNow();
    }

    public function test_period_start_policy_uses_period_from_for_aging(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 01.05.2026 00:00:00',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_PERIOD_START,
        );

        $this->assertSame('orange', $candidate['status']);
        $this->assertSame('2026-06-06', $candidate['due_date']);
        $this->assertSame('period_from', $candidate['aging_source']);

        Carbon::setTestNow();
    }

    public function test_invoice_day_policy_uses_tenth_day_of_settlement_month_plus_grace(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 01.05.2026 00:00:00',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_INVOICE_DAY,
        );

        $this->assertSame('pending', $candidate['status']);
        $this->assertSame('2026-06-15', $candidate['due_date']);
        $this->assertSame('invoice_day', $candidate['aging_source']);

        Carbon::setTestNow();
    }

    public function test_settlement_document_invoice_day_policy_uses_document_month_invoice_day(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $oldDebt = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 01.04.2026 00:00:00',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY,
        );

        $currentDebt = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 01.06.2026 00:00:00',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY,
        );

        $lateMonthDocument = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 31.05.2026 00:00:00',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY,
        );

        $this->assertSame('red', $oldDebt['status']);
        $this->assertSame('2026-04-15', $oldDebt['due_date']);
        $this->assertSame('settlement_document_invoice_day', $oldDebt['aging_source']);

        $this->assertSame('pending', $currentDebt['status']);
        $this->assertSame('2026-06-15', $currentDebt['due_date']);

        $this->assertSame('pending', $lateMonthDocument['status']);
        $this->assertSame('2026-06-15', $lateMonthDocument['due_date']);

        Carbon::setTestNow();
    }

    public function test_settlement_net_balance_policy_uses_current_period_when_opening_net_debt_is_closed(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'account' => '62',
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'settlement_document_name' => 'Realization 31.03.2026 14:00:00',
                    'opening_debit' => 38896,
                    'opening_credit' => 38896,
                    'turnover_debit' => 16887.15,
                    'turnover_credit' => 0,
                    'closing_debit' => 55783.15,
                    'closing_credit' => 38896,
                    'debt_amount' => 16887.15,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        );

        $this->assertSame('pending', $candidate['status']);
        $this->assertSame('2026-06-15', $candidate['due_date']);
        $this->assertSame('settlement_net_balance_current_period', $candidate['aging_source']);

        Carbon::setTestNow();
    }

    public function test_settlement_net_balance_policy_uses_oldest_positive_closing_balance_streak(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $this->insertSettlementHistory('tenant-history-1', 'contract-history-1', [
            ['2026-03-01', '2026-03-31', 0, 0, 0, 0],
            ['2026-04-01', '2026-04-30', 0, 0, 1200, 0],
            ['2026-05-01', '2026-05-31', 1200, 0, 1200, 0],
            ['2026-06-01', '2026-06-30', 1200, 0, 1200, 0],
        ]);

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'account' => '62',
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'contract_external_id' => 'contract-history-1',
                    'opening_debit' => 1200,
                    'opening_credit' => 0,
                    'closing_debit' => 1200,
                    'closing_credit' => 0,
                    'debt_amount' => 1200,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        );

        $this->assertSame('red', $candidate['status']);
        $this->assertSame('2026-04-15', $candidate['due_date']);
        $this->assertSame('settlement_net_balance_history', $candidate['aging_source']);

        Carbon::setTestNow();
    }

    public function test_settlement_net_balance_policy_does_not_skip_missing_periods(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $this->insertSettlementHistory('tenant-history-1', 'contract-history-gap', [
            ['2026-04-01', '2026-04-30', 0, 0, 1200, 0],
            ['2026-06-01', '2026-06-30', 1200, 0, 1200, 0],
        ]);

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'account' => '62',
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'contract_external_id' => 'contract-history-gap',
                    'opening_debit' => 1200,
                    'opening_credit' => 0,
                    'closing_debit' => 1200,
                    'closing_credit' => 0,
                    'debt_amount' => 1200,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        );

        $this->assertSame('pending', $candidate['status']);
        $this->assertSame('2026-06-15', $candidate['due_date']);

        Carbon::setTestNow();
    }

    public function test_invoice_day_policy_marks_debt_overdue_after_invoice_day_and_grace(): void
    {
        Carbon::setTestNow('2026-06-16 12:00:00');

        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'closing_debit' => 10000,
                    'closing_credit' => 0,
                    'debt_amount' => 10000,
                ]),
            ]),
            scope: 'space',
            reason: 'test rows',
            account: '62',
            agingPolicy: DebtDecisionPolicy::AGING_INVOICE_DAY,
        );

        $this->assertSame('orange', $candidate['status']);
        $this->assertSame('2026-06-15', $candidate['due_date']);
        $this->assertGreaterThanOrEqual(1, $candidate['overdue_days']);

        Carbon::setTestNow();
    }

    public function test_closed_settlement_balance_keeps_amount_and_status_separate(): void
    {
        $candidate = $this->policy->candidateFromSettlementRows(
            marketId: (int) $this->market->id,
            rows: collect([
                $this->row([
                    'period_from' => '2026-06-01',
                    'period_to' => '2026-06-30',
                    'closing_debit' => 5000,
                    'closing_credit' => 5000,
                    'debt_amount' => 0,
                ]),
            ]),
            scope: 'tenant_fallback',
            reason: 'closed rows',
            account: '62',
        );

        $this->assertSame('green', $candidate['status']);
        $this->assertSame('tenant_fallback', $candidate['scope']);
        $this->assertSame('medium', $candidate['confidence']);
        $this->assertSame(0.0, $candidate['debt_amount']);
        $this->assertSame(5000.0, $candidate['amount_basis']['closing_debit']);
        $this->assertSame(5000.0, $candidate['amount_basis']['closing_credit']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function row(array $overrides = []): object
    {
        return (object) array_merge([
            'period_from' => '2026-06-01',
            'period_to' => '2026-06-30',
            'tenant_id' => (int) $this->tenant->id,
            'contract_external_id' => 'contract-1',
            'account' => '62',
            'settlement_document_name' => null,
            'opening_debit' => 0,
            'opening_credit' => 0,
            'turnover_debit' => 0,
            'turnover_credit' => 0,
            'closing_debit' => 0,
            'closing_credit' => 0,
            'debt_amount' => 0,
        ], $overrides);
    }

    /**
     * @param array<int, array{0:string,1:string,2:float|int,3:float|int,4:float|int,5:float|int}> $periods
     */
    private function insertSettlementHistory(string $tenantExternalId, string $contractExternalId, array $periods): void
    {
        foreach ($periods as $period) {
            [$periodFrom, $periodTo, $openingDebit, $openingCredit, $closingDebit, $closingCredit] = $period;

            \DB::table('tenant_settlement_balances')->insert([
                'market_id' => $this->market->id,
                'tenant_id' => (int) $this->tenant->id,
                'tenant_contract_id' => null,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'tenant_external_id' => $tenantExternalId,
                'tenant_name' => 'History tenant',
                'contract_external_id' => $contractExternalId,
                'contract_name' => 'History contract',
                'account' => '62',
                'currency' => 'RUB',
                'opening_debit' => $openingDebit,
                'opening_credit' => $openingCredit,
                'turnover_debit' => max(0, (float) $closingDebit - (float) $openingDebit),
                'turnover_credit' => max(0, (float) $closingCredit - (float) $openingCredit),
                'closing_debit' => $closingDebit,
                'closing_credit' => $closingCredit,
                'source' => '1c',
                'source_file' => '1c:settlements',
                'imported_at' => Carbon::now(),
                'source_row_hash' => hash('sha256', implode('|', [$tenantExternalId, $contractExternalId, $periodFrom, $periodTo])),
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }
    }
}
