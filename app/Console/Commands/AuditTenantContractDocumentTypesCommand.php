<?php
# app/Console/Commands/AuditTenantContractDocumentTypesCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketIntegration;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Illuminate\Console\Command;

class AuditTenantContractDocumentTypesCommand extends Command
{
    protected $signature = 'contracts:audit-types
        {--market= : Market ID (default: market_id from active 1C integration)}
        {--limit=20 : Sample size for each category}
        {--all : Include matched contracts too (default: only unmatched non-inferred)}';

    protected $description = 'Read-only audit of imported 1C contract document types';

    public function handle(ContractDocumentClassifier $classifier): int
    {
        $marketId = $this->resolveMarketId();
        $limit = max(0, (int) $this->option('limit'));
        $includeAll = (bool) $this->option('all');

        $query = TenantContract::query()
            ->where('market_id', $marketId)
            ->orderBy('id');

        if (! $includeAll) {
            $query
                ->whereNull('market_space_id')
                ->where(static function ($subQuery): void {
                    $subQuery
                        ->whereNull('notes')
                        ->orWhere('notes', 'not like', '%inferred:single_space%');
                });
        }

        $contracts = $query->get([
            'id',
            'tenant_id',
            'external_id',
            'number',
            'market_space_id',
            'notes',
        ]);

        $stats = [
            'total' => $contracts->count(),
            'actionable' => 0,
            'by_category' => [
                'primary_contract' => 0,
                'supplemental_document' => 0,
                'service_document' => 0,
                'penalty_document' => 0,
                'non_rent_document' => 0,
                'placeholder_document' => 0,
                'unknown' => 0,
            ],
        ];

        $samples = [
            'primary_contract' => [],
            'supplemental_document' => [],
            'service_document' => [],
            'penalty_document' => [],
            'non_rent_document' => [],
            'placeholder_document' => [],
            'unknown' => [],
        ];

        foreach ($contracts as $contract) {
            $classification = $classifier->classify((string) ($contract->number ?? ''));
            $category = $classification['category'];

            $stats['by_category'][$category]++;

            if ($classification['actionable']) {
                $stats['actionable']++;
            }

            if ($limit === 0 || count($samples[$category]) < $limit) {
                $samples[$category][] = [
                    'id' => (int) $contract->id,
                    'tenant_id' => $contract->tenant_id !== null ? (int) $contract->tenant_id : null,
                    'external_id' => (string) ($contract->external_id ?? ''),
                    'number' => (string) ($contract->number ?? ''),
                    'place_token' => $classification['place_token'],
                    'document_date' => $classification['document_date'],
                    'label' => $classification['label'],
                    'matched_rule' => $classification['matched_rule'],
                ];
            }
        }

        $this->info("market_id={$marketId}");
        $this->info('scope=' . ($includeAll ? 'all_contracts' : 'unmatched_non_inferred'));
        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
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
