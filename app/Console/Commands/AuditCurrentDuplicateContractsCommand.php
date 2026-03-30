<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketIntegration;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Illuminate\Console\Command;

class AuditCurrentDuplicateContractsCommand extends Command
{
    protected $signature = 'contracts:audit-current-duplicates
        {--market= : Market ID (default: market_id from active 1C integration)}
        {--limit=20 : How many top groups to print}
        {--min-count=2 : Minimum contracts in a duplicate group}';

    protected $description = 'Read-only audit of current duplicate primary contracts grouped by tenant, place, place token and document date.';

    public function handle(ContractDocumentClassifier $classifier): int
    {
        $marketId = $this->resolveMarketId();
        $limit = max(0, (int) $this->option('limit'));
        $minCount = max(2, (int) $this->option('min-count'));

        $contracts = TenantContract::query()
            ->where('market_id', $marketId)
            ->whereNotNull('tenant_id')
            ->whereNotNull('market_space_id')
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->orderBy('id')
            ->get([
                'id',
                'external_id',
                'tenant_id',
                'market_space_id',
                'space_mapping_mode',
                'number',
                'status',
                'created_at',
                'updated_at',
            ]);

        $stats = [
            'market_id' => $marketId,
            'total_scanned' => $contracts->count(),
            'primary_considered' => 0,
            'duplicate_groups' => 0,
            'duplicate_rows' => 0,
            'groups_with_mixed_external_ids' => 0,
            'groups_with_missing_external_ids' => 0,
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

            $placeToken = trim((string) ($classification['place_token'] ?? ''));
            $documentDate = trim((string) ($classification['document_date'] ?? ''));

            if ($placeToken === '' || $documentDate === '') {
                continue;
            }

            $stats['primary_considered']++;

            $key = implode(':', [
                (int) $contract->tenant_id,
                (int) $contract->market_space_id,
                $placeToken,
                $documentDate,
            ]);

            $groups[$key] ??= [
                'tenant_id' => (int) $contract->tenant_id,
                'market_space_id' => (int) $contract->market_space_id,
                'place_token' => $placeToken,
                'document_date' => $documentDate,
                'contracts' => [],
            ];

            $groups[$key]['contracts'][] = [
                'id' => (int) $contract->id,
                'external_id' => $contract->external_id !== null ? (string) $contract->external_id : null,
                'number' => (string) ($contract->number ?? ''),
                'mode' => (string) ($contract->space_mapping_mode ?? ''),
                'status' => (string) ($contract->status ?? ''),
                'created_at' => optional($contract->created_at)->format('Y-m-d H:i:s'),
                'updated_at' => optional($contract->updated_at)->format('Y-m-d H:i:s'),
            ];
        }

        $prepared = collect($groups)
            ->filter(static fn (array $group): bool => count($group['contracts']) >= $minCount)
            ->map(function (array $group) use (&$stats): array {
                usort($group['contracts'], static function (array $left, array $right): int {
                    return $left['id'] <=> $right['id'];
                });

                $externalIds = collect($group['contracts'])
                    ->pluck('external_id')
                    ->filter(static fn (?string $value): bool => $value !== null && $value !== '')
                    ->unique()
                    ->values()
                    ->all();

                $missingExternalIdCount = collect($group['contracts'])
                    ->filter(static fn (array $item): bool => blank($item['external_id']))
                    ->count();

                if (count($externalIds) > 1) {
                    $stats['groups_with_mixed_external_ids']++;
                }

                if ($missingExternalIdCount > 0) {
                    $stats['groups_with_missing_external_ids']++;
                }

                $stats['duplicate_rows'] += count($group['contracts']);

                return [
                    'tenant_id' => $group['tenant_id'],
                    'market_space_id' => $group['market_space_id'],
                    'place_token' => $group['place_token'],
                    'document_date' => $group['document_date'],
                    'count' => count($group['contracts']),
                    'external_ids' => $externalIds,
                    'missing_external_id_count' => $missingExternalIdCount,
                    'contracts' => $group['contracts'],
                ];
            })
            ->sort(function (array $left, array $right): int {
                $countComparison = $right['count'] <=> $left['count'];
                if ($countComparison !== 0) {
                    return $countComparison;
                }

                $dateComparison = strcmp($right['document_date'], $left['document_date']);
                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return $left['market_space_id'] <=> $right['market_space_id'];
            })
            ->values();

        $stats['duplicate_groups'] = $prepared->count();

        $this->line(json_encode([
            'stats' => $stats,
            'groups' => $limit > 0 ? $prepared->take($limit)->all() : $prepared->all(),
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
}
