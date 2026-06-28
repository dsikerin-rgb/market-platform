<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketplaceMediaStorage;
use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MarketplaceRepairDemoAssetPermissionsCommand extends Command
{
    protected $signature = 'marketplace:repair-demo-asset-permissions
        {--directory=marketplace-demo-assets : Relative directory under storage/app/public to normalize}
        {--dry-run : Run in dry-run mode}
        {--execute : Normalize local public storage permissions (default: dry-run)}';

    protected $description = 'Normalize demo asset permissions in local public storage so Laravel can serve them reliably';

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

        if ($dryRun) {
            $normalized = $this->countLocalPublicTreePaths($directory);
            $this->info(sprintf('Would normalize %d paths under %s.', $normalized, $directory));
            $this->warn('DRY RUN: no permissions were changed. Use --execute to apply.');

            return self::SUCCESS;
        }

        $normalized = MarketplaceMediaStorage::normalizeLocalPublicTreePermissions($directory);

        $this->info(sprintf('Normalized %d paths under %s.', $normalized, $directory));

        return self::SUCCESS;
    }

    private function countLocalPublicTreePaths(string $path): int
    {
        $value = trim($path, "/ \t\n\r\0\x0B");
        if ($value === '') {
            return 0;
        }

        $absolutePath = storage_path('app/public/'.ltrim($value, '/'));
        if (! file_exists($absolutePath)) {
            return 0;
        }

        if (is_file($absolutePath)) {
            return 1;
        }

        $count = 1;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $itemPath = $item->getPathname();
            if (! is_string($itemPath) || $itemPath === '') {
                continue;
            }

            $count++;
        }

        return $count;
    }
}
