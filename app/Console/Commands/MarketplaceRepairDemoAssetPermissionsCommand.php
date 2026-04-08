<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MarketplaceMediaStorage;
use Illuminate\Console\Command;

class MarketplaceRepairDemoAssetPermissionsCommand extends Command
{
    protected $signature = 'marketplace:repair-demo-asset-permissions
        {--directory=marketplace-demo-assets : Relative directory under storage/app/public to normalize}';

    protected $description = 'Normalize demo asset permissions in local public storage so Laravel can serve them reliably';

    public function handle(): int
    {
        $directory = trim((string) $this->option('directory'), '/');
        if ($directory === '') {
            $directory = 'marketplace-demo-assets';
        }

        $normalized = MarketplaceMediaStorage::normalizeLocalPublicTreePermissions($directory);

        $this->info(sprintf('Normalized %d paths under %s.', $normalized, $directory));

        return self::SUCCESS;
    }
}
