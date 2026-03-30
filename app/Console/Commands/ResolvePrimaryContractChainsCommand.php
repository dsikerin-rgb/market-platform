<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantContract;
use App\Services\MarketSpaces\MarketSpaceTenantBindingRecorder;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ResolvePrimaryContractChainsCommand extends Command
{
    protected $signature = 'contracts:resolve-primary-chains
        {--market= : Market ID}
        {--limit=0 : Limit auto-resolvable groups for testing}
        {--apply : Actually update contracts (default: preview only)}';

    protected $description = 'Preview or apply safe primary-chain cleanup for auto-resolvable contract groups.';

    public function handle(
        ContractDocumentClassifier $classifier,
        MarketSpaceTenantBindingRecorder $recorder,
    ): int {
        $marketId = max(1, (int) $this->option('market'));
        $limit = max(0, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');
        $now = now();

        $contracts = TenantContract::query()
            ->where('market_id', $marketId)
            ->whereNotNull('tenant_id')
            ->whereNotNull('market_space_id')
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->orderBy('id')
            ->get([
                'id',
                'market_id',
                'tenant_id',
                'market_space_id',
                'space_mapping_mode',
                'space_mapping_updated_at',
                'space_mapping_updated_by_user_id',
                'number',
                'starts_at',
                'ends_at',
                'status',
                'is_active',
            ]);

        $groups = [];

        foreach ($contracts as $contract) {
            if ($contract->excludesFromSpaceMapping()) {
                continue;
            }

            $number = (string) ($contract->number ?? '');
            if ($this->looksSyntheticNumber($number)) {
                continue;
            }

            $classification = $classifier->classify($number);
            if (($classification['category'] ?? null) !== 'primary_contract') {
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
                'document_date' => $classification['document_date'],
                'place_token' => $classification['place_token'],
            ];
        }

        $preparedGroups = collect($groups)
            ->map(fn (array $group): ?array => $this->prepareAutoResolvableGroup($group))
            ->filter()
            ->values();

        if ($limit > 0) {
            $preparedGroups = $preparedGroups->take($limit)->values();
        }

        $stats = [
            'mode' => $apply ? 'apply' : 'preview',
            'market_id' => $marketId,
            'total_groups' => $preparedGroups->count(),
            'updated_contracts' => 0,
            'excluded_contracts' => 0,
            'unchanged_contracts' => 0,
        ];

        $samples = [];

        foreach ($preparedGroups as $group) {
            $candidate = TenantContract::query()->find($group['candidate_contract_id']);
            if (! $candidate instanceof TenantContract) {
                continue;
            }

            $historicalContracts = TenantContract::query()
                ->whereIn('id', $group['historical_contract_ids'])
                ->orderBy('id')
                ->get();

            $groupSample = [
                'tenant_id' => $group['tenant_id'],
                'market_space_id' => $group['market_space_id'],
                'candidate_contract_id' => $group['candidate_contract_id'],
                'candidate_document_date' => $group['candidate_document_date'],
                'historical_contract_ids' => $group['historical_contract_ids'],
                'applied' => $apply,
            ];

            if ($apply) {
                foreach ($historicalContracts as $historical) {
                    if ($historical->excludesFromSpaceMapping()) {
                        $stats['unchanged_contracts']++;
                        continue;
                    }

                    $historical->forceFill([
                        'space_mapping_mode' => TenantContract::SPACE_MAPPING_MODE_EXCLUDED,
                        'space_mapping_updated_at' => $now,
                    ]);
                    $historical->save();

                    $stats['excluded_contracts']++;
                    $stats['updated_contracts']++;
                }

                $recorder->syncFromContract($candidate);
            }

            if (count($samples) < 30) {
                $samples[] = $groupSample;
            }
        }

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /**
     * @param  array{
     *   tenant_id:int,
     *   market_space_id:int,
     *   contracts:list<array{id:int,document_date:?string,place_token:?string}>
     * }  $group
     * @return array{
     *   tenant_id:int,
     *   market_space_id:int,
     *   candidate_contract_id:int,
     *   candidate_document_date:string,
     *   historical_contract_ids:list<int>
     * }|null
     */
    private function prepareAutoResolvableGroup(array $group): ?array
    {
        $contracts = collect($group['contracts']);
        if ($contracts->count() < 2) {
            return null;
        }

        $distinctPlaceTokens = $contracts
            ->pluck('place_token')
            ->filter(static fn (?string $token): bool => $token !== null && $token !== '')
            ->unique()
            ->values();

        if ($distinctPlaceTokens->count() > 1) {
            return null;
        }

        $dated = $contracts->filter(static fn (array $item): bool => $item['document_date'] !== null)->values();
        if ($dated->isEmpty()) {
            return null;
        }

        $sortedContracts = $dated->all();
        usort($sortedContracts, [$this, 'compareContracts']);
        $sorted = collect($sortedContracts)->values();

        $candidate = $sorted->first();
        if ($candidate === null || ! is_string($candidate['document_date'])) {
            return null;
        }

        $latestDateTies = $dated
            ->where('document_date', $candidate['document_date'])
            ->pluck('id')
            ->values();

        if ($latestDateTies->count() > 1) {
            return null;
        }

        return [
            'tenant_id' => $group['tenant_id'],
            'market_space_id' => $group['market_space_id'],
            'candidate_contract_id' => (int) $candidate['id'],
            'candidate_document_date' => $candidate['document_date'],
            'historical_contract_ids' => $sorted
                ->reject(static fn (array $item): bool => $item['id'] === $candidate['id'])
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->values()
                ->all(),
        ];
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
}
