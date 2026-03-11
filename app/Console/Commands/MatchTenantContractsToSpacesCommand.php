<?php
# app/Console/Commands/MatchTenantContractsToSpacesCommand.php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketIntegration;
use App\Models\MarketSpace;
use App\Models\TenantContract;
use App\Services\TenantContracts\ContractNumberSpaceMatcher;
use Illuminate\Console\Command;

class MatchTenantContractsToSpacesCommand extends Command
{
    protected $signature = 'contracts:match-spaces
        {--market= : Market ID (default: market_id from active 1C integration)}
        {--apply : Persist only exact unique matches}
        {--limit=15 : Sample size for each diagnostic bucket}';

    protected $description = 'Audit or backfill tenant_contracts.market_space_id from contract numbers using a strict matcher';

    public function handle(ContractNumberSpaceMatcher $matcher): int
    {
        $marketId = $this->resolveMarketId();
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));

        $contracts = TenantContract::query()
            ->where('market_id', $marketId)
            ->whereNull('market_space_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('space_mapping_mode')
                    ->orWhere('space_mapping_mode', TenantContract::SPACE_MAPPING_MODE_AUTO);
            })
            ->whereNotNull('tenant_id')
            ->whereNotNull('number')
            ->orderBy('id')
            ->get(['id', 'market_id', 'tenant_id', 'external_id', 'number', 'space_mapping_mode']);

        $spacesByTenant = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereNotNull('tenant_id')
            ->get(['id', 'tenant_id', 'number', 'code'])
            ->groupBy(static fn (MarketSpace $space): int => (int) $space->tenant_id);

        $stats = [
            'total' => $contracts->count(),
            'ok' => 0,
            'ambiguous' => 0,
            'not_found' => 0,
            'no_spaces' => 0,
            'updated' => 0,
        ];

        $samples = [
            'ok' => [],
            'ambiguous' => [],
            'not_found' => [],
            'no_spaces' => [],
        ];

        foreach ($contracts as $contract) {
            $tenantId = (int) $contract->tenant_id;
            $spaces = $spacesByTenant->get($tenantId);

            if (! $spaces || $spaces->isEmpty()) {
                $stats['no_spaces']++;
                if ($limit === 0 || count($samples['no_spaces']) < $limit) {
                    $samples['no_spaces'][] = $this->formatSample($contract);
                }
                continue;
            }

            $result = $matcher->match((string) $contract->number, $spaces);

            if ($result['state'] === 'ok') {
                $stats['ok']++;

                if ($limit === 0 || count($samples['ok']) < $limit) {
                    $samples['ok'][] = $this->formatSample($contract, [
                        'market_space_id' => $result['market_space_id'],
                        'matched_keys' => $result['matched_keys'],
                    ]);
                }

                if ($apply && $result['market_space_id'] !== null) {
                    $contract->market_space_id = $result['market_space_id'];
                    $contract->space_mapping_mode = TenantContract::SPACE_MAPPING_MODE_AUTO;
                    $contract->save();
                    $stats['updated']++;
                }

                continue;
            }

            if ($result['state'] === 'ambiguous') {
                $stats['ambiguous']++;
                if ($limit === 0 || count($samples['ambiguous']) < $limit) {
                    $samples['ambiguous'][] = $this->formatSample($contract, [
                        'candidate_ids' => $result['candidate_ids'],
                        'matched_keys' => $result['matched_keys'],
                    ]);
                }
                continue;
            }

            $stats['not_found']++;
            if ($limit === 0 || count($samples['not_found']) < $limit) {
                $samples['not_found'][] = $this->formatSample($contract);
            }
        }

        $this->info("market_id={$marketId}");
        $this->info('mode=' . ($apply ? 'apply' : 'dry-run'));
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

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function formatSample(TenantContract $contract, array $extra = []): array
    {
        return array_merge([
            'id' => (int) $contract->id,
            'tenant_id' => (int) $contract->tenant_id,
            'external_id' => (string) ($contract->external_id ?? ''),
            'number' => (string) ($contract->number ?? ''),
        ], $extra);
    }
}
