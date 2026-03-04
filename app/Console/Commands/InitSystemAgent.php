<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Support\SystemAgentService;
use Illuminate\Console\Command;

class InitSystemAgent extends Command
{
    protected $signature = 'market:system-agent:init
        {--market_id= : Only one market id}
        {--all : Process all markets}
        {--execute : Apply changes}
        {--dry-run : Show what would be changed without writing (default mode)}';

    protected $description = 'Create or validate per-market System Agent users (idempotent and safe).';

    public function handle(SystemAgentService $service): int
    {
        $marketIds = $this->resolveMarketIds();
        if ($marketIds === []) {
            $this->warn('No markets selected.');

            return Command::SUCCESS;
        }

        $dryRun = ! (bool) $this->option('execute');
        if ((bool) $this->option('dry-run')) {
            $dryRun = true;
        }
        $this->info($dryRun ? 'mode=DRY-RUN' : 'mode=EXECUTE');

        $created = 0;
        $exists = 0;
        $conflicts = 0;
        $wouldCreate = 0;

        foreach ($marketIds as $marketId) {
            $result = $service->ensureForMarket($marketId, ! $dryRun);

            $status = (string) ($result['status'] ?? 'unknown');
            $message = (string) ($result['message'] ?? '');
            $this->line("[market={$marketId}] {$status}: {$message}");

            if ($status === 'created') {
                $created++;
            } elseif ($status === 'exists') {
                $exists++;
            } elseif ($status === 'would_create') {
                $wouldCreate++;
            } elseif ($status === 'conflict') {
                $conflicts++;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Summary: created=%d exists=%d would_create=%d conflicts=%d',
            $created,
            $exists,
            $wouldCreate,
            $conflicts,
        ));

        return $conflicts > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function resolveMarketIds(): array
    {
        $marketIdOption = $this->option('market_id');

        if (filled($marketIdOption) && is_numeric($marketIdOption)) {
            return [(int) $marketIdOption];
        }

        if ((bool) $this->option('all') || ! filled($marketIdOption)) {
            return Market::query()
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
        }

        return [];
    }
}
