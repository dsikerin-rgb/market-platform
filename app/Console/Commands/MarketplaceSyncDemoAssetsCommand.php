<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketplaceMediaStorage;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MarketplaceSyncDemoAssetsCommand extends Command
{
    protected $signature = 'marketplace:sync-demo-assets
        {--directory=marketplace-demo-assets : Relative directory under storage/app/public to sync}
        {--dry-run : Run in dry-run mode}
        {--execute : Sync files into the marketplace media disk (default: dry-run)}';

    protected $description = 'Sync local demo assets into the marketplace media disk using the same relative paths';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $directory = trim((string) $this->option('directory'), '/');
        if ($directory === '') {
            $directory = 'marketplace-demo-assets';
        }

        $sourceRoot = storage_path('app/public/'.$directory);
        if (! is_dir($sourceRoot)) {
            $message = "Source directory not found: {$sourceRoot}";
            if ($dryRun) {
                $this->warn($message);
                $this->warn('DRY RUN: no files were synced. Use --execute to apply.');

                return self::SUCCESS;
            }

            $this->error($message);

            return self::FAILURE;
        }

        $filesystem = new Filesystem;
        $targetDisk = MarketplaceMediaStorage::disk();
        $synced = 0;

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($sourceRoot) + 1));

            if ($dryRun) {
                if ($file->getSize() <= 0) {
                    continue;
                }

                $synced++;

                continue;
            }

            $binary = $filesystem->get($absolutePath);
            if ($binary === '') {
                continue;
            }

            $mimeType = trim((string) @mime_content_type($absolutePath));
            $options = ['visibility' => 'public'];
            if ($mimeType !== '') {
                $options['ContentType'] = $mimeType;
            }

            Storage::disk($targetDisk)->put($directory.'/'.$relativePath, $binary, $options);
            $synced++;
        }

        if (! $dryRun) {
            MarketplaceMediaStorage::normalizeLocalPublicTreePermissions($directory);
        }

        $this->info(sprintf(
            '%s %d demo assets into %s.',
            $dryRun ? 'Would sync' : 'Synced',
            $synced,
            $targetDisk,
        ));

        if ($dryRun) {
            $this->warn('DRY RUN: no files were synced. Use --execute to apply.');
        }

        return self::SUCCESS;
    }
}
