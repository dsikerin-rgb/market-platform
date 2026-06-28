<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketplaceProduct;
use App\Models\TenantShowcase;
use App\Models\TenantSpaceShowcase;
use App\Support\MarketContext;
use App\Support\MarketplaceDemoAssetLocalizer;
use App\Support\MarketplaceMediaStorage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketplaceLocalizeDemoAssetsCommand extends Command
{
    protected $signature = 'marketplace:localize-demo-assets
        {--market= : Market id or slug}
        {--limit=0 : Optional max demo products per market to process}
        {--dry-run : Run in dry-run mode}
        {--execute : Localize demo assets and update records (default: dry-run)}';

    protected $description = 'Localize existing demo product and showcase images into marketplace storage';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        if ($execute && trim((string) $this->option('market')) === '') {
            $this->error('Market ID or slug is required with --execute. Use --market=1.');

            return self::FAILURE;
        }

        $markets = $this->resolveMarkets();
        if ($markets->isEmpty()) {
            $this->warn('No active markets found for demo asset localization.');

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));

        foreach ($markets as $market) {
            $this->line('');
            $this->info(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            $stats = app(MarketContext::class)->withMarket(
                (int) $market->id,
                fn (): array => $this->localizeMarketDemoAssets($market, $limit, $dryRun),
            );

            $this->line(sprintf(
                '  demo products %s: %d',
                $dryRun ? 'would update' : 'updated',
                $stats['products'],
            ));
            $this->line(sprintf(
                '  tenant showcases %s: %d',
                $dryRun ? 'would update' : 'updated',
                $stats['tenant_showcases'],
            ));
            $this->line(sprintf(
                '  space showcases %s: %d',
                $dryRun ? 'would update' : 'updated',
                $stats['space_showcases'],
            ));
        }

        if (! $dryRun) {
            MarketplaceMediaStorage::normalizeLocalPublicTreePermissions((string) config('marketplace.demo_assets.directory', 'marketplace-demo-assets'));
        }

        $this->line('');
        $this->info($dryRun ? 'Demo asset localization dry-run completed.' : 'Demo asset localization completed.');

        if ($dryRun) {
            $this->warn('DRY RUN: no files or records were changed. Use --execute --market=... to apply.');
        }

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

    /**
     * @return array{products:int,tenant_showcases:int,space_showcases:int}
     */
    private function localizeMarketDemoAssets(Market $market, int $limit, bool $dryRun): array
    {
        return [
            'products' => $this->localizeDemoProducts($market, $limit, $dryRun),
            'tenant_showcases' => $this->localizeTenantShowcases($market, $dryRun),
            'space_showcases' => $this->localizeSpaceShowcases($market, $dryRun),
        ];
    }

    private function localizeDemoProducts(Market $market, int $limit, bool $dryRun): int
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

            $localizedImages = $dryRun
                ? $images->all()
                : $images
                    ->map(fn (string $path): string => MarketplaceDemoAssetLocalizer::localize($path, 'products/'.$profileKey))
                    ->values()
                    ->all();

            if ($dryRun && ! $this->hasLocalizableSource($images)) {
                continue;
            }

            if (! $dryRun && $localizedImages === $images->all()) {
                continue;
            }

            if (! $dryRun) {
                $product->forceFill(['images' => $localizedImages])->save();
            }

            $updated++;
        }

        return $updated;
    }

    private function localizeTenantShowcases(Market $market, bool $dryRun): int
    {
        $updated = 0;

        TenantShowcase::query()
            ->where('is_demo', true)
            ->whereHas('tenant', function (Builder $query) use ($market): void {
                $query->where('market_id', (int) $market->id);
            })
            ->orderBy('id')
            ->chunkById(50, function (Collection $showcases) use (&$updated, $dryRun): void {
                foreach ($showcases as $showcase) {
                    $photos = collect($showcase->photos ?? [])
                        ->filter(static fn ($path): bool => is_string($path) && trim($path) !== '')
                        ->values();

                    if ($photos->isEmpty()) {
                        continue;
                    }

                    $localizedPhotos = $dryRun
                        ? $photos->all()
                        : $photos
                            ->map(fn (string $path): string => MarketplaceDemoAssetLocalizer::localize($path, 'showcases/tenant'))
                            ->values()
                            ->all();

                    if ($dryRun && ! $this->hasLocalizableSource($photos)) {
                        continue;
                    }

                    if (! $dryRun && $localizedPhotos === $photos->all()) {
                        continue;
                    }

                    if (! $dryRun) {
                        $showcase->forceFill(['photos' => $localizedPhotos])->save();
                    }

                    $updated++;
                }
            });

        return $updated;
    }

    private function localizeSpaceShowcases(Market $market, bool $dryRun): int
    {
        $updated = 0;

        TenantSpaceShowcase::query()
            ->where('market_id', (int) $market->id)
            ->where('is_demo', true)
            ->orderBy('id')
            ->chunkById(50, function (Collection $showcases) use (&$updated, $dryRun): void {
                foreach ($showcases as $showcase) {
                    $photos = collect($showcase->photos ?? [])
                        ->filter(static fn ($path): bool => is_string($path) && trim($path) !== '')
                        ->values();

                    if ($photos->isEmpty()) {
                        continue;
                    }

                    $localizedPhotos = $dryRun
                        ? $photos->all()
                        : $photos
                            ->map(fn (string $path): string => MarketplaceDemoAssetLocalizer::localize($path, 'showcases/space'))
                            ->values()
                            ->all();

                    if ($dryRun && ! $this->hasLocalizableSource($photos)) {
                        continue;
                    }

                    if (! $dryRun && $localizedPhotos === $photos->all()) {
                        continue;
                    }

                    if (! $dryRun) {
                        $showcase->forceFill(['photos' => $localizedPhotos])->save();
                    }

                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * @param  Collection<int, string>  $paths
     */
    private function hasLocalizableSource(Collection $paths): bool
    {
        if (! (bool) config('marketplace.demo_assets.localize', false)) {
            return false;
        }

        return $paths->contains(
            static fn (string $path): bool => Str::startsWith(trim($path), ['http://', 'https://', '/'])
        );
    }
}
