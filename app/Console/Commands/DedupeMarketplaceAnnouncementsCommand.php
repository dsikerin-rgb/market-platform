<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketplaceAnnouncement;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class DedupeMarketplaceAnnouncementsCommand extends Command
{
    protected $signature = 'marketplace:announcements:dedupe
        {--market= : Market id}
        {--dry-run : Only show what would be deleted}';

    protected $description = 'Remove duplicate marketplace announcements by market/kind/title/start-date';

    public function handle(): int
    {
        $marketId = $this->option('market');
        $dryRun = (bool) $this->option('dry-run');

        $query = MarketplaceAnnouncement::query()
            ->selectRaw('market_id, kind, title, DATE(starts_at) as starts_on, COUNT(*) as duplicate_count')
            ->groupBy('market_id', 'kind', 'title', 'starts_on')
            ->havingRaw('COUNT(*) > 1');

        if (is_numeric($marketId)) {
            $query->where('market_id', (int) $marketId);
        }

        /** @var Collection<int, object> $groups */
        $groups = $query->get();

        if ($groups->isEmpty()) {
            $this->info('No duplicates found.');
            return self::SUCCESS;
        }

        $totalDeleted = 0;
        $totalGroups = 0;

        foreach ($groups as $group) {
            $ids = MarketplaceAnnouncement::query()
                ->where('market_id', (int) $group->market_id)
                ->where('kind', (string) $group->kind)
                ->where('title', (string) $group->title)
                ->whereDate('starts_at', (string) $group->starts_on)
                ->orderByDesc('id')
                ->pluck('id')
                ->all();

            if (count($ids) <= 1) {
                continue;
            }

            $keepId = (int) array_shift($ids);
            $deleteIds = array_map('intval', $ids);

            $this->line(sprintf(
                '[group] market=%d kind=%s date=%s keep=%d delete=%s',
                (int) $group->market_id,
                (string) $group->kind,
                (string) $group->starts_on,
                $keepId,
                implode(',', $deleteIds),
            ));

            if (! $dryRun) {
                $deleted = MarketplaceAnnouncement::query()
                    ->whereIn('id', $deleteIds)
                    ->delete();
                $totalDeleted += (int) $deleted;
            } else {
                $totalDeleted += count($deleteIds);
            }

            $totalGroups++;
        }

        $this->info(sprintf(
            '%s duplicates processed: groups=%d rows=%d',
            $dryRun ? 'Dry-run' : 'Done',
            $totalGroups,
            $totalDeleted,
        ));

        return self::SUCCESS;
    }
}
