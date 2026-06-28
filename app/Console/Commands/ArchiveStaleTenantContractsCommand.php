<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantContract;
use App\Support\MarketContext;
use App\Support\TenantContracts\TenantContractOperationalActivity;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class ArchiveStaleTenantContractsCommand extends Command
{
    protected $signature = 'tenant-contracts:archive-stale
        {--market= : Market ID (default: all)}
        {--months=2 : Stale threshold in months from latest accrual period}
        {--limit=50 : Sample limit for output}
        {--contract-id=* : Archive only selected tenant_contract IDs}
        {--apply : Actually archive contracts (default: dry-run)}';

    protected $description = 'Archive active tenant contracts that have no recent 1C accruals or payments.';

    public function handle(TenantContractOperationalActivity $activity): int
    {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $months = max(0, (int) $this->option('months'));
        $limit = max(0, (int) $this->option('limit'));
        $apply = (bool) $this->option('apply');
        $contractIds = collect((array) $this->option('contract-id'))
            ->map(static fn (mixed $value): int => (int) $value)
            ->filter(static fn (int $value): bool => $value > 0)
            ->values();

        if ($marketId !== null) {
            return app(MarketContext::class)->withMarket(
                $marketId,
                fn (): int => $this->archiveStaleContracts($activity, $marketId, $months, $limit, $apply, $contractIds),
            );
        }

        return $this->archiveStaleContracts($activity, null, $months, $limit, $apply, $contractIds);
    }

    /**
     * @param  Collection<int, int>  $contractIds
     */
    private function archiveStaleContracts(
        TenantContractOperationalActivity $activity,
        ?int $marketId,
        int $months,
        int $limit,
        bool $apply,
        Collection $contractIds,
    ): int {
        $query = TenantContract::query()
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived', 'cancelled'])
            ->orderBy('id');

        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        if ($contractIds->isNotEmpty()) {
            $query->whereIn('id', $contractIds->all());
        }

        $stats = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'market_id' => $marketId,
            'months_without_activity' => $months,
            'scanned' => 0,
            'eligible_for_archive' => 0,
            'archived' => 0,
            'skipped' => 0,
        ];

        $samples = [];

        $query->chunkById(500, function ($contracts) use ($activity, $months, $apply, $limit, &$stats, &$samples): void {
            foreach ($contracts as $contract) {
                $stats['scanned']++;

                if (! $activity->shouldArchiveAsStale($contract, $months)) {
                    $stats['skipped']++;

                    continue;
                }

                $stats['eligible_for_archive']++;

                $sample = [
                    'contract_id' => (int) $contract->id,
                    'market_id' => (int) $contract->market_id,
                    'tenant_id' => (int) $contract->tenant_id,
                    'market_space_id' => $contract->market_space_id !== null ? (int) $contract->market_space_id : null,
                    'number' => (string) ($contract->number ?? ''),
                    'status_before' => (string) ($contract->status ?? ''),
                    'starts_at' => $contract->starts_at?->format('Y-m-d'),
                    'signed_at' => $contract->signed_at?->format('Y-m-d'),
                    'applied' => $apply,
                ];

                if ($apply) {
                    $contract->status = 'archived';
                    $contract->is_active = false;
                    $contract->notes = $this->appendArchiveNote((string) ($contract->notes ?? ''));
                    $contract->save();

                    $stats['archived']++;
                    $sample['status_after'] = 'archived';
                }

                if ($limit === 0 || count($samples) < $limit) {
                    $samples[] = $sample;
                }
            }
        });

        $this->line(json_encode([
            'stats' => $stats,
            'samples' => $samples,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function appendArchiveNote(string $notes): string
    {
        $line = 'Auto archived as stale: no recent 1C accruals or payments.';
        $notes = trim($notes);

        if ($notes === '') {
            return $line;
        }

        if (str_contains($notes, $line)) {
            return $notes;
        }

        return $notes.PHP_EOL.$line;
    }
}
