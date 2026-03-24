<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAnnouncement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupDuplicateAnnouncements extends Command
{
    protected $signature = 'marketplace:cleanup-duplicates {--market_id=1 : Market ID}';
    protected $description = 'Удалить дубликаты анонсов (старые записи с kind=event)';

    public function handle(): int
    {
        $marketId = (int) ($this->option('market_id') ?? 1);

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
                $new->update(['cover_image' => $old->cover_image]);
                $this->info("Перенесено изображение для: {$old->title}");
                $updated++;
            }
        }

        $this->info("Изображений перенесено: {$updated}");

        // Удалить старые дубликаты
        $deleted = MarketplaceAnnouncement::query()
            ->where('market_id', $marketId)
            ->where('kind', 'event')
            ->delete();

        $this->info("Удалено старых дубликатов: {$deleted}");

        return Command::SUCCESS;
    }
}
