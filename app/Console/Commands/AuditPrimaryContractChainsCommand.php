<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketIntegration;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class AuditPrimaryContractChainsCommand extends Command
{
    protected $signature = 'contracts:audit-primary-chains
        {--market= : Market ID (default: market_id from active 1C integration)}
        {--limit=20 : How many top groups to print}
        {--min-count=2 : Minimum contracts in a chain}
        {--only-auto : Show only groups that can be auto-resolved}
        {--only-ambiguous : Show only groups that still require review}
        {--include-test : Include TEST/placeholder contract numbers in output}';

    protected $description = 'Read-only audit of primary contract chains on the same tenant/place using document dates from contract numbers.';

    public function handle(ContractDocumentClassifier $classifier): int
    {
        $marketId = $this->resolveMarketId();
        $limit = max(0, (int) $this->option('limit'));
        $minCount = max(2, (int) $this->option('min-count'));
        $onlyAuto = (bool) $this->option('only-auto');
        $onlyAmbiguous = (bool) $this->option('only-ambiguous');
        $includeTest = (bool) $this->option('include-test');

        $contracts = TenantContract::query()
            ->where('market_id', $marketId)
            ->whereNotNull('tenant_id')
            ->whereNotNull('market_space_id')
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->orderBy('id')
            ->get([
                'id',
                'tenant_id',
                'market_space_id',
                'space_mapping_mode',
                'number',
                'starts_at',
                'ends_at',
                'status',
            ]);

        $stats = [
            'market_id' => $marketId,
            'total_scanned' => $contracts->count(),
            'primary_considered' => 0,
            'group_count' => 0,
            'groups_with_candidate' => 0,
            'groups_without_document_date' => 0,
            'groups_with_test_noise' => 0,
            'auto_resolvable_groups' => 0,
            'ambiguous_groups' => 0,
            'groups_with_place_token_conflicts' => 0,
            'groups_with_latest_date_ties' => 0,
            'groups_with_missing_place_tokens' => 0,
        ];

        $groups = [];

        foreach ($contracts as $contract) {
            if ($contract->excludesFromSpaceMapping()) {
                continue;
            }

            $classification = $classifier->classify((string) ($contract->number ?? ''));
            if (($classification['category'] ?? null) !== 'primary_contract') {
                continue;
            }

            $stats['primary_considered']++;

            $number = (string) ($contract->number ?? '');
            if (! $includeTest && $this->looksSyntheticNumber($number)) {
                continue;
            }

            $key = (int) $contract->tenant_id . ':' . (int) $contract->market_space_id;
            $groups[$key] ??= [
                'tenant_id' => (int) $contract->tenant_id,
                'market_space_id' => (int) $contract->market_space_id,
                'contracts' => [],
            ];

            $groups[$key]['contracts'][] = [
                'id' => (int) $contract->id,
                'number' => $number,
                'mode' => (string) ($contract->space_mapping_mode ?? ''),
                'status' => (string) ($contract->status ?? ''),
                'starts_at' => optional($contract->starts_at)->format('Y-m-d'),
                'ends_at' => optional($contract->ends_at)->format('Y-m-d'),
                'document_date' => $classification['document_date'],
                'place_token' => $classification['place_token'],
                'synthetic' => $this->looksSyntheticNumber($number),
            ];
        }

        $preparedGroups = collect($groups)
            ->map(function (array $group) use (&$stats, $minCount): ?array {
                $contracts = collect($group['contracts']);
                if ($contracts->count() < $minCount) {
                    return null;
                }

                $sortedContracts = $contracts->all();
                usort($sortedContracts, [$this, 'compareContracts']);
                $sorted = collect($sortedContracts)->values();

                $candidate = $sorted->first(static fn (array $item): bool => $item['document_date'] !== null);
                $hasTestNoise = $contracts->contains(static fn (array $item): bool => $item['synthetic'] === true);

                if ($candidate !== null) {
                    $stats['groups_with_candidate']++;
                } else {
                    $stats['groups_without_document_date']++;
                }

                if ($hasTestNoise) {
                    $stats['groups_with_test_noise']++;
                }

                $distinctPlaceTokens = $contracts
                    ->pluck('place_token')
                    ->filter(static fn (?string $token): bool => $token !== null && $token !== '')
                    ->unique()
                    ->values();

                $hasPlaceTokenConflict = $distinctPlaceTokens->count() > 1;
                $missingPlaceTokenCount = $contracts->whereNull('place_token')->count();
                $latestDateTies = $candidate !== null
                    ? $contracts->where('document_date', $candidate['document_date'])->pluck('id')->values()->all()
                    : [];
                $hasLatestDateTie = count($latestDateTies) > 1;

                if ($hasPlaceTokenConflict) {
                    $stats['groups_with_place_token_conflicts']++;
                }

                if ($hasLatestDateTie) {
                    $stats['groups_with_latest_date_ties']++;
                }

                if ($missingPlaceTokenCount > 0) {
                    $stats['groups_with_missing_place_tokens']++;
                }

                $resolutionStatus = 'auto_single_latest_document';
                if ($candidate === null) {
                    $resolutionStatus = 'review_missing_document_date';
                } elseif ($hasPlaceTokenConflict) {
                    $resolutionStatus = 'review_mixed_place_tokens';
                } elseif ($hasLatestDateTie) {
                    $resolutionStatus = 'review_latest_date_tie';
                }

                $autoResolvable = $resolutionStatus === 'auto_single_latest_document';
                if ($autoResolvable) {
                    $stats['auto_resolvable_groups']++;
                } else {
                    $stats['ambiguous_groups']++;
                }

                return [
                    'tenant_id' => $group['tenant_id'],
                    'market_space_id' => $group['market_space_id'],
                    'count' => $contracts->count(),
                    'candidate_contract_id' => $candidate['id'] ?? null,
                    'candidate_document_date' => $candidate['document_date'] ?? null,
                    'historical_contract_ids' => $candidate !== null
                        ? $sorted
                            ->reject(static fn (array $item): bool => $item['id'] === $candidate['id'])
                            ->pluck('id')
                            ->values()
                            ->all()
                        : $sorted->pluck('id')->values()->all(),
                    'auto_resolvable' => $autoResolvable,
                    'resolution_status' => $resolutionStatus,
                    'place_tokens' => $distinctPlaceTokens->all(),
                    'missing_place_token_count' => $missingPlaceTokenCount,
                    'latest_date_tie_contract_ids' => $hasLatestDateTie ? $latestDateTies : [],
                    'has_test_noise' => $hasTestNoise,
                    'contracts' => $sorted->all(),
                ];
            })
            ->filter()
            ->when($onlyAuto, static fn (Collection $groups): Collection => $groups->where('auto_resolvable', true))
            ->when($onlyAmbiguous, static fn (Collection $groups): Collection => $groups->where('auto_resolvable', false))
            ->sort(fn (array $left, array $right): int => $this->compareGroups($left, $right))
            ->values();

        $stats['group_count'] = $preparedGroups->count();

        $this->line(json_encode([
            'stats' => $stats,
            'groups' => $limit > 0 ? $preparedGroups->take($limit)->all() : $preparedGroups->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function resolveMarketId(): int
    {
        $marketId = $this->option('market');
        if (is_numeric($marketId) && (int) $marketId > 0) {
            return (int) $marketId;
        }

        $integration = MarketIntegration::query()
            ->where('type', MarketIntegration::TYPE_1C)
            ->where('status', 'active')
            ->first();

        return (int) ($integration?->market_id ?? 1);
    }

    private function looksSyntheticNumber(string $number): bool
    {
        $value = mb_strtoupper(trim($number), 'UTF-8');

        return str_starts_with($value, 'TEST')
            || str_contains($value, 'CONTRACT-')
            || str_contains($value, 'STAGING-CHECK');
    }

    /**
     * @param  array{id:int,document_date:?string}  $left
     * @param  array{id:int,document_date:?string}  $right
     */
    private function compareContracts(array $left, array $right): int
    {
        $leftHasDate = $left['document_date'] !== null;
        $rightHasDate = $right['document_date'] !== null;

        if ($leftHasDate !== $rightHasDate) {
            return $leftHasDate ? -1 : 1;
        }

        $dateComparison = strcmp($right['document_date'] ?? '', $left['document_date'] ?? '');
        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        return $right['id'] <=> $left['id'];
    }

    /**
     * @param  array{count:int,candidate_document_date:?string,tenant_id:int,market_space_id:int}  $left
     * @param  array{count:int,candidate_document_date:?string,tenant_id:int,market_space_id:int}  $right
     */
    private function compareGroups(array $left, array $right): int
    {
        $countComparison = $right['count'] <=> $left['count'];
        if ($countComparison !== 0) {
            return $countComparison;
        }

        $dateComparison = strcmp($right['candidate_document_date'] ?? '', $left['candidate_document_date'] ?? '');
        if ($dateComparison !== 0) {
            return $dateComparison;
        }

        $tenantComparison = $left['tenant_id'] <=> $right['tenant_id'];
        if ($tenantComparison !== 0) {
            return $tenantComparison;
        }

        return $left['market_space_id'] <=> $right['market_space_id'];
    }
}
