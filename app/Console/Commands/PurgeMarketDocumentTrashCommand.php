<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeMarketDocumentTrashCommand extends Command
{
    protected $signature = 'market-documents:purge-trash
        {--days= : Override the trash retention period in days}
        {--dry-run : Count files without deleting them}';

    protected $description = 'Permanently delete market documents that stayed in trash longer than the retention period.';

    public function handle(): int
    {
        $days = $this->retentionDays();

        if ($days < 1) {
            $this->error('Trash retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = MarketDocument::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<=', $cutoff);

        if ($this->option('dry-run')) {
            $this->info((string) $query->count());

            return self::SUCCESS;
        }

        $storage = Storage::disk(MarketDocument::storageDisk());
        $deleted = 0;
        $failed = 0;

        $query
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($storage, &$deleted, &$failed): void {
                foreach ($documents as $document) {
                    if (! $document instanceof MarketDocument) {
                        continue;
                    }

                    try {
                        $path = trim((string) $document->file_path);

                        if ($path !== '' && $storage->exists($path)) {
                            $storage->delete($path);
                        }

                        $document->delete();
                        $deleted++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->warn("Document {$document->id}: {$exception->getMessage()}");
                    }
                }
            });

        $this->info("Deleted: {$deleted}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function retentionDays(): int
    {
        $option = $this->option('days');

        if (is_numeric($option)) {
            return (int) $option;
        }

        return (int) config('market_documents.trash_retention_days', 7);
    }
}
