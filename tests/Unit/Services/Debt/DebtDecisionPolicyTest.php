<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Debt;

use App\Models\Market;
use App\Services\Debt\DebtDecisionPolicy;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DebtDecisionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private DebtDecisionPolicy $policy;

    private Market $market;

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
            'contract_external_id' => 'contract-1',
            'settlement_document_name' => null,
            'closing_debit' => 0,
            'closing_credit' => 0,
            'debt_amount' => 0,
        ], $overrides);
    }
}
