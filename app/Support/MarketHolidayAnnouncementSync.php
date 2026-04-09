<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarketHoliday;
use App\Models\MarketplaceAnnouncement;
use Illuminate\Support\Str;

final class MarketHolidayAnnouncementSync
{
    public function sync(MarketHoliday $holiday): ?MarketplaceAnnouncement
    {
        if (! $holiday->market_id) {
            return null;
        }

        $source = $this->canonicalHolidaySource((string) ($holiday->source ?? ''));
        $rawSource = trim((string) ($holiday->source ?? ''));
        $startDate = $holiday->starts_at?->toDateString();
        $slug = $this->makeAnnouncementSlug((string) ($holiday->title ?? 'event'), $source, $startDate);
        $legacySlug = $rawSource !== '' && $rawSource !== $source
            ? $this->makeAnnouncementSlug((string) ($holiday->title ?? 'event'), $rawSource, $startDate)
            : null;

        $announcement = MarketplaceAnnouncement::query()
            ->where('market_id', (int) $holiday->market_id)
            ->where(function ($query) use ($holiday, $slug, $legacySlug): void {
                $query->where('market_holiday_id', (int) $holiday->id)
                    ->orWhere('slug', $slug);

                if ($legacySlug !== null && $legacySlug !== $slug) {
                    $query->orWhere('slug', $legacySlug);
                }

                if ($holiday->starts_at) {
                    $query->orWhere(function ($legacyQuery) use ($holiday): void {
                        $legacyQuery->where('title', (string) $holiday->title)
                            ->whereDate('starts_at', $holiday->starts_at->toDateString());
                    });
                }
            })
            ->orderByDesc('market_holiday_id')
            ->orderByDesc('id')
            ->first();

        $payload = [
            'market_holiday_id' => (int) $holiday->id,
            'author_user_id' => null,
            'kind' => $this->mapHolidayKind($source),
            'title' => trim((string) ($holiday->title ?? 'Market event')),
            'slug' => $slug,
            'excerpt' => $holiday->announcementExcerptText(),
            'content' => $holiday->announcementContentText(),
            'cover_image' => $this->resolveCoverImage($holiday),
            'starts_at' => $holiday->starts_at,
            'ends_at' => $holiday->ends_at,
            'is_active' => true,
            'published_at' => $holiday->starts_at ?? now(),
        ];

        if ($announcement) {
            $announcement->fill($payload)->save();

            return $announcement->refresh();
        }

        return MarketplaceAnnouncement::query()->create($payload + [
            'market_id' => (int) $holiday->market_id,
        ]);
    }

    public function delete(MarketHoliday $holiday): void
    {
        if (! $holiday->id) {
            return;
        }

        $announcement = MarketplaceAnnouncement::query()
            ->where('market_holiday_id', (int) $holiday->id)
            ->first();

        if (! $announcement) {
            $source = $this->canonicalHolidaySource((string) ($holiday->source ?? ''));
            $slug = $this->makeAnnouncementSlug((string) ($holiday->title ?? 'event'), $source, $holiday->starts_at?->toDateString());

            $announcement = MarketplaceAnnouncement::query()
                ->where('market_id', (int) $holiday->market_id)
                ->where('slug', $slug)
                ->first();
        }

        if (! $announcement) {
            return;
        }

        MarketplaceMediaStorage::delete($announcement->cover_image);
        $announcement->delete();
    }

    private function resolveCoverImage(MarketHoliday $holiday): ?string
    {
        $coverImage = MarketplaceAnnouncementImageCatalog::resolveCoverImage(
            (string) ($holiday->title ?? ''),
            $holiday->cover_image,
        );

        if (! is_string($coverImage)) {
            return null;
        }

        $coverImage = trim($coverImage);

        return $coverImage !== '' ? $coverImage : null;
    }

    private function canonicalHolidaySource(string $source): string
    {
        $normalized = Str::lower(trim($source));

        if ($normalized === '') {
            return 'event';
        }

        if (str_contains($normalized, 'promo')) {
            return 'promotion';
        }

        if (str_contains($normalized, 'sanitary')) {
            return 'sanitary_auto';
        }

        if ($normalized === 'file' || $normalized === 'holiday' || str_contains($normalized, 'holiday')) {
            return 'national_holiday';
        }

        return $normalized;
    }

    private function mapHolidayKind(string $source): string
    {
        $normalized = $this->canonicalHolidaySource($source);

        return match (true) {
            str_contains($normalized, 'sanitary') => 'sanitary_day',
            str_contains($normalized, 'holiday') || $normalized === 'file' => 'holiday',
            str_contains($normalized, 'promo') || $normalized === 'promotion' => 'promo',
            default => 'event',
        };
    }

    private function makeAnnouncementSlug(string $title, string $source, ?string $startsAt): string
    {
        $slugBase = trim(sprintf('%s-%s-%s', $title, $source, $startsAt ?: 'date'));
        $slug = Str::slug($slugBase);

        return $slug !== '' ? $slug : 'event-' . sha1($slugBase);
    }
}
