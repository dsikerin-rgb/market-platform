<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\MarketplaceAnnouncement;
use App\Support\MarketplaceAnnouncementImageCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class LocalizeMarketplaceAnnouncementImagesCommand extends Command
{
    protected $signature = 'marketplace:images:localize
        {--market= : Market id or slug}
        {--overwrite : Force replacing current image even if it is already local}';

    protected $description = 'Replace mapped holiday/promo cover images with local marketplace assets';

    public function handle(): int
    {
        $markets = $this->resolveMarkets();

        if ($markets->isEmpty()) {
            $this->warn('No active markets found.');

            return self::SUCCESS;
        }

        $overwrite = (bool) $this->option('overwrite');
        $holidayUpdates = 0;
        $announcementUpdates = 0;

        foreach ($markets as $market) {
            $this->line(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            $holidayUpdates += $this->localizeHolidays($market, $overwrite);
            $announcementUpdates += $this->localizeAnnouncements($market, $overwrite);
        }

        $this->info(sprintf(
            'Done. holidays=%d announcements=%d',
            $holidayUpdates,
            $announcementUpdates,
        ));

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

    private function localizeHolidays(Market $market, bool $overwrite): int
    {
        $updated = 0;

        $holidays = MarketHoliday::query()
            ->where('market_id', (int) $market->id)
            ->get(['id', 'title', 'cover_image']);

        foreach ($holidays as $holiday) {
            $resolved = MarketplaceAnnouncementImageCatalog::resolveCoverImage(
                (string) ($holiday->title ?? ''),
                $holiday->cover_image,
                $overwrite,
            );

            if ($resolved === null || $resolved === $holiday->cover_image) {
                continue;
            }

            $holiday->forceFill(['cover_image' => $resolved])->save();
            $updated++;
        }

        return $updated;
    }

    private function localizeAnnouncements(Market $market, bool $overwrite): int
    {
        $updated = 0;

        $announcements = MarketplaceAnnouncement::query()
            ->where('market_id', (int) $market->id)
            ->get(['id', 'title', 'cover_image']);

        foreach ($announcements as $announcement) {
            $resolved = MarketplaceAnnouncementImageCatalog::resolveCoverImage(
                (string) ($announcement->title ?? ''),
                $announcement->cover_image,
                $overwrite,
            );

            if ($resolved === null || $resolved === $announcement->cover_image) {
                continue;
            }

            $announcement->forceFill(['cover_image' => $resolved])->save();
            $updated++;
        }

        return $updated;
    }
}
