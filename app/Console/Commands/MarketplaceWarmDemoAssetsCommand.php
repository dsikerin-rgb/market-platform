<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketplaceDemoAssetLocalizer;
use App\Support\MarketplaceDemoAssets;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Console\Command;

class MarketplaceWarmDemoAssetsCommand extends Command
{
    protected $signature = 'marketplace:warm-demo-assets
        {--profile= : Restrict product assets to a single profile key}
        {--limit=0 : Limit number of sources per product profile}
        {--force : Re-download and regenerate assets even if cached files exist}
        {--dry-run : Run in dry-run mode}
        {--execute : Download and localize demo assets (default: dry-run)}';

    protected $description = 'Download and localize demo image banks into marketplace storage';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        $profile = trim((string) $this->option('profile'));
        $limit = max(0, (int) $this->option('limit'));
        $force = (bool) $this->option('force');
        $localized = 0;

        if ($profile !== '') {
            $sources = MarketplaceDemoAssets::imagePaths($profile);
            if ($limit > 0) {
                $sources = array_slice($sources, 0, $limit);
            }
            foreach ($sources as $source) {
                if (! $dryRun) {
                    MarketplaceDemoAssetLocalizer::localize($source, 'products/'.$profile, $force);
                }

                $localized++;
            }
        } else {
            foreach (MarketplaceDemoAssets::productImageBanks() as $profileKey => $sources) {
                if ($limit > 0) {
                    $sources = array_slice($sources, 0, $limit);
                }

                foreach ($sources as $source) {
                    if (! $dryRun) {
                        MarketplaceDemoAssetLocalizer::localize($source, 'products/'.$profileKey, $force);
                    }

                    $localized++;
                }
            }

            $showcaseSources = MarketplaceDemoAssets::showcaseImagePaths();
            if ($limit > 0) {
                $showcaseSources = array_slice($showcaseSources, 0, $limit);
            }

            foreach ($showcaseSources as $source) {
                if (! $dryRun) {
                    MarketplaceDemoAssetLocalizer::localize($source, 'showcases/tenant', $force);
                }

                $localized++;
            }
        }

        if (! $dryRun) {
            MarketplaceMediaStorage::normalizeLocalPublicTreePermissions((string) config('marketplace.demo_assets.directory', 'marketplace-demo-assets'));
        }

        if ($dryRun) {
            $this->info(sprintf('Would warm %d demo assets.', $localized));
            $this->warn('DRY RUN: no assets were downloaded or localized. Use --execute to apply.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Demo assets warmed: %d', $localized));

        return self::SUCCESS;
    }
}
