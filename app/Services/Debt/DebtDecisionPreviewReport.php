<?php

declare(strict_types=1);

namespace App\Services\Debt;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DebtDecisionPreviewReport
{
    public function __construct(
        private readonly DebtStatusResolver $resolver,
        private readonly DebtDecisionPolicy $policy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(
        int $marketId,
        string $account,
        string $agingPolicy,
        ?string $currentStatusFilter = null,
        bool $onlyMismatches = false,
    ): array {
        if (! Schema::hasTable('tenant_settlement_balances')) {
            return [
                'error' => 'tenant_settlement_balances table is missing',
                'summary' => $this->emptySummary($marketId, $account, $agingPolicy),
                'rows' => [],
            ];
        }

        DebtStatusResolver::clearCache();

        $spaces = DB::table('market_spaces as ms')
            ->leftJoin('tenants as t', 't.id', '=', 'ms.tenant_id')
            ->where('ms.market_id', $marketId)
            ->where('ms.is_active', true)
            ->whereNotNull('ms.tenant_id')
            ->orderBy('ms.id')
            ->get([
                'ms.id',
                'ms.number',
                'ms.tenant_id',
                'ms.market_id',
                't.name as tenant_name',
            ]);

        $summary = $this->emptySummary($marketId, $account, $agingPolicy);
        $summary['active_spaces_with_tenant'] = $spaces->count();
        $rows = [];

        foreach ($spaces as $space) {
            $current = $this->resolver->resolveForMarketSpace((int) $space->id, $marketId);
            $currentStatus = (string) ($current['status'] ?? 'none');

            if ($currentStatusFilter !== null && $currentStatusFilter !== '' && $currentStatus !== $currentStatusFilter) {
                continue;
            }

            $currentScope = (string) (data_get($current, 'extra.scope') ?? 'none');
            $candidate = $currentScope === 'shared_use'
                ? $this->sharedUseCandidate($account)
                : $this->buildSettlementCandidate(
                    marketId: $marketId,
                    tenantId: (int) $space->tenant_id,
                    marketSpaceId: (int) $space->id,
                    account: $account,
                    agingPolicy: $agingPolicy,
                );

            $candidateStatus = (string) ($candidate['status'] ?? 'none');
            $candidateScope = (string) ($candidate['scope'] ?? 'none');
            $isMismatch = $candidateStatus !== 'none' && $candidateStatus !== $currentStatus;
            $mismatchReason = $this->policy->classifyMismatch($current, $candidate);
            $severityChange = $this->policy->severityChange($currentStatus, $candidateStatus);
            $scopeChange = sprintf(
                '%s -> %s',
                $currentScope,
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

            $rows[] = [
                'space_id' => (int) $space->id,
                'space_number' => (string) $space->number,
                'tenant_id' => (int) $space->tenant_id,
                'tenant_name' => (string) ($space->tenant_name ?? ''),
                'current_map' => [
                    'status' => $currentStatus,
                    'label' => (string) ($current['label'] ?? ''),
                    'scope' => data_get($current, 'extra.scope'),
                    'source' => $current['source'] ?? null,
                    'debt_amount' => data_get($current, 'extra.debt_amount'),
                    'overdue_days' => data_get($current, 'extra.overdue_days'),
                ],
                'osv_candidate' => $candidate,
                'mismatch' => $isMismatch,
                'mismatch_reason' => $isMismatch ? $mismatchReason : null,
                'severity_change' => $isMismatch ? $severityChange : 'same_severity',
            ];
        }

        ksort($summary['current_map_statuses']);
        ksort($summary['osv_candidate_statuses']);
        ksort($summary['scope_candidates']);
        arsort($summary['mismatch_reasons']);
        ksort($summary['severity_changes']);
        ksort($summary['scope_changes']);

        return [
            'summary' => $summary,
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sharedUseCandidate(string $account): array
    {
        return [
            'status' => 'gray',
            'scope' => 'shared_use',
            'confidence' => 'high',
            'reason' => 'shared-use space keeps neutral map status until exact financial attribution',
            'account' => $account,
            'debt_amount' => 0.0,
            'amount_source' => 'shared_use_rule',
            'amount_basis' => 'not_applicable',
            'aging_policy' => null,
            'aging_source' => null,
            'overdue_days' => null,
            'due_date' => null,
            'rows' => 0,
            'contracts' => [],
            'contract_names' => [],
            'latest_period_to' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSettlementCandidate(
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
            return $this->candidateFromRows(
                $spaceRows,
                $marketId,
                'space',
                'active space contract has OSV rows',
                $account,
                $agingPolicy,
            );
        }

        $tenantRows = $this->latestSettlementRows($marketId, $account, $tenantId, null);
        if ($tenantRows->isEmpty()) {
            return [
                'status' => 'none',
                'scope' => 'none',
                'reason' => 'no OSV rows for tenant/account/latest period',
                'account' => $account,
                'debt_amount' => 0.0,
                'rows' => 0,
                'contracts' => [],
                'contract_names' => [],
                'latest_period_to' => null,
            ];
        }

        $exactTenantSpaceContractIds = $this->activeContractExternalIdsForTenantSpaces($marketId, $tenantId);
        $fallbackMode = 'tenant_total';
        $candidateRows = $tenantRows;
        $decisionScope = 'tenant_fallback';

        if ($exactTenantSpaceContractIds->isNotEmpty()) {
            $candidateRows = $tenantRows
                ->filter(static function (object $row) use ($exactTenantSpaceContractIds): bool {
                    $contractExternalId = trim((string) ($row->contract_external_id ?? ''));

                    return $contractExternalId === ''
                        || ! $exactTenantSpaceContractIds->contains($contractExternalId);
                })
                ->values();
            $fallbackMode = 'residual';
            $decisionScope = 'tenant_fallback_residual';

            if ($candidateRows->isEmpty()) {
                return [
                    'status' => 'green',
                    'scope' => 'tenant_fallback',
                    'confidence' => 'medium',
                    'reason' => 'tenant OSV rows are already represented by exact active space contracts',
                    'account' => $account,
                    'debt_amount' => 0.0,
                    'amount_source' => 'tenant_settlement_balances.residual_after_exact_space_contracts',
                    'amount_basis' => 'net_balance',
                    'aging_policy' => $agingPolicy,
                    'aging_source' => null,
                    'overdue_days' => null,
                    'due_date' => null,
                    'rows' => 0,
                    'contracts' => [],
                    'contract_names' => [],
                    'latest_period_to' => (string) $tenantRows->max('period_to'),
                    'fallback_mode' => 'residual',
                    'exact_space_contracts_excluded' => $exactTenantSpaceContractIds->values()->all(),
                    'exact_space_contracts_excluded_count' => $exactTenantSpaceContractIds->count(),
                ];
            }
        }

        $unboundDebt = $candidateRows->filter(static function (object $row): bool {
            return ((float) $row->debt_amount) > 0.009;
        });

        if ($unboundDebt->isEmpty()) {
            $candidate = $this->candidateFromRows(
                $candidateRows,
                $marketId,
                $decisionScope,
                'tenant OSV rows have no positive debt',
                $account,
                $agingPolicy,
                'tenant_fallback',
            );

            return $this->withFallbackMeta($candidate, $fallbackMode, $exactTenantSpaceContractIds);
        }

        $candidate = $this->candidateFromRows(
            $candidateRows,
            $marketId,
            $decisionScope,
            $contractIds->isEmpty()
                ? 'tenant has OSV debt but no active exact contract link for this space'
                : 'tenant has OSV debt, but no positive OSV row for active exact space contract',
            $account,
            $agingPolicy,
            'tenant_fallback',
        );

        return $this->withFallbackMeta($candidate, $fallbackMode, $exactTenantSpaceContractIds);
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<string, mixed>
     */
    private function candidateFromRows(
        Collection $rows,
        int $marketId,
        string $scope,
        string $reason,
        string $account,
        string $agingPolicy,
        ?string $displayScope = null,
    ): array {
        $candidate = $this->policy->candidateFromSettlementRows($marketId, $rows, $scope, $reason, $account, $agingPolicy);
        if ($displayScope !== null) {
            $candidate['scope'] = $displayScope;
        }

        $candidate['contract_names'] = $rows
            ->pluck('contract_name')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $candidate;
    }

    /**
     * @return Collection<int, string>
     */
    private function activeContractExternalIdsForSpace(int $marketId, int $tenantId, int $marketSpaceId): Collection
    {
        $contractIds = collect();

        if (
            Schema::hasTable('market_space_tenant_bindings')
            && Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')
        ) {
            $now = now();

            $contractIds = $contractIds->merge(DB::table('market_space_tenant_bindings as mstb')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'mstb.tenant_contract_id')
                ->where('mstb.market_space_id', $marketSpaceId)
                ->where('mstb.market_id', $marketId)
                ->where('mstb.tenant_id', $tenantId)
                ->whereNotNull('mstb.tenant_contract_id')
                ->whereNotNull('tc.external_id')
                ->where('tc.market_id', $marketId)
                ->where('tc.tenant_id', $tenantId)
                ->where('tc.is_active', true)
                ->whereNotIn('tc.status', ['terminated', 'archived'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', $now);
                })
                ->pluck('tc.external_id'));
        }

        $contractIds = $contractIds->merge(DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('market_space_id', $marketSpaceId)
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->whereNotNull('external_id')
            ->pluck('external_id'));

        return $contractIds
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @return Collection<int, string>
     */
    private function activeContractExternalIdsForTenantSpaces(int $marketId, int $tenantId): Collection
    {
        if (
            ! Schema::hasTable('tenant_contracts')
            || ! Schema::hasTable('market_spaces')
            || ! Schema::hasColumn('tenant_contracts', 'external_id')
            || ! Schema::hasColumn('tenant_contracts', 'market_space_id')
            || ! Schema::hasColumn('market_spaces', 'tenant_id')
        ) {
            return collect();
        }

        $contractIds = collect();
        $now = now();

        if (
            Schema::hasTable('market_space_tenant_bindings')
            && Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')
        ) {
            $contractIds = $contractIds->merge(DB::table('market_space_tenant_bindings as mstb')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'mstb.tenant_contract_id')
                ->join('market_spaces as ms', 'ms.id', '=', 'mstb.market_space_id')
                ->where('mstb.market_id', $marketId)
                ->where('mstb.tenant_id', $tenantId)
                ->whereNotNull('mstb.tenant_contract_id')
                ->whereNotNull('tc.external_id')
                ->where('tc.market_id', $marketId)
                ->where('tc.tenant_id', $tenantId)
                ->where('tc.is_active', true)
                ->whereNotIn('tc.status', ['terminated', 'archived'])
                ->where('ms.market_id', $marketId)
                ->where('ms.tenant_id', $tenantId)
                ->where('ms.is_active', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', $now);
                })
                ->pluck('tc.external_id'));
        }

        $contractIds = $contractIds->merge(DB::table('tenant_contracts as tc')
            ->join('market_spaces as ms', 'ms.id', '=', 'tc.market_space_id')
            ->where('tc.market_id', $marketId)
            ->where('tc.tenant_id', $tenantId)
            ->where('tc.is_active', true)
            ->whereNotIn('tc.status', ['terminated', 'archived'])
            ->whereNotNull('tc.external_id')
            ->where('ms.market_id', $marketId)
            ->where('ms.tenant_id', $tenantId)
            ->where('ms.is_active', true)
            ->pluck('tc.external_id'));

        return $contractIds
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  Collection<int, string>  $exactTenantSpaceContractIds
     * @return array<string, mixed>
     */
    private function withFallbackMeta(array $candidate, string $fallbackMode, Collection $exactTenantSpaceContractIds): array
    {
        $candidate['fallback_mode'] = $fallbackMode;
        $candidate['exact_space_contracts_excluded'] = $exactTenantSpaceContractIds->values()->all();
        $candidate['exact_space_contracts_excluded_count'] = $exactTenantSpaceContractIds->count();

        return $candidate;
    }

    /**
     * @param  Collection<int, string>|null  $contractExternalIds
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
                'tenant_id',
                'tenant_contract_id',
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
     * @return array<string, mixed>
     */
    private function emptySummary(int $marketId, string $account, string $agingPolicy): array
    {
        return [
            'market_id' => $marketId,
            'account' => $account,
            'aging_policy' => $agingPolicy,
            'active_spaces_with_tenant' => 0,
            'current_map_statuses' => [],
            'osv_candidate_statuses' => [],
            'scope_candidates' => [],
            'mismatch_reasons' => [],
            'severity_changes' => [],
            'scope_changes' => [],
            'mismatches' => 0,
        ];
    }
}
