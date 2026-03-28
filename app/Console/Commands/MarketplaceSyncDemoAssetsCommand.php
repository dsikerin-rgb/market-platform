<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketplaceMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class MarketplaceSyncDemoAssetsCommand extends Command
{
    protected $signature = 'marketplace:sync-demo-assets
        {--directory=marketplace-demo-assets : Relative directory under storage/app/public to sync}';

    protected $description = 'Sync local demo assets into the marketplace media disk using the same relative paths';

    public function handle(): int
    {
        $directory = trim((string) $this->option('directory'), '/');
        if ($directory === '') {
            $directory = 'marketplace-demo-assets';
        }

        $sourceRoot = storage_path('app/public/' . $directory);
        if (! is_dir($sourceRoot)) {
            $this->error("Source directory not found: {$sourceRoot}");

            return self::FAILURE;
        }

        $filesystem = new Filesystem();
        $targetDisk = MarketplaceMediaStorage::disk();
        $synced = 0;

        /** @var \SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($sourceRoot) + 1));
            $binary = $filesystem->get($absolutePath);
            if ($binary === '') {
                continue;
            }

            $mimeType = trim((string) @mime_content_type($absolutePath));
            $options = ['visibility' => 'public'];
            if ($mimeType !== '') {
                $options['ContentType'] = $mimeType;
            }

            Storage::disk($targetDisk)->put($directory . '/' . $relativePath, $binary, $options);
            $synced++;
        }

        $this->info("Synced {$synced} demo assets into {$targetDisk}.");

        return self::SUCCESS;
    }
}
