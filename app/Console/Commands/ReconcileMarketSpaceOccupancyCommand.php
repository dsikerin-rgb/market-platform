<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketSpace;
use Illuminate\Console\Command;

class ReconcileMarketSpaceOccupancyCommand extends Command
{
    protected $signature = 'market-spaces:reconcile-occupancy
        {--market-id= : Limit reconciliation to one market}
        {--apply : Persist changes instead of showing a dry run}';

    protected $description = 'Repair orphan child spaces and align occupied/vacant statuses with effective occupancy.';

    public function handle(): int
    {
        $marketId = (int) ($this->option('market-id') ?? 0);
        $apply = (bool) $this->option('apply');

        $query = MarketSpace::query()
            ->with('spaceGroupParent:id,tenant_id,space_group_role')
            ->orderBy('id');

        if ($marketId > 0) {
            $query->where('market_id', $marketId);
        }

        $total = 0;
        $spacesChanged = 0;
        $orphanChildrenFixed = 0;
        $statusesFixed = 0;

        $query->chunkById(200, function ($spaces) use (
            $apply,
            &$total,
            &$spacesChanged,
            &$orphanChildrenFixed,
            &$statusesFixed,
        ): void {
            foreach ($spaces as $space) {
                $total++;

                $updates = $space->occupancyConsistencyUpdates();
                if ($updates === []) {
                    continue;
                }

                $spacesChanged++;

                if (array_key_exists('space_group_role', $updates)) {
                    $orphanChildrenFixed++;
                }

                if (array_key_exists('status', $updates)) {
                    $statusesFixed++;
                }

                if (! $apply) {
                    continue;
                }

                $space->forceFill($updates)->save();
            }
        });

        $mode = $apply ? 'apply' : 'dry-run';

        $this->info("Mode: {$mode}");
        $this->line("Checked spaces: {$total}");
        $this->line("Spaces to change: {$spacesChanged}");
        $this->line("Orphan child fixes: {$orphanChildrenFixed}");
        $this->line("Status fixes: {$statusesFixed}");

        return self::SUCCESS;
    }
}
