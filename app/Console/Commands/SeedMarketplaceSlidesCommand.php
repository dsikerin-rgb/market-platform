<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketplaceSlide;
use App\Support\MarketContext;
use App\Support\MarketplaceDefaultSlideCatalog;
use Illuminate\Console\Command;

class SeedMarketplaceSlidesCommand extends Command
{
    protected $signature = 'marketplace:slides:seed-defaults
        {--market= : Market ID}
        {--overwrite : Update existing slides}
        {--dry-run : Run in dry-run mode}
        {--execute : Apply changes (default: dry-run)}';

    protected $description = 'Seed default marketplace info slides for the selected market.';

    public function handle(): int
    {
        $marketId = $this->marketIdOption();
        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute || (bool) $this->option('dry-run');

        if ($marketId === false) {
            $this->error('Market ID must be a positive integer.');

            return self::FAILURE;
        }

        if ($execute && (bool) $this->option('dry-run')) {
            $this->error('Use either --execute or --dry-run, not both.');

            return self::FAILURE;
        }

        if ($execute && $marketId === null) {
            $this->error('Market ID is required with --execute. Use --market=1.');

            return self::FAILURE;
        }

        $marketQuery = Market::query()->orderBy('id');

        if ($marketId !== null) {
            $marketQuery->whereKey($marketId);
        }

        $markets = $marketQuery->get();

        if ($markets->isEmpty()) {
            $this->warn('No markets found.');

            return self::SUCCESS;
        }

        $overwrite = (bool) $this->option('overwrite');
        $created = 0;
        $updated = 0;

        foreach ($markets as $market) {
            [$marketCreated, $marketUpdated] = app(MarketContext::class)->withMarket(
                (int) $market->id,
                fn (): array => $this->seedDefaultsForMarket($market, $overwrite, $dryRun),
            );

            $created += $marketCreated;
            $updated += $marketUpdated;
        }

        $this->info(($dryRun ? 'Marketplace slides dry-run.' : 'Marketplace slides seeded.').' created='.$created.' updated='.$updated);

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied. Use --execute --market=... to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function seedDefaultsForMarket(Market $market, bool $overwrite, bool $dryRun): array
    {
        $created = 0;
        $updated = 0;
        $settings = (array) (($market->settings ?? [])['marketplace'] ?? []);
        $defaults = MarketplaceDefaultSlideCatalog::defaultsForMarket($market, $settings);

        foreach ($defaults as $row) {
            $attributes = [
                'market_id' => (int) $market->id,
                'placement' => 'home_info_carousel',
                'title' => (string) $row['title'],
            ];

            $values = [
                'badge' => $row['badge'] ?? null,
                'description' => $row['description'] ?? null,
                'image_path' => $row['image_path'] ?? null,
                'theme' => $row['theme'] ?? 'info',
                'cta_label' => $row['cta_label'] ?? null,
                'cta_url' => $row['cta_url'] ?? null,
                'audience' => 'all',
                'sort_order' => (int) ($row['sort_order'] ?? 0),
                'is_active' => true,
            ];

            $existing = MarketplaceSlide::query()
                ->where($attributes)
                ->first();

            if (! $existing) {
                if (! $dryRun) {
                    MarketplaceSlide::query()->create($attributes + $values);
                }

                $created++;

                continue;
            }

            if ($overwrite) {
                if (! $dryRun) {
                    $existing->fill($values)->save();
                }

                $updated++;
            }
        }

        return [$created, $updated];
    }

    private function marketIdOption(): int|false|null
    {
        $value = $this->option('market');

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $marketId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($marketId) ? $marketId : false;
    }
}
