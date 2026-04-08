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
        {--force : Re-download and regenerate assets even if cached files exist}';

    protected $description = 'Download and localize demo image banks into marketplace storage';

    public function handle(): int
    {
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
                MarketplaceDemoAssetLocalizer::localize($source, 'products/' . $profile, $force);
                $localized++;
            }
        } else {
            foreach (MarketplaceDemoAssets::productImageBanks() as $profileKey => $sources) {
                if ($limit > 0) {
                    $sources = array_slice($sources, 0, $limit);
                }

                foreach ($sources as $source) {
                    MarketplaceDemoAssetLocalizer::localize($source, 'products/' . $profileKey, $force);
                    $localized++;
                }
            }

            $showcaseSources = MarketplaceDemoAssets::showcaseImagePaths();
            if ($limit > 0) {
                $showcaseSources = array_slice($showcaseSources, 0, $limit);
            }

            foreach ($showcaseSources as $source) {
                MarketplaceDemoAssetLocalizer::localize($source, 'showcases/tenant', $force);
                $localized++;
            }
        }

        MarketplaceMediaStorage::normalizeLocalPublicTreePermissions((string) config('marketplace.demo_assets.directory', 'marketplace-demo-assets'));

        $this->info(sprintf('Demo assets warmed: %d', $localized));

        return self::SUCCESS;
    }
}
