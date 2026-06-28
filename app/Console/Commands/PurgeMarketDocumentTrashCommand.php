<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketDocument;
use App\Models\MarketDocumentActivityEvent;
use App\Support\MarketDocuments\MarketDocumentActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PurgeMarketDocumentTrashCommand extends Command
{
    protected $signature = 'market-documents:purge-trash
        {--days= : Override the trash retention period in days}
        {--dry-run : Run in dry-run mode}
        {--execute : Delete documents and files (default: dry-run)}';

    protected $description = 'Permanently delete market documents that stayed in trash longer than the retention period.';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $days = $this->retentionDays();

        if ($days < 1) {
            $this->error('Trash retention must be at least 1 day.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $query = MarketDocument::query()
            ->whereNotNull('archived_at')
            ->where('archived_at', '<=', $cutoff);

        if ($dryRun) {
            $this->info('Would delete: '.$query->count());
            $this->warn('DRY RUN: no documents or files were deleted. Use --execute to apply.');

            return self::SUCCESS;
        }

        $storage = Storage::disk(MarketDocument::storageDisk());
        $deleted = 0;
        $failed = 0;

        $query
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($storage, $days, $cutoff, &$deleted, &$failed): void {
                foreach ($documents as $document) {
                    if (! $document instanceof MarketDocument) {
                        continue;
                    }

                    try {
                        $path = trim((string) $document->file_path);

                        if ($path !== '' && $storage->exists($path)) {
                            $storage->delete($path);
                        }

                        app(MarketDocumentActivityLogger::class)->log(
                            $document,
                            MarketDocumentActivityEvent::ACTION_PURGED_BY_RETENTION,
                            null,
                            null,
                            [
                                'retention_days' => $days,
                                'cutoff' => $cutoff->toDateTimeString(),
                            ],
                        );

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
