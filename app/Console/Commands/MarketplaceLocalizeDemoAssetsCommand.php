<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketplaceProduct;
use App\Models\TenantShowcase;
use App\Models\TenantSpaceShowcase;
use App\Support\MarketplaceDemoAssetLocalizer;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MarketplaceLocalizeDemoAssetsCommand extends Command
{
    protected $signature = 'marketplace:localize-demo-assets
        {--market= : Market id or slug}
        {--limit=0 : Optional max demo products per market to process}';

    protected $description = 'Localize existing demo product and showcase images into marketplace storage';

    public function handle(): int
    {
        $markets = $this->resolveMarkets();
        if ($markets->isEmpty()) {
            $this->warn('No active markets found for demo asset localization.');

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));

        foreach ($markets as $market) {
            $this->line('');
            $this->info(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            $productsUpdated = $this->localizeDemoProducts($market, $limit);
            $showcasesUpdated = $this->localizeTenantShowcases($market);
            $spaceShowcasesUpdated = $this->localizeSpaceShowcases($market);

            $this->line("  demo products updated: {$productsUpdated}");
            $this->line("  tenant showcases updated: {$showcasesUpdated}");
            $this->line("  space showcases updated: {$spaceShowcasesUpdated}");
        }

        MarketplaceMediaStorage::normalizeLocalPublicTreePermissions((string) config('marketplace.demo_assets.directory', 'marketplace-demo-assets'));

        $this->line('');
        $this->info('Demo asset localization completed.');

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Market>
     */
    private function resolveMarkets(): Collection
    {
        $raw = trim((string) $this->option('market'));
        if ($raw === '') {
            return Market::query()->where('is_active', true)->orderBy('id')->get();
        }

        $query = Market::query()->where('is_active', true);
        if (is_numeric($raw)) {
            $query->whereKey((int) $raw);
        } else {
            $query->where('slug', $raw);
        }

        return $query->get();
    }

    private function localizeDemoProducts(Market $market, int $limit): int
    {
        $updated = 0;

        $query = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('is_demo', true)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        /** @var Collection<int, MarketplaceProduct> $products */
        $products = $query->get();

        foreach ($products as $product) {
            $images = collect($product->images ?? [])
                ->filter(static fn ($path): bool => is_string($path) && trim($path) !== '')
                ->values();

            if ($images->isEmpty()) {
                continue;
            }

            $profileKey = trim((string) data_get($product->attributes, 'demo_profile', 'default'));
            if ($profileKey === '') {
                $profileKey = 'default';
            }

            $localizedImages = $images
                ->map(fn (string $path): string => MarketplaceDemoAssetLocalizer::localize($path, 'products/' . $profileKey))
                ->values()
                ->all();

            if ($localizedImages === $images->all()) {
                continue;
            }

            $product->forceFill(['images' => $localizedImages])->save();
            $updated++;
        }

        return $updated;
    }

    private function localizeTenantShowcases(Market $market): int
    {
        $updated = 0;

        TenantShowcase::query()
            ->where('is_demo', true)
            ->whereHas('tenant', function (Builder $query) use ($market): void {
                $query->where('market_id', (int) $market->id);
            })
            ->orderBy('id')
            ->chunkById(50, function (Collection $showcases) use (&$updated): void {
                foreach ($showcases as $showcase) {
                    $photos = collect($showcase->photos ?? [])
                        ->filter(static fn ($path): bool => is_string($path) && trim($path) !== '')
                        ->values();

                    if ($photos->isEmpty()) {
                        continue;
                    }

                    $localizedPhotos = $photos
                        ->map(fn (string $path): string => MarketplaceDemoAssetLocalizer::localize($path, 'showcases/tenant'))
                        ->values()
                        ->all();

                    if ($localizedPhotos === $photos->all()) {
                        continue;
                    }

                    $showcase->forceFill(['photos' => $localizedPhotos])->save();
                    $updated++;
                }
            });

        return $updated;
    }

    private function localizeSpaceShowcases(Market $market): int
    {
        $updated = 0;

        TenantSpaceShowcase::query()
            ->where('market_id', (int) $market->id)
            ->where('is_demo', true)
            ->orderBy('id')
            ->chunkById(50, function (Collection $showcases) use (&$updated): void {
                foreach ($showcases as $showcase) {
                    $photos = collect($showcase->photos ?? [])
                        ->filter(static fn ($path): bool => is_string($path) && trim($path) !== '')
                        ->values();

                    if ($photos->isEmpty()) {
                        continue;
                    }

                    $localizedPhotos = $photos
                        ->map(fn (string $path): string => MarketplaceDemoAssetLocalizer::localize($path, 'showcases/space'))
                        ->values()
                        ->all();

                    if ($localizedPhotos === $photos->all()) {
                        continue;
                    }

                    $showcase->forceFill(['photos' => $localizedPhotos])->save();
                    $updated++;
                }
            });

        return $updated;
    }
}
