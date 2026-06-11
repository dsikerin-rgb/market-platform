<?php

declare(strict_types=1);

namespace App\Services\Debt;

use App\Models\Market;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DebtDecisionPolicy
{
    public const AGING_SETTLEMENT_DOCUMENT = 'settlement-document';

    public const AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY = 'settlement-document-invoice-day';

    public const AGING_SETTLEMENT_NET_BALANCE = 'settlement-net-balance';

    public const AGING_INVOICE_DAY = 'invoice-day';

    public const AGING_PERIOD_START = 'period-start';

    private const DEFAULT_INVOICE_DAY_OF_MONTH = 10;

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    public function candidateFromSettlementRows(
        int $marketId,
        Collection $rows,
        string $scope,
        string $reason,
        string $account,
        string $agingPolicy = self::AGING_SETTLEMENT_DOCUMENT,
    ): array {
        $netDebt = (float) $rows->sum('debt_amount');
        $settings = $this->debtSettings($marketId);
        $minimumDebt = (float) $settings['minimum_debt_amount'];

        $status = 'green';
        $overdueDays = null;
        $dueDate = null;
        $agingSource = null;

        if ($netDebt > 0.009 && $netDebt < $minimumDebt) {
            $status = 'green';
            $reason .= '; debt below threshold';
            $agingSource = 'not_needed_below_threshold';
        } elseif ($netDebt > 0.009) {
            [$dueDate, $agingSource] = $this->dueDateFromRows(
                $marketId,
                $rows,
                (int) $settings['grace_days'],
                $agingPolicy,
                $account,
                $scope,
                $minimumDebt,
            );

            if ($dueDate === null) {
                $status = 'gray';
                $reason .= '; cannot determine due date';
            } else {
                $now = Carbon::now();
                if (! $now->gt($dueDate)) {
                    $status = 'pending';
                    $overdueDays = 0;
                } else {
                    $overdueDays = $dueDate->diffInDays($now);
                    if ($overdueDays >= (int) $settings['red_after_days']) {
                        $status = 'red';
                    } elseif ($overdueDays >= (int) $settings['yellow_after_days']) {
                        $status = 'orange';
                    } else {
                        $status = 'pending';
                    }
                }
            }
        }

        return [
            'status' => $status,
            'scope' => $scope,
            'confidence' => $scope === 'space' ? 'high' : 'medium',
            'reason' => $reason,
            'account' => $account,
            'debt_amount' => round($netDebt, 2),
            'amount_source' => 'tenant_settlement_balances.closing_debit_minus_closing_credit',
            'amount_basis' => [
                'closing_debit' => round((float) $rows->sum('closing_debit'), 2),
                'closing_credit' => round((float) $rows->sum('closing_credit'), 2),
            ],
            'aging_policy' => $agingPolicy,
            'aging_source' => $agingSource,
            'overdue_days' => $overdueDays,
            'due_date' => $dueDate?->toDateString(),
            'rows' => $rows->count(),
            'contracts' => $rows->pluck('contract_external_id')->filter()->unique()->values()->all(),
            'latest_period_to' => (string) $rows->max('period_to'),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $candidate
     */
    public function classifyMismatch(array $current, array $candidate): string
    {
        $currentStatus = (string) ($current['status'] ?? 'none');
        $candidateStatus = (string) ($candidate['status'] ?? 'none');

        if ($candidateStatus === 'none') {
            return 'osv_no_candidate';
        }

        if ($candidateStatus === $currentStatus) {
            return 'same_status';
        }

        $currentScope = (string) (data_get($current, 'extra.scope') ?? 'none');
        $candidateScope = (string) ($candidate['scope'] ?? 'none');
        $currentDebt = (float) (data_get($current, 'extra.debt_amount') ?? 0);
        $candidateDebt = (float) ($candidate['debt_amount'] ?? 0);
        $currentOverdueDays = data_get($current, 'extra.overdue_days');
        $candidateOverdueDays = $candidate['overdue_days'] ?? null;

        if ($candidateStatus === 'green' && $currentStatus !== 'green') {
            return 'osv_closed_or_credit_while_current_map_has_debt';
        }

        if (in_array($currentStatus, ['green', 'gray'], true) && $candidateDebt > 0.009) {
            return 'osv_has_debt_missing_from_current_map';
        }

        if ($currentStatus === 'red' && in_array($candidateStatus, ['pending', 'orange', 'green', 'gray'], true)) {
            return 'current_map_more_severe_than_osv';
        }

        if (
            in_array($currentStatus, ['pending', 'orange'], true)
            && $candidateStatus === 'red'
            && is_numeric($currentOverdueDays)
            && is_numeric($candidateOverdueDays)
            && (float) $candidateOverdueDays > ((float) $currentOverdueDays + 20)
        ) {
            return 'osv_document_date_makes_debt_much_older';
        }

        if ($currentScope !== $candidateScope) {
            return 'scope_differs';
        }

        if (abs($currentDebt - $candidateDebt) > 1.0) {
            return 'debt_amount_differs';
        }

        return 'status_bucket_differs';
    }

    public function severityChange(string $currentStatus, string $candidateStatus): string
    {
        $rank = [
            'none' => 0,
            'gray' => 1,
            'green' => 2,
            'pending' => 3,
            'orange' => 4,
            'red' => 5,
        ];

        $currentRank = $rank[$currentStatus] ?? 0;
        $candidateRank = $rank[$candidateStatus] ?? 0;

        if ($candidateRank > $currentRank) {
            return 'more_severe';
        }

        if ($candidateRank < $currentRank) {
            return 'less_severe';
        }

        return 'same_severity';
    }

    /**
     * @return array{grace_days:int,yellow_after_days:int,red_after_days:int,minimum_debt_amount:float}
     */
    private function debtSettings(int $marketId): array
    {
        $market = Market::find($marketId);
        $settings = is_array($market?->settings) ? $market->settings : [];
        $debt = is_array($settings['debt_monitoring'] ?? null) ? $settings['debt_monitoring'] : [];

        $yellow = (int) ($debt['yellow_after_days'] ?? $debt['orange_after_days'] ?? 1);
        $red = (int) ($debt['red_after_days'] ?? 30);

        return [
            'grace_days' => (int) ($debt['grace_days'] ?? 5),
            'yellow_after_days' => max(1, $yellow),
            'red_after_days' => max($yellow + 1, $red),
            'minimum_debt_amount' => (float) ($debt['minimum_debt_amount'] ?? 500),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0:?Carbon,1:?string}
     */
    private function dueDateFromRows(
        int $marketId,
        Collection $rows,
        int $graceDays,
        string $agingPolicy,
        string $account,
        string $scope,
        float $minimumDebt,
    ): array {
        $positiveRows = $rows->filter(static function (object $row): bool {
            return ((float) $row->debt_amount) > 0.009;
        });

        if ($agingPolicy === self::AGING_SETTLEMENT_NET_BALANCE) {
            return $this->dueDateFromNetBalanceHistory($marketId, $rows, $graceDays, $account, $scope, $minimumDebt);
        }

        if ($agingPolicy === self::AGING_SETTLEMENT_DOCUMENT) {
            $dates = $positiveRows
                ->map(fn (object $row): ?Carbon => $this->parseDocumentDate((string) ($row->settlement_document_name ?? '')))
                ->filter()
                ->values();

            if ($dates->isNotEmpty()) {
                return [
                    $dates
                        ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
                        ->first()
                        ?->startOfDay()
                        ->addDays($graceDays),
                    'settlement_document_name',
                ];
            }
        }

        if ($agingPolicy === self::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY) {
            $dates = $positiveRows
                ->map(fn (object $row): ?Carbon => $this->parseDocumentDate((string) ($row->settlement_document_name ?? '')))
                ->filter()
                ->values();

            if ($dates->isNotEmpty()) {
                return [
                    $dates
                        ->map(fn (Carbon $date): Carbon => $this->documentInvoiceDayDueDate($date, $graceDays))
                        ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
                        ->first(),
                    'settlement_document_invoice_day',
                ];
            }
        }

        if ($agingPolicy === self::AGING_INVOICE_DAY) {
            $periodFrom = $positiveRows->min('period_from');
            if (! $periodFrom) {
                return [null, null];
            }

            try {
                return [
                    $this->invoiceDayDueDate(Carbon::parse((string) $periodFrom)->startOfMonth(), $graceDays),
                    'invoice_day',
                ];
            } catch (\Throwable) {
                return [null, null];
            }
        }

        $periodFrom = $positiveRows->min('period_from');
        if (! $periodFrom) {
            return [null, null];
        }

        try {
            return [
                Carbon::parse((string) $periodFrom)->startOfDay()->addDays($graceDays),
                'period_from',
            ];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{0:?Carbon,1:?string}
     */
    private function dueDateFromNetBalanceHistory(
        int $marketId,
        Collection $rows,
        int $graceDays,
        string $account,
        string $scope,
        float $minimumDebt,
    ): array {
        $periodFrom = $rows->min('period_from');
        if (! $periodFrom) {
            return [null, null];
        }

        try {
            $currentPeriod = Carbon::parse((string) $periodFrom)->startOfMonth();
        } catch (\Throwable) {
            return [null, null];
        }

        $openingDebt = (float) $rows->sum(static function (object $row): float {
            return (float) ($row->opening_debit ?? 0) - (float) ($row->opening_credit ?? 0);
        });

        if ($openingDebt < $minimumDebt) {
            return [$this->invoiceDayDueDate($currentPeriod, $graceDays), 'settlement_net_balance_current_period'];
        }

        $history = $this->settlementNetBalanceHistory($marketId, $rows, $account, $scope);
        $positiveStreak = [];
        $expectedPeriod = $currentPeriod->copy();

        foreach ($history as $period) {
            try {
                $periodStart = Carbon::parse((string) $period->period_from)->startOfMonth();
            } catch (\Throwable) {
                break;
            }

            if (! $periodStart->equalTo($expectedPeriod)) {
                break;
            }

            if ((float) ($period->debt_amount ?? 0) < $minimumDebt) {
                break;
            }

            $positiveStreak[] = $period;
            $expectedPeriod->subMonthNoOverflow();
        }

        if ($positiveStreak === []) {
            return [$this->invoiceDayDueDate($currentPeriod, $graceDays), 'settlement_net_balance_current_period'];
        }

        $oldestPositivePeriod = end($positiveStreak);
        $oldestPeriodFrom = $oldestPositivePeriod->period_from ?? null;
        if (! $oldestPeriodFrom) {
            return [$this->invoiceDayDueDate($currentPeriod, $graceDays), 'settlement_net_balance_current_period'];
        }

        try {
            return [
                $this->invoiceDayDueDate(Carbon::parse((string) $oldestPeriodFrom)->startOfMonth(), $graceDays),
                'settlement_net_balance_history',
            ];
        } catch (\Throwable) {
            return [$this->invoiceDayDueDate($currentPeriod, $graceDays), 'settlement_net_balance_current_period'];
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function settlementNetBalanceHistory(int $marketId, Collection $rows, string $account, string $scope): Collection
    {
        $latestPeriodTo = $rows->max('period_to');
        $tenantIds = $rows
            ->pluck('tenant_id')
            ->filter()
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->unique()
            ->values();
        $contractExternalIds = $rows
            ->pluck('contract_external_id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
        $accounts = $rows
            ->pluck('account')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($accounts->isEmpty()) {
            $accounts = collect(explode(',', $account))
                ->map(static fn (string $value): string => trim($value))
                ->filter()
                ->unique()
                ->values();
        }

        if ($accounts->isEmpty() || $tenantIds->count() !== 1) {
            return collect();
        }

        $hasBlankContractRows = $rows->contains(static function (object $row): bool {
            return trim((string) ($row->contract_external_id ?? '')) === '';
        });
        $useContractScopedHistory = in_array($scope, ['space', 'tenant_fallback_residual'], true);

        if ($useContractScopedHistory && $contractExternalIds->isEmpty() && ! $hasBlankContractRows) {
            return collect();
        }

        $query = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantIds->first())
            ->whereIn('account', $accounts->all());

        if ($latestPeriodTo) {
            $query->where('period_to', '<=', $latestPeriodTo);
        }

        if ($useContractScopedHistory) {
            $query->where(function ($contracts) use ($contractExternalIds, $hasBlankContractRows): void {
                if ($contractExternalIds->isNotEmpty()) {
                    $contracts->whereIn('contract_external_id', $contractExternalIds->all());
                }

                if ($hasBlankContractRows) {
                    $contractExternalIds->isNotEmpty()
                        ? $contracts->orWhereNull('contract_external_id')->orWhere('contract_external_id', '')
                        : $contracts->whereNull('contract_external_id')->orWhere('contract_external_id', '');
                }
            });
        }

        return $query
            ->select(['period_from', 'period_to'])
            ->selectRaw('SUM(COALESCE(closing_debit, 0) - COALESCE(closing_credit, 0)) as debt_amount')
            ->groupBy('period_from', 'period_to')
            ->orderByDesc('period_to')
            ->get();
    }

    private function documentInvoiceDayDueDate(Carbon $documentDate, int $graceDays): Carbon
    {
        $period = $documentDate->copy()->startOfMonth();
        if ($documentDate->day > self::DEFAULT_INVOICE_DAY_OF_MONTH) {
            $period->addMonthNoOverflow();
        }

        return $this->invoiceDayDueDate($period, $graceDays);
    }

    private function invoiceDayDueDate(Carbon $period, int $graceDays): Carbon
    {
        return $period
            ->copy()
            ->day(min(self::DEFAULT_INVOICE_DAY_OF_MONTH, $period->daysInMonth))
            ->startOfDay()
            ->addDays($graceDays);
    }

    private function parseDocumentDate(string $value): ?Carbon
    {
        if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?\b/u', $value, $matches) !== 1) {
            return null;
        }

        $time = sprintf(
            '%02d:%02d:%02d',
            isset($matches[4]) ? (int) $matches[4] : 0,
            isset($matches[5]) ? (int) $matches[5] : 0,
            isset($matches[6]) ? (int) $matches[6] : 0,
        );

        try {
            return Carbon::createFromFormat('d.m.Y H:i:s', "{$matches[1]}.{$matches[2]}.{$matches[3]} {$time}");
        } catch (\Throwable) {
            return null;
        }
    }
}
