<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantContract;
use App\Services\MarketSpaces\MarketSpaceTenantBindingRecorder;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Illuminate\Console\Command;

class SyncTenantContractBindingsCommand extends Command
{
    protected $signature = 'contracts:sync-bindings
        {--market= : Market ID (default: all)}
        {--limit=0 : Limit rows for testing}
        {--contract-id=* : Sync only selected tenant_contract IDs}
        {--service-shadowed-only : Only service docs shadowed by a primary contract on the same tenant/place}
        {--apply : Actually write binding changes (default: preview only)}';

    protected $description = 'Preview or apply contract binding resync through MarketSpaceTenantBindingRecorder.';

    public function handle(
        MarketSpaceTenantBindingRecorder $recorder,
        ContractDocumentClassifier $classifier,
    ): int {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $limit = max(0, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');
        $serviceShadowedOnly = (bool) $this->option('service-shadowed-only');
        $contractIds = collect((array) $this->option('contract-id'))
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->values();

        $query = TenantContract::query()
            ->orderBy('id');

        if ($marketId) {
            $query->where('market_id', $marketId);
        }

        if ($contractIds->isNotEmpty()) {
            $query->whereIn('id', $contractIds->all());
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $contracts = $query->get([
            'id',
            'market_id',
            'tenant_id',
            'market_space_id',
            'space_mapping_mode',
            'is_active',
            'status',
            'number',
        ]);

        $stats = [
            'mode' => $apply ? 'apply' : 'preview',
            'market_id' => $marketId,
            'total_scanned' => $contracts->count(),
            'eligible' => 0,
            'service_shadowed' => 0,
            'written' => 0,
            'skipped' => 0,
        ];

        $samples = [];
        $sampleLimit = 30;

        foreach ($contracts as $contract) {
            $classification = $classifier->classify((string) ($contract->number ?? ''));
            $isServiceShadowed = $this->isShadowedServiceDocument($contract, $classifier, $classification['category'] ?? null);

            if ($serviceShadowedOnly && ! $isServiceShadowed) {
                $stats['skipped']++;
                continue;
            }

            $stats['eligible']++;

            if ($isServiceShadowed) {
                $stats['service_shadowed']++;
            }

            $sample = [
                'contract_id' => (int) $contract->id,
                'tenant_id' => $contract->tenant_id !== null ? (int) $contract->tenant_id : null,
                'market_space_id' => $contract->market_space_id !== null ? (int) $contract->market_space_id : null,
                'mode' => (string) ($contract->space_mapping_mode ?? ''),
                'status' => (string) ($contract->status ?? ''),
                'is_active' => (bool) $contract->is_active,
                'category' => $classification['category'] ?? 'unknown',
                'number' => (string) ($contract->number ?? ''),
                'service_shadowed' => $isServiceShadowed,
                'applied' => $apply,
            ];

            if ($apply) {
                $recorder->syncFromContract($contract);
                $stats['written']++;
            }

            if (count($samples) < $sampleLimit) {
                $samples[] = $sample;
            }
        }

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function isShadowedServiceDocument(
        TenantContract $contract,
        ContractDocumentClassifier $classifier,
        ?string $category = null,
    ): bool {
        if (! $contract->tenant_id || ! $contract->market_space_id) {
            return false;
        }

        if (($category ?? 'unknown') !== 'service_document') {
            return false;
        }

        $siblings = TenantContract::query()
            ->where('market_id', (int) $contract->market_id)
            ->where('tenant_id', (int) $contract->tenant_id)
            ->where('market_space_id', (int) $contract->market_space_id)
            ->whereKeyNot($contract->getKey())
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->get(['id', 'number', 'space_mapping_mode']);

        foreach ($siblings as $sibling) {
            if ($sibling->excludesFromSpaceMapping()) {
                continue;
            }

            $siblingClassification = $classifier->classify((string) ($sibling->number ?? ''));
            if (($siblingClassification['category'] ?? null) === 'primary_contract') {
                return true;
            }
        }

        return false;
    }
}
