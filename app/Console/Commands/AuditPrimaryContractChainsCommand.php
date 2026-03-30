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
        {--include-test : Include TEST/placeholder contract numbers in output}';

    protected $description = 'Read-only audit of primary contract chains on the same tenant/place using document dates from contract numbers.';

    public function handle(ContractDocumentClassifier $classifier): int
    {
        $marketId = $this->resolveMarketId();
        $limit = max(0, (int) $this->option('limit'));
        $minCount = max(2, (int) $this->option('min-count'));
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

                $sorted = $contracts
                    ->sortByDesc([
                        static fn (array $item): int => $item['document_date'] !== null ? 1 : 0,
                        static fn (array $item): string => $item['document_date'] ?? '',
                        static fn (array $item): int => $item['id'],
                    ])
                    ->values();

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

                return [
                    'tenant_id' => $group['tenant_id'],
                    'market_space_id' => $group['market_space_id'],
                    'count' => $contracts->count(),
                    'candidate_contract_id' => $candidate['id'] ?? null,
                    'candidate_document_date' => $candidate['document_date'] ?? null,
                    'has_test_noise' => $hasTestNoise,
                    'contracts' => $sorted->all(),
                ];
            })
            ->filter()
            ->sortByDesc([
                static fn (array $group): int => $group['count'],
                static fn (array $group): string => $group['candidate_document_date'] ?? '',
                static fn (array $group): int => $group['tenant_id'],
                static fn (array $group): int => $group['market_space_id'],
            ])
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
}
