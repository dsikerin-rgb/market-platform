<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\MarketplaceAnnouncement;
use App\Support\MarketContext;
use App\Support\MarketplaceAnnouncementImageCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class LocalizeMarketplaceAnnouncementImagesCommand extends Command
{
    protected $signature = 'marketplace:images:localize
        {--market= : Market id or slug}
        {--overwrite : Force replacing current image even if it is already local}
        {--dry-run : Run in dry-run mode}
        {--execute : Apply image localization (default: dry-run)}';

    protected $description = 'Replace mapped holiday/promo cover images with local marketplace assets';

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
            $this->warn('No active markets found.');

            return self::SUCCESS;
        }

        $overwrite = (bool) $this->option('overwrite');
        $holidayUpdates = 0;
        $announcementUpdates = 0;

        foreach ($markets as $market) {
            $this->line(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            [$marketHolidayUpdates, $marketAnnouncementUpdates] = app(MarketContext::class)->withMarket(
                (int) $market->id,
                fn (): array => $this->localizeMarket($market, $overwrite, $dryRun),
            );

            $holidayUpdates += $marketHolidayUpdates;
            $announcementUpdates += $marketAnnouncementUpdates;
        }

        $this->info(sprintf(
            '%s. holidays=%d announcements=%d',
            $dryRun ? 'Dry-run done' : 'Done',
            $holidayUpdates,
            $announcementUpdates,
        ));

        if ($dryRun) {
            $this->warn('DRY RUN: no changes applied. Use --execute --market=... to apply.');
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
     * @return array{0: int, 1: int}
     */
    private function localizeMarket(Market $market, bool $overwrite, bool $dryRun): array
    {
        return [
            $this->localizeHolidays($market, $overwrite, $dryRun),
            $this->localizeAnnouncements($market, $overwrite, $dryRun),
        ];
    }

    private function localizeHolidays(Market $market, bool $overwrite, bool $dryRun): int
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

            if (! $dryRun) {
                $holiday->forceFill(['cover_image' => $resolved])->save();
            }

            $updated++;
        }

        return $updated;
    }

    private function localizeAnnouncements(Market $market, bool $overwrite, bool $dryRun): int
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

            if (! $dryRun) {
                $announcement->forceFill(['cover_image' => $resolved])->save();
            }

            $updated++;
        }

        return $updated;
    }
}
