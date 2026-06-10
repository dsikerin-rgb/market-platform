<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use App\Services\Debt\DebtDecisionPolicy;
use App\Services\Debt\DebtStatusResolver;
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
        {--aging-policy=settlement-document : OSV aging policy: settlement-document or period-start}
        {--json : Output raw JSON only}';

    protected $description = 'Build a read-only draft comparison between current map debt colors and 1C settlement balances.';

    public function handle(DebtStatusResolver $resolver, DebtDecisionPolicy $policy): int
    {
        $marketId = max(1, (int) $this->option('market'));
        $account = trim((string) $this->option('account'));
        $limit = max(1, (int) $this->option('limit'));
        $statusFilter = trim((string) ($this->option('status') ?? ''));
        $onlyMismatches = (bool) $this->option('mismatches');
        $agingPolicy = trim((string) $this->option('aging-policy'));

        if (! in_array($agingPolicy, [DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT, DebtDecisionPolicy::AGING_PERIOD_START], true)) {
            $this->line(json_encode([
                'error' => 'unsupported aging policy',
                'supported' => [
                    DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT,
                    DebtDecisionPolicy::AGING_PERIOD_START,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::FAILURE;
        }

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
            'aging_policy' => $agingPolicy,
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
                policy: $policy,
                marketId: $marketId,
                tenantId: (int) $space->tenant_id,
                marketSpaceId: (int) $space->id,
                account: $account,
                agingPolicy: $agingPolicy,
            );

            $candidateStatus = (string) ($candidate['status'] ?? 'none');
            $candidateScope = (string) ($candidate['scope'] ?? 'none');
            $isMismatch = $candidateStatus !== 'none' && $candidateStatus !== $currentStatus;
            $mismatchReason = $policy->classifyMismatch($current, $candidate);
            $severityChange = $policy->severityChange($currentStatus, $candidateStatus);
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
    private function buildSettlementCandidate(
        DebtDecisionPolicy $policy,
        int $marketId,
        int $tenantId,
        int $marketSpaceId,
        string $account,
        string $agingPolicy,
    ): array {
        $contractIds = $this->activeContractExternalIdsForSpace($marketId, $tenantId, $marketSpaceId);
        $spaceRows = $contractIds->isNotEmpty()
            ? $this->latestSettlementRows($marketId, $account, $tenantId, $contractIds)
            : collect();

        if ($spaceRows->isNotEmpty()) {
            return $policy->candidateFromSettlementRows($marketId, $spaceRows, 'space', 'active space contract has OSV rows', $account, $agingPolicy);
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
            return $policy->candidateFromSettlementRows($marketId, $tenantRows, 'tenant_fallback', 'tenant OSV rows have no positive debt', $account, $agingPolicy);
        }

        return $policy->candidateFromSettlementRows(
            $marketId,
            $tenantRows,
            'tenant_fallback',
            $contractIds->isEmpty()
                ? 'tenant has OSV debt but no active exact contract link for this space'
                : 'tenant has OSV debt, but no positive OSV row for active exact space contract',
            $account,
            $agingPolicy,
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

}
