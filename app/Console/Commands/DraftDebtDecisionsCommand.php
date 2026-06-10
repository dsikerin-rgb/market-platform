<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Services\Debt\DebtStatusResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DraftDebtDecisionsCommand extends Command
{
    protected $signature = 'debt:draft-decisions
        {--market=1 : Market ID}
        {--account=62 : Settlement account to inspect}
        {--limit=50 : Maximum sample rows}
        {--status= : Filter by current map status}
        {--mismatches : Return only rows where current map status differs from the OSV candidate}
        {--json : Output raw JSON only}';

    protected $description = 'Build a read-only draft comparison between current map debt colors and 1C settlement balances.';

    public function handle(DebtStatusResolver $resolver): int
    {
        $marketId = max(1, (int) $this->option('market'));
        $account = trim((string) $this->option('account'));
        $limit = max(1, (int) $this->option('limit'));
        $statusFilter = trim((string) ($this->option('status') ?? ''));
        $onlyMismatches = (bool) $this->option('mismatches');

        if (! Schema::hasTable('tenant_settlement_balances')) {
            $this->line(json_encode([
                'error' => 'tenant_settlement_balances table is missing',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

        DebtStatusResolver::clearCache();

        $spaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->whereNotNull('tenant_id')
            ->orderBy('id')
            ->get(['id', 'number', 'tenant_id', 'market_id']);

        $summary = [
            'market_id' => $marketId,
            'account' => $account,
            'active_spaces_with_tenant' => $spaces->count(),
            'current_map_statuses' => [],
            'osv_candidate_statuses' => [],
            'scope_candidates' => [],
            'mismatch_reasons' => [],
            'severity_changes' => [],
            'scope_changes' => [],
            'mismatches' => 0,
        ];

        $samples = [];

        foreach ($spaces as $space) {
            $current = $resolver->resolveForMarketSpace((int) $space->id, $marketId);
            $currentStatus = (string) ($current['status'] ?? 'null');

            if ($statusFilter !== '' && $currentStatus !== $statusFilter) {
                continue;
            }

            $candidate = $this->buildSettlementCandidate(
                marketId: $marketId,
                tenantId: (int) $space->tenant_id,
                marketSpaceId: (int) $space->id,
                account: $account,
            );

            $candidateStatus = (string) ($candidate['status'] ?? 'none');
            $candidateScope = (string) ($candidate['scope'] ?? 'none');
            $isMismatch = $candidateStatus !== 'none' && $candidateStatus !== $currentStatus;
            $mismatchReason = $this->classifyMismatch($current, $candidate);
            $severityChange = $this->severityChange($currentStatus, $candidateStatus);
            $scopeChange = sprintf(
                '%s -> %s',
                (string) (data_get($current, 'extra.scope') ?? 'none'),
                $candidateScope,
            );

            $summary['current_map_statuses'][$currentStatus] = ($summary['current_map_statuses'][$currentStatus] ?? 0) + 1;
            $summary['osv_candidate_statuses'][$candidateStatus] = ($summary['osv_candidate_statuses'][$candidateStatus] ?? 0) + 1;
            $summary['scope_candidates'][$candidateScope] = ($summary['scope_candidates'][$candidateScope] ?? 0) + 1;
            $summary['scope_changes'][$scopeChange] = ($summary['scope_changes'][$scopeChange] ?? 0) + 1;

            if ($isMismatch) {
                $summary['mismatches']++;
                $summary['mismatch_reasons'][$mismatchReason] = ($summary['mismatch_reasons'][$mismatchReason] ?? 0) + 1;
                $summary['severity_changes'][$severityChange] = ($summary['severity_changes'][$severityChange] ?? 0) + 1;
            }

            if ($onlyMismatches && ! $isMismatch) {
                continue;
            }

            if (count($samples) >= $limit) {
                continue;
            }

            $samples[] = [
                'space_id' => (int) $space->id,
                'space_number' => (string) $space->number,
                'tenant_id' => (int) $space->tenant_id,
                'current_map' => [
                    'status' => $currentStatus,
                    'scope' => data_get($current, 'extra.scope'),
                    'source' => $current['source'] ?? null,
                    'debt_amount' => data_get($current, 'extra.debt_amount'),
                    'overdue_days' => data_get($current, 'extra.overdue_days'),
                ],
                'osv_candidate' => $candidate,
                'mismatch' => $isMismatch,
                'mismatch_reason' => $isMismatch ? $mismatchReason : null,
                'severity_change' => $isMismatch ? $severityChange : null,
            ];
        }

        ksort($summary['current_map_statuses']);
        ksort($summary['osv_candidate_statuses']);
        ksort($summary['scope_candidates']);
        arsort($summary['mismatch_reasons']);
        ksort($summary['severity_changes']);
        ksort($summary['scope_changes']);

        $payload = [
            'summary' => $summary,
            'samples' => $samples,
        ];

        if (! (bool) $this->option('json')) {
            $this->info('Read-only draft. No database rows were changed.');
        }

        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettlementCandidate(int $marketId, int $tenantId, int $marketSpaceId, string $account): array
    {
        $contractIds = $this->activeContractExternalIdsForSpace($marketId, $tenantId, $marketSpaceId);
        $spaceRows = $contractIds->isNotEmpty()
            ? $this->latestSettlementRows($marketId, $account, $tenantId, $contractIds)
            : collect();

        if ($spaceRows->isNotEmpty()) {
            return $this->candidateFromRows($marketId, $spaceRows, 'space', 'active space contract has OSV rows');
        }

        $tenantRows = $this->latestSettlementRows($marketId, $account, $tenantId, null);
        if ($tenantRows->isEmpty()) {
            return [
                'status' => 'none',
                'scope' => 'none',
                'reason' => 'no OSV rows for tenant/account/latest period',
            ];
        }

        $unboundDebt = $tenantRows->filter(static function (object $row): bool {
            return ((float) $row->debt_amount) > 0.009;
        });

        if ($unboundDebt->isEmpty()) {
            return $this->candidateFromRows($marketId, $tenantRows, 'tenant_fallback', 'tenant OSV rows have no positive debt');
        }

        return $this->candidateFromRows(
            $marketId,
            $tenantRows,
            'tenant_fallback',
            $contractIds->isEmpty()
                ? 'tenant has OSV debt but no active exact contract link for this space'
                : 'tenant has OSV debt, but no positive OSV row for active exact space contract'
        );
    }

    /**
     * @return Collection<int, string>
     */
    private function activeContractExternalIdsForSpace(int $marketId, int $tenantId, int $marketSpaceId): Collection
    {
        return DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('market_space_id', $marketSpaceId)
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param Collection<int, string>|null $contractExternalIds
     * @return Collection<int, object>
     */
    private function latestSettlementRows(
        int $marketId,
        string $account,
        int $tenantId,
        ?Collection $contractExternalIds,
    ): Collection {
        $latestPeriod = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('account', $account)
            ->max('period_to');

        if (! $latestPeriod) {
            return collect();
        }

        $query = DB::table('tenant_settlement_balances')
            ->where('market_id', $marketId)
            ->where('account', $account)
            ->where('period_to', $latestPeriod)
            ->where('tenant_id', $tenantId);

        if ($contractExternalIds !== null) {
            $query->whereIn('contract_external_id', $contractExternalIds->all());
        }

        return $query
            ->select([
                'period_from',
                'period_to',
                'account',
                'tenant_name',
                'contract_name',
                'contract_external_id',
                'settlement_document_name',
                'opening_debit',
                'opening_credit',
                'turnover_debit',
                'turnover_credit',
                'closing_debit',
                'closing_credit',
            ])
            ->selectRaw('(COALESCE(closing_debit, 0) - COALESCE(closing_credit, 0)) as debt_amount')
            ->get();
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<string, mixed>
     */
    private function candidateFromRows(int $marketId, Collection $rows, string $scope, string $reason): array
    {
        $netDebt = (float) $rows->sum('debt_amount');
        $settings = $this->debtSettings($marketId);
        $minimumDebt = (float) $settings['minimum_debt_amount'];

        $status = 'green';
        $overdueDays = null;
        $dueDate = null;

        if ($netDebt > 0.009 && $netDebt < $minimumDebt) {
            $status = 'green';
            $reason .= '; debt below threshold';
        } elseif ($netDebt > 0.009) {
            $dueDate = $this->dueDateFromRows($rows, (int) $settings['grace_days']);

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
            'reason' => $reason,
            'debt_amount' => round($netDebt, 2),
            'overdue_days' => $overdueDays,
            'due_date' => $dueDate?->toDateString(),
            'rows' => $rows->count(),
            'contracts' => $rows->pluck('contract_external_id')->filter()->unique()->values()->all(),
            'latest_period_to' => (string) $rows->max('period_to'),
        ];
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $candidate
     */
    private function classifyMismatch(array $current, array $candidate): string
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

    private function severityChange(string $currentStatus, string $candidateStatus): string
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
     * @param Collection<int, object> $rows
     */
    private function dueDateFromRows(Collection $rows, int $graceDays): ?Carbon
    {
        $positiveRows = $rows->filter(static function (object $row): bool {
            return ((float) $row->debt_amount) > 0.009;
        });

        $dates = $positiveRows
            ->map(fn (object $row): ?Carbon => $this->parseDocumentDate((string) ($row->settlement_document_name ?? '')))
            ->filter()
            ->values();

        if ($dates->isNotEmpty()) {
            return $dates
                ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
                ->first()
                ?->startOfDay()
                ->addDays($graceDays);
        }

        $periodFrom = $positiveRows->min('period_from');
        if (! $periodFrom) {
            return null;
        }

        try {
            return Carbon::parse((string) $periodFrom)->startOfDay()->addDays($graceDays);
        } catch (\Throwable) {
            return null;
        }
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
