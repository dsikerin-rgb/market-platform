<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketplaceAnnouncement;
use App\Support\MarketContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DedupeMarketplaceAnnouncementsCommand extends Command
{
    protected $signature = 'marketplace:announcements:dedupe
        {--market= : Market id}
        {--dry-run : Run in dry-run mode}
        {--execute : Delete duplicate rows (default: dry-run)}';

    protected $description = 'Remove duplicate marketplace announcements by market/kind/title/start-date';

    public function handle(): int
    {
        $marketId = $this->marketIdOption();
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($marketId === false) {
            $this->error('Market ID must be a positive integer.');

            return self::FAILURE;
        }

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        if ($execute && $marketId === null) {
            $this->error('Market ID is required with --execute. Use --market=1.');

            return self::FAILURE;
        }

        if ($marketId !== null) {
            return app(MarketContext::class)->withMarket(
                $marketId,
                fn (): int => $this->dedupeAnnouncements($marketId, $dryRun),
            );
        }

        return $this->dedupeAnnouncements(null, $dryRun);
    }

    private function dedupeAnnouncements(?int $marketId, bool $dryRun): int
    {

        $query = MarketplaceAnnouncement::query()
            ->selectRaw('market_id, kind, title, DATE(starts_at) as starts_on, COUNT(*) as duplicate_count')
            ->groupBy('market_id', 'kind', 'title', 'starts_on')
            ->havingRaw('COUNT(*) > 1');

        if ($marketId !== null) {
            $query->where('market_id', $marketId);
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

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied. Use --execute --market=... to delete duplicate rows.');
        }

        return self::SUCCESS;
    }

    private function marketIdOption(): int|false|null
    {
        $value = $this->option('market');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $marketId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($marketId) ? $marketId : false;
    }
}
