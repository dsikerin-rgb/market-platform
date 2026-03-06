<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketplaceSlide;
use App\Support\MarketplaceDefaultSlideCatalog;
use Illuminate\Console\Command;

class SeedMarketplaceSlidesCommand extends Command
{
    protected $signature = 'marketplace:slides:seed-defaults {--market=} {--overwrite}';

    protected $description = 'Seed default marketplace info slides for the selected market.';

    public function handle(): int
    {
        $marketQuery = Market::query()->orderBy('id');

        if (is_numeric($this->option('market'))) {
            $marketQuery->whereKey((int) $this->option('market'));
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
                    MarketplaceSlide::query()->create($attributes + $values);
                    $created++;
                    continue;
                }

                if ($overwrite) {
                    $existing->fill($values)->save();
                    $updated++;
                }
            }
        }

        $this->info('Marketplace slides seeded. created=' . $created . ' updated=' . $updated);

        return self::SUCCESS;
    }
}
