<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketplaceProduct;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceAssignDemoImageCommand extends Command
{
    protected $signature = 'marketplace:assign-demo-image
        {--market= : Market id or slug}
        {--profile= : Demo profile key to target}
        {--path= : Image path to assign}
        {--limit=5 : Number of demo products to update}';

    protected $description = 'Assign a known-good image path to a limited set of demo products for quick visual repair';

    public function handle(): int
    {
        $market = $this->resolveMarket();
        if ($market === null) {
            $this->error('Market not found.');

            return self::FAILURE;
        }

        $profile = trim((string) $this->option('profile'));
        $path = trim((string) $this->option('path'));
        $limit = max(1, (int) $this->option('limit'));

        if ($profile === '') {
            $this->error('Option --profile is required.');

            return self::FAILURE;
        }

        if ($path === '') {
            $this->error('Option --path is required.');

            return self::FAILURE;
        }

        $products = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('is_demo', true)
            ->where('attributes->demo_profile', $profile)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'title', 'images']);

        if ($products->isEmpty()) {
            $this->warn('No matching demo products found.');

            return self::SUCCESS;
        }

        foreach ($products as $product) {
            $images = collect($product->images ?? [])
                ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
                ->values()
                ->all();

            $product->images = array_values(array_unique(array_merge([$path], $images)));
            $product->save();

            $this->line(sprintf('#%d %s', (int) $product->id, (string) $product->title));
        }

        $this->info(sprintf('Updated %d demo products in market %s.', $products->count(), (string) $market->slug));

        return self::SUCCESS;
    }

    private function resolveMarket(): ?Market
    {
        $raw = trim((string) $this->option('market'));
        if ($raw === '') {
            return null;
        }

        $query = Market::query()->where('is_active', true);

        if (is_numeric($raw)) {
            $query->whereKey((int) $raw);
        } else {
            $query->where('slug', $raw);
        }

        return $query->first();
    }
}
