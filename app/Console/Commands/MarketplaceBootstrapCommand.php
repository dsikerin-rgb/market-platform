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
    /**
     * @var array<string, array{
     *   category:string,
     *   keywords:list<string>,
     *   titles:list<string>,
     *   unit:string,
     *   price_range:array{0:int,1:int}
     * }>
     */
    private const DEMO_PRODUCT_PROFILES = [
        'produce' => [
            'category' => 'Овощи и фрукты',
            'keywords' => ['овощ', 'фрукт', 'ягод', 'зелень', 'сад', 'урож', 'томат', 'яблок', 'груш', 'картоф', 'солень', 'гриб'],
            'titles' => [
                'Свежие овощи фермерские',
                'Сезонные фрукты',
                'Ягоды и зелень',
                'Овощной набор',
                'Фрукты для стола',
            ],
            'unit' => 'кг',
            'price_range' => [120, 480],
        ],
        'meat_fish' => [
            'category' => 'Мясо и рыба',
            'keywords' => ['мяс', 'рыб', 'колбас', 'птиц', 'стейк', 'шашл', 'фарш', 'деликатес', 'икра', 'краб', 'куриц', 'яйц', 'полуфабрикат'],
            'titles' => [
                'Фермерское мясо охлажденное',
                'Свежая рыба',
                'Домашние полуфабрикаты',
                'Фермерские колбасы',
                'Мясной набор',
            ],
            'unit' => 'кг',
            'price_range' => [320, 1450],
        ],
        'dairy' => [
            'category' => 'Молочные продукты',
            'keywords' => ['сыр', 'молок', 'творог', 'сметан', 'кефир', 'йогурт', 'масл', 'молоч', 'белорусск'],
            'titles' => [
                'Фермерский сыр',
                'Творог натуральный',
                'Сметана домашняя',
                'Йогурт фермерский',
                'Масло сливочное',
            ],
            'unit' => 'шт',
            'price_range' => [140, 780],
        ],
        'grocery' => [
            'category' => 'Бакалея',
            'keywords' => ['бакале', 'чай', 'кофе', 'мед', 'хлеб', 'пекар', 'кондитер', 'сладост', 'круп', 'спец', 'орех', 'сухофрукт', 'варень', 'кафе', 'вино', 'напит', 'кондитерск'],
            'titles' => [
                'Домашний хлеб',
                'Мед натуральный',
                'Чай травяной',
                'Орехи и сухофрукты',
                'Бакалея фермерская',
            ],
            'unit' => 'шт',
            'price_range' => [90, 650],
        ],
        'clothing' => [
            'category' => 'Одежда и текстиль',
            'keywords' => ['одеж', 'текстил', 'обув', 'трикотаж', 'плать', 'рубаш', 'брюк', 'куртк', 'аксессуар', 'бантик', 'детск', 'монгольск'],
            'titles' => [
                'Трикотаж базовый',
                'Одежда повседневная',
                'Текстиль для дома',
                'Аксессуары сезонные',
                'Коллекция текстиля',
            ],
            'unit' => 'шт',
            'price_range' => [450, 3200],
        ],
        'home' => [
            'category' => 'Товары для дома',
            'keywords' => ['дом', 'хоз', 'посуд', 'декор', 'интерьер', 'быт', 'сувенир', 'подар', 'кухн', 'аптек', 'очк', 'дух', 'парфюм', 'мыл', 'зоо', 'мебел', 'чугун', 'канцеляр', 'игруш', 'хими'],
            'titles' => [
                'Посуда для кухни',
                'Текстиль для дома',
                'Декор интерьера',
                'Хозяйственные товары',
                'Полезные товары для дома',
            ],
            'unit' => 'шт',
            'price_range' => [220, 2400],
        ],
        'services' => [
            'category' => 'Услуги',
            'keywords' => ['услуг', 'ремонт', 'ателье', 'сервис', 'достав', 'мастер', 'консульт', 'упаков', 'ключ', 'банкомат', 'офис', 'интернет магазин', 'интернет-магазин', 'игра автомат'],
            'titles' => [
                'Услуга доставки заказа',
                'Индивидуальная упаковка',
                'Подбор товара',
                'Сервисное сопровождение',
                'Консультация по ассортименту',
            ],
            'unit' => 'услуга',
            'price_range' => [300, 3500],
        ],
        'default' => [
            'category' => 'Бакалея',
            'keywords' => [],
            'titles' => [
                'Популярный товар',
                'Товар дня',
                'Фермерский ассортимент',
                'Выбор покупателя',
                'Новинка витрины',
            ],
            'unit' => 'шт',
            'price_range' => [150, 950],
        ],
    ];

    protected $signature = 'marketplace:bootstrap
        {--market= : Market id or slug}
        {--seed-products=10 : Max demo products per tenant}
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

        $seedProductsPerTenant = max(0, (int) $this->option('seed-products'));
        $force = (bool) $this->option('force');
        $refreshAnnouncements = (bool) $this->option('refresh-announcements');

        foreach ($markets as $market) {
            $this->line('');
            $this->info(sprintf('Market: %s (#%d)', $market->name, (int) $market->id));

            if ($refreshAnnouncements) {
                $count = $this->syncAnnouncementsFromHolidays($market);
                $this->line("  announcements synced: {$count}");
            }

            $created = $this->seedDemoProducts($market, $seedProductsPerTenant, $force);
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
            $slug = Str::slug((string) $item['name']);
            if ($slug === '') {
                $slug = 'category-' . (int) $item['sort_order'];
            }

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
            ->get(['id', 'title', 'description', 'starts_at', 'ends_at', 'source', 'cover_image', 'public_payload']);

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
                'market_holiday_id' => (int) $holiday->id,
                'author_user_id' => null,
                'kind' => $this->mapHolidayKind($source),
                'title' => trim((string) ($holiday->title ?? 'Market event')),
                'excerpt' => $holiday->announcementExcerptText(),
                'content' => $holiday->announcementContentText(),
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

        $globalCategories = MarketplaceCategory::query()
            ->whereNull('market_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->keyBy(fn (MarketplaceCategory $category): string => $this->normalizeDemoText((string) $category->name));

        $tenants = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->where('is_active', true)
            ->with(['spaces:id,tenant_id,market_id,display_name,number,code,activity_type'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        $created = 0;
        foreach ($tenants as $tenant) {
            $spaces = $tenant->spaces;
            if ($spaces->isEmpty()) {
                continue;
            }

            $tenantProductsQuery = MarketplaceProduct::query()
                ->where('market_id', (int) $market->id)
                ->where('tenant_id', (int) $tenant->id)
                ->where('is_demo', true);

            if ($force) {
                $tenantProductsQuery->delete();
                $existingDemoCount = 0;
            } else {
                $existingDemoCount = (int) $tenantProductsQuery->count();

                if ($existingDemoCount >= $limit) {
                    continue;
                }
            }

            $productsToCreate = max(0, $limit - $existingDemoCount);
            if ($productsToCreate === 0) {
                continue;
            }

            $spaceItems = $spaces->values()->all();
            $spaceCount = count($spaceItems);
            if ($spaceCount === 0) {
                continue;
            }

            for ($i = 0; $i < $productsToCreate; $i++) {
                $space = $spaceItems[$i % $spaceCount];
                $profile = $this->resolveDemoProductProfile($tenant, $space);
                $sequence = $existingDemoCount + $i + 1;
                $title = $this->buildDemoProductTitle($tenant, $space, $profile, $sequence);
                $slug = $this->makeUniqueProductSlug((int) $market->id, $title . '-' . $space->id . '-' . $sequence);
                $categoryId = $this->resolveDemoCategoryId($globalCategories, (string) $profile['category']);
                $price = $this->resolveDemoPrice(
                    (int) $market->id,
                    (int) $tenant->id,
                    (int) $space->id,
                    $profile['price_range'],
                );

                MarketplaceProduct::query()->create([
                    'market_id' => (int) $market->id,
                    'tenant_id' => (int) $tenant->id,
                    'market_space_id' => (int) $space->id,
                    'category_id' => $categoryId ? (int) $categoryId : null,
                    'title' => Str::limit($title, 190, ''),
                    'slug' => $slug,
                    'description' => $this->buildDemoProductDescription($tenant, $space, $profile),
                    'price' => $price,
                    'currency' => 'RUB',
                    'stock_qty' => $this->resolveDemoStockQty($profile),
                    'unit' => (string) $profile['unit'],
                    'images' => [$this->demoProductImageUrl($created + 1)],
                    'attributes' => [
                        'generated' => true,
                        'demo_profile' => $this->resolveDemoProfileKey($profile),
                        'generated_from' => [
                            'tenant' => trim((string) ($tenant->short_name ?: $tenant->name)),
                            'space' => $this->spaceContextLabel($space),
                            'activity_type' => trim((string) ($space->activity_type ?? '')),
                        ],
                    ],
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

    /**
     * @return array{
     *   category:string,
     *   keywords:list<string>,
     *   titles:list<string>,
     *   unit:string,
     *   price_range:array{0:int,1:int}
     * }
     */
    private function resolveDemoProductProfile(Tenant $tenant, object $space): array
    {
        $searchable = $this->normalizeDemoText(implode(' ', array_filter([
            trim((string) ($tenant->short_name ?? '')),
            trim((string) ($tenant->name ?? '')),
            trim((string) ($space->display_name ?? '')),
            trim((string) ($space->activity_type ?? '')),
            trim((string) ($space->number ?? '')),
            trim((string) ($space->code ?? '')),
        ])));

        foreach (self::DEMO_PRODUCT_PROFILES as $key => $profile) {
            if ($key === 'default') {
                continue;
            }

            foreach ($profile['keywords'] as $keyword) {
                if ($keyword !== '' && str_contains($searchable, $this->normalizeDemoText($keyword))) {
                    return $profile;
                }
            }
        }

        return self::DEMO_PRODUCT_PROFILES['default'];
    }

    private function resolveDemoProfileKey(array $profile): string
    {
        foreach (self::DEMO_PRODUCT_PROFILES as $key => $candidate) {
            if ($candidate === $profile) {
                return $key;
            }
        }

        return 'default';
    }

    private function buildDemoProductTitle(Tenant $tenant, object $space, array $profile, int $sequence): string
    {
        $titles = $profile['titles'];
        $baseTitle = $titles[($sequence - 1) % count($titles)] ?? 'Популярный товар';
        $context = $this->spaceContextLabel($space);

        if ($context === '') {
            $context = trim((string) ($tenant->short_name ?: $tenant->name));
        }

        return trim($baseTitle . ($context !== '' ? ' - ' . $context : ''));
    }

    private function buildDemoProductDescription(Tenant $tenant, object $space, array $profile): string
    {
        $tenantName = trim((string) ($tenant->short_name ?: $tenant->name));
        $spaceLabel = $this->spaceContextLabel($space);
        $activityType = trim((string) ($space->activity_type ?? ''));
        $category = trim((string) ($profile['category'] ?? ''));

        return trim(implode(' ', array_filter([
            $tenantName !== '' ? $tenantName . ' представляет demo-карточку товара.' : 'Demo-карточка товара.',
            $spaceLabel !== '' ? 'Отдел: ' . $spaceLabel . '.' : null,
            $activityType !== '' ? 'Специализация: ' . $activityType . '.' : null,
            $category !== '' ? 'Категория: ' . $category . '.' : null,
            'Замените название, описание, фото и цену на реальные данные арендатора.',
        ])));
    }

    private function spaceContextLabel(object $space): string
    {
        foreach ([
            trim((string) ($space->display_name ?? '')),
            trim((string) ($space->activity_type ?? '')),
            trim((string) ($space->number ?? '')),
            trim((string) ($space->code ?? '')),
        ] as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveDemoCategoryId(Collection $categories, string $categoryName): ?int
    {
        $category = $categories->get($this->normalizeDemoText($categoryName));

        return $category instanceof MarketplaceCategory ? (int) $category->id : null;
    }

    private function resolveDemoStockQty(array $profile): int
    {
        $unit = trim((string) ($profile['unit'] ?? 'шт'));

        return match ($unit) {
            'кг' => random_int(15, 80),
            'услуга' => random_int(5, 20),
            default => random_int(20, 120),
        };
    }

    private function normalizeDemoText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @param array{0:int,1:int} $fallbackRange
     */
    private function resolveDemoPrice(int $marketId, int $tenantId, int $spaceId, array $fallbackRange): float
    {
        $value = TenantAccrual::query()
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('market_space_id', $spaceId)
            ->orderByDesc('period')
            ->value('total_with_vat');

        if (is_numeric($value)) {
            return round(max(50.0, ((float) $value / 100.0)), 2);
        }

        $min = max(50, (int) ($fallbackRange[0] ?? 150));
        $max = max($min, (int) ($fallbackRange[1] ?? 950));

        return round((float) random_int($min, $max), 2);
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
