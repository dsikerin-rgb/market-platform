<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAnnouncement;
use App\Support\MarketContext;
use Illuminate\Console\Command;

class CleanupDuplicateAnnouncements extends Command
{
    protected $signature = 'marketplace:cleanup-duplicates
                            {--market_id=1 : Market ID}
                            {--dry-run : Run in dry-run mode}
                            {--execute : Apply changes (default: dry-run)}';

    protected $description = 'Удалить дубликаты анонсов (старые записи с kind=event)';

    public function handle(): int
    {
        $marketId = (int) ($this->option('market_id') ?? 1);
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        return app(MarketContext::class)->withMarket(
            $marketId,
            fn (): int => $this->cleanupDuplicates($marketId, $dryRun),
        );
    }

    private function cleanupDuplicates(int $marketId, bool $dryRun): int
    {
        // Найти старые записи (kind=event) с cover_image
        $oldWithImages = MarketplaceAnnouncement::query()
            ->where('market_id', $marketId)
            ->where('kind', 'event')
            ->whereNotNull('cover_image')
            ->where('cover_image', '!=', '')
            ->get();

        $updated = 0;
        foreach ($oldWithImages as $old) {
            // Найти новую запись с тем же title и датой
            $new = MarketplaceAnnouncement::query()
                ->where('market_id', $marketId)
                ->where('kind', '!=', 'event')
                ->where('title', $old->title)
                ->whereDate('starts_at', $old->starts_at)
                ->first();

            if ($new && empty($new->cover_image)) {
                if (! $dryRun) {
                    $new->update(['cover_image' => $old->cover_image]);
                }

                $this->info("Перенесено изображение для: {$old->title}");
                $updated++;
            }
        }

        $this->info(($dryRun ? 'К переносу изображений' : 'Изображений перенесено').": {$updated}");

        // Удалить старые дубликаты
        $duplicatesQuery = MarketplaceAnnouncement::query()
            ->where('market_id', $marketId)
            ->where('kind', 'event');

        $deleted = $dryRun ? $duplicatesQuery->count() : $duplicatesQuery->delete();

        $this->info(($dryRun ? 'К удалению старых дубликатов' : 'Удалено старых дубликатов').": {$deleted}");

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied. Use --execute to apply.');
        }

        return Command::SUCCESS;
    }
}
