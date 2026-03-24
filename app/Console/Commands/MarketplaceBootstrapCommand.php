<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\MarketplaceAnnouncement;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Support\MarketplaceAnnouncementImageCatalog;
use App\Support\MarketplaceDemoAssets;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class MarketplaceBootstrapCommand extends Command
{
    protected $signature = 'marketplace:bootstrap
        {--market= : Market id or slug}
        {--seed-products=60 : Max demo products per market}
        {--refresh-announcements : Sync announcements from market holidays}
        {--force : Generate demo products even if marketplace already has products}';

    protected $description = 'Bootstrap marketplace base categories, announcements, and demo products';

    public function handle(): int
    {
        Role::findOrCreate('buyer', 'web');

        $markets = $this->resolveMarkets();
        if ($markets->isEmpty()) {
            $this->warn('No active markets found for marketplace bootstrap.');

            return self::SUCCESS;
        }

        $this->ensureGlobalCategories();

        $seedProductsLimit = max(0, (int) $this->option('seed-products'));
        $force = (bool) $this->option('force');
        $refreshAnnouncements = (bool) $this->option('refresh-announcements');

        foreach ($markets as $market) {
            $this->line('');
            $this->info(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            if ($refreshAnnouncements) {
                $count = $this->syncAnnouncementsFromHolidays($market);
                $this->line("  announcements synced: {$count}");
            }

            $created = $this->seedDemoProducts($market, $seedProductsLimit, $force);
            $this->line("  demo products created: {$created}");
        }

        $this->info('');
        $this->info('Marketplace bootstrap completed.');

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

    private function ensureGlobalCategories(): void
    {
        $items = [
            ['name' => 'Овощи и фрукты', 'icon' => '🥬', 'sort_order' => 10],
            ['name' => 'Мясо и рыба', 'icon' => '🥩', 'sort_order' => 20],
            ['name' => 'Молочные продукты', 'icon' => '🥛', 'sort_order' => 30],
            ['name' => 'Бакалея', 'icon' => '🧂', 'sort_order' => 40],
            ['name' => 'Одежда и текстиль', 'icon' => '👕', 'sort_order' => 50],
            ['name' => 'Товары для дома', 'icon' => '🏠', 'sort_order' => 60],
            ['name' => 'Услуги', 'icon' => '🛠', 'sort_order' => 70],
        ];

        foreach ($items as $item) {
            $slug = $this->makeUniqueCategorySlug(null, (string) $item['name']);
            MarketplaceCategory::query()->updateOrCreate(
                ['market_id' => null, 'slug' => $slug],
                [
                    'name' => (string) $item['name'],
                    'icon' => (string) $item['icon'],
                    'sort_order' => (int) $item['sort_order'],
                    'is_active' => true,
                ],
            );
        }
    }

    private function syncAnnouncementsFromHolidays(Market $market): int
    {
        $holidays = MarketHoliday::query()
            ->where('market_id', (int) $market->id)
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get(['id', 'title', 'description', 'starts_at', 'ends_at', 'source', 'cover_image']);

        $count = 0;
        foreach ($holidays as $holiday) {
            $source = $this->canonicalHolidaySource((string) ($holiday->source ?? ''));
            $rawSource = trim((string) ($holiday->source ?? ''));

            $slugBase = trim(sprintf(
                '%s-%s-%s',
                (string) ($holiday->title ?? 'event'),
                $source,
                optional($holiday->starts_at)->format('Y-m-d') ?? 'date',
            ));
            $slug = Str::slug($slugBase);
            if ($slug === '') {
                $slug = 'event-' . (int) $holiday->id;
            }

            $legacySlug = null;
            if ($rawSource !== '' && $rawSource !== $source) {
                $legacySlugBase = trim(sprintf(
                    '%s-%s-%s',
                    (string) ($holiday->title ?? 'event'),
                    $rawSource,
                    optional($holiday->starts_at)->format('Y-m-d') ?? 'date',
                ));
                $legacySlug = Str::slug($legacySlugBase);
                if ($legacySlug === '') {
                    $legacySlug = null;
                }
            }

            $existing = MarketplaceAnnouncement::query()
                ->where('market_id', (int) $market->id)
                ->where(function ($query) use ($slug, $legacySlug): void {
                    $query->where('slug', $slug);

                    if ($legacySlug !== null && $legacySlug !== $slug) {
                        $query->orWhere('slug', $legacySlug);
                    }
                })
                ->first();

            // Если не нашли по slug, ищем по названию и дате начала
            if (! $existing) {
                $existing = MarketplaceAnnouncement::query()
                    ->where('market_id', (int) $market->id)
                    ->where('title', $holiday->title)
                    ->whereDate('starts_at', $holiday->starts_at)
                    ->first();
            }

            $coverImage = $this->normalizeCoverImage(
                MarketplaceAnnouncementImageCatalog::resolveCoverImage(
                    (string) ($holiday->title ?? ''),
                    $holiday->cover_image,
                )
            );

            $payload = [
                'author_user_id' => null,
                'kind' => $this->mapHolidayKind($source),
                'title' => trim((string) ($holiday->title ?? 'Market event')),
                'excerpt' => Str::limit(trim((string) ($holiday->description ?? '')), 220),
                'content' => trim((string) ($holiday->description ?? '')),
                'starts_at' => $holiday->starts_at,
                'ends_at' => $holiday->ends_at,
                'is_active' => true,
                'published_at' => $holiday->starts_at ?? now(),
            ];

            if ($coverImage !== null) {
                $payload['cover_image'] = $coverImage;
            }

            if ($existing) {
                $payload['slug'] = $slug;
                $existing->fill($payload)->save();
            } else {
                $payload['market_id'] = (int) $market->id;
                $payload['slug'] = $slug;
                MarketplaceAnnouncement::query()->create($payload);
            }

            $count++;
        }

        return $count;
    }

    private function seedDemoProducts(Market $market, int $limit, bool $force): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $existingCount = (int) MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->count();

        if ($existingCount > 0 && ! $force) {
            return 0;
        }

        $globalCategories = MarketplaceCategory::query()
            ->whereNull('market_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        $tenants = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->with(['spaces:id,tenant_id,market_id,display_name,number,code'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        $created = 0;
        foreach ($tenants as $tenant) {
            if ($created >= $limit) {
                break;
            }

            $spaces = $tenant->spaces;
            if ($spaces->isEmpty()) {
                continue;
            }

            foreach ($spaces as $space) {
                if ($created >= $limit) {
                    break 2;
                }

                $spaceLabel = trim((string) ($space->display_name ?: ($space->number ?: $space->code)));
                $title = trim(($tenant->short_name ?: $tenant->name) . ' · ' . ($spaceLabel !== '' ? $spaceLabel : 'Торговое место'));
                $slug = $this->makeUniqueProductSlug((int) $market->id, $title . '-' . $space->id);
                $categoryId = optional($globalCategories->random())->id;
                $price = $this->resolveDemoPrice((int) $market->id, (int) $tenant->id, (int) $space->id);

                MarketplaceProduct::query()->create([
                    'market_id' => (int) $market->id,
                    'tenant_id' => (int) $tenant->id,
                    'market_space_id' => (int) $space->id,
                    'category_id' => $categoryId ? (int) $categoryId : null,
                    'title' => Str::limit($title, 190, ''),
                    'slug' => $slug,
                    'description' => 'Демо-карточка товара/предложения. Заполните описание, фото и условия доставки в кабинете арендатора.',
                    'price' => $price,
                    'currency' => 'RUB',
                    'stock_qty' => 100,
                    'unit' => 'шт',
                    'images' => [$this->demoProductImageUrl($created + 1)],
                    'attributes' => ['generated' => true],
                    'views_count' => 0,
                    'favorites_count' => 0,
                    'is_active' => true,
                    'is_featured' => $created < 8,
                    'is_demo' => true,
                    'published_at' => now(),
                ]);

                $created++;
            }
        }

        return $created;
    }

    private function resolveDemoPrice(int $marketId, int $tenantId, int $spaceId): float
    {
        $value = TenantAccrual::query()
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('market_space_id', $spaceId)
            ->orderByDesc('period')
            ->value('total_with_vat');

        $price = is_numeric($value) ? ((float) $value / 100.0) : random_int(250, 3500);

        return round(max(50.0, $price), 2);
    }

    private function demoProductImageUrl(int $index): string
    {
        $images = MarketplaceDemoAssets::imagePaths();

        if ($images === []) {
            return '/marketplace/demo/demo-1.svg';
        }

        $normalizedIndex = max(1, $index);

        return $images[($normalizedIndex - 1) % count($images)];
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

    private function makeUniqueCategorySlug(?int $marketId, string $source): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $i = 1;
        while (MarketplaceCategory::query()->where('market_id', $marketId)->where('slug', $slug)->exists()) {
            $i++;
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function makeUniqueProductSlug(int $marketId, string $source): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'product';
        }

        $slug = $base;
        $i = 1;
        while (MarketplaceProduct::query()->where('market_id', $marketId)->where('slug', $slug)->exists()) {
            $i++;
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function makeUniqueAnnouncementSlug(int $marketId, string $source): string
    {
        $base = Str::slug($source);
        if ($base === '') {
            $base = 'announcement';
        }

        $slug = $base;
        $i = 1;
        while (MarketplaceAnnouncement::query()->where('market_id', $marketId)->where('slug', $slug)->exists()) {
            $i++;
            $slug = $base . '-' . $i;
        }

        return $slug;
    }

    private function normalizeCoverImage(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $image = trim($value);

        if ($image === '') {
            return null;
        }

        if (Str::startsWith($image, ['http://', 'https://', 'data:', '/'])) {
            return $image;
        }

        return Storage::disk('public')->url($image);
    }
}
