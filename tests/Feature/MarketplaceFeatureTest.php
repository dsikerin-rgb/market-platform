<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketHoliday;
use App\Models\MarketplaceAnnouncement;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantShowcase;
use App\Models\User;
use App\Support\MarketplaceDemoAssets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketplaceFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketplace_entry_redirects_to_first_active_market(): void
    {
        $market = Market::query()->create([
            'name' => 'Тестовый рынок',
            'slug' => 'test-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $this->get(route('marketplace.entry'))
            ->assertRedirect(route('marketplace.home', ['marketSlug' => $market->slug]));
    }

    public function test_guest_can_open_home_and_catalog(): void
    {
        $market = Market::query()->create([
            'name' => 'Рынок',
            'slug' => 'market-a',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Арендатор',
            'is_active' => true,
            'slug' => 'tenant-a',
        ]);

        $category = MarketplaceCategory::query()->create([
            'market_id' => null,
            'name' => 'Категория',
            'slug' => 'cat',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $category->id,
            'title' => 'Товар',
            'slug' => 'product-1',
            'price' => 1000,
            'currency' => 'RUB',
            'stock_qty' => 10,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->get(route('marketplace.home', ['marketSlug' => $market->slug]))
            ->assertOk()
            ->assertSee('Товар');

        $this->get(route('marketplace.catalog', ['marketSlug' => $market->slug]))
            ->assertOk()
            ->assertSee('Каталог товаров');
    }

    public function test_buyer_role_access_for_buyer_routes(): void
    {
        $market = Market::query()->create([
            'name' => 'Рынок',
            'slug' => 'market-b',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Покупатель',
            'email' => 'buyer@example.test',
            'password' => 'secret123',
            'market_id' => (int) $market->id,
        ]);
        Role::findOrCreate('buyer', 'web');
        $user->assignRole('buyer');

        $this->actingAs($user, 'web');

        $this->get(route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug]))
            ->assertOk()
            ->assertSee('Кабинет маркетплейса');
    }

    public function test_non_buyer_cannot_open_buyer_dashboard(): void
    {
        $market = Market::query()->create([
            'name' => 'Рынок',
            'slug' => 'market-c',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $user = User::query()->create([
            'name' => 'Оператор',
            'email' => 'staff@example.test',
            'password' => 'secret123',
            'market_id' => (int) $market->id,
        ]);
        Role::findOrCreate('market-admin', 'web');
        $user->assignRole('market-admin');

        $this->actingAs($user, 'web');

        $this->get(route('marketplace.buyer.dashboard', ['marketSlug' => $market->slug]))
            ->assertForbidden();
    }

    public function test_demo_content_can_be_toggled_on_public_pages(): void
    {
        $market = Market::query()->create([
            'name' => 'Demo market',
            'slug' => 'market-demo-toggle',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'allow_public_sales_without_active_contracts' => true,
                ],
            ],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Demo seller',
            'short_name' => 'Demo',
            'slug' => 'demo-seller',
            'is_active' => true,
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'title' => 'Demo product',
            'slug' => 'demo-product',
            'price' => 1000,
            'currency' => 'RUB',
            'stock_qty' => 10,
            'is_active' => true,
            'is_featured' => true,
            'is_demo' => true,
            'published_at' => now(),
        ]);

        TenantShowcase::query()->create([
            'tenant_id' => (int) $tenant->id,
            'title' => 'Demo showcase',
            'description' => 'Demo showcase description',
            'photos' => ['/marketplace/demo/demo-1.svg'],
            'is_demo' => true,
        ]);

        $homeRoute = route('marketplace.home', ['marketSlug' => $market->slug]);
        $showcaseRoute = route('cabinet.showcase.public', ['tenantSlug' => $tenant->slug]);

        $this->get($homeRoute)
            ->assertOk()
            ->assertDontSee('Demo product');

        $this->get($showcaseRoute)
            ->assertOk()
            ->assertDontSee('Demo showcase description');

        $market->forceFill([
            'settings' => [
                'marketplace' => [
                    'allow_public_sales_without_active_contracts' => true,
                    'demo_content_enabled' => true,
                ],
            ],
        ])->save();

        $this->get($homeRoute)
            ->assertOk()
            ->assertSee('Demo product');

        $this->get($showcaseRoute)
            ->assertOk()
            ->assertSee('Demo showcase description');
    }

    public function test_market_holiday_syncs_matching_announcement(): void
    {
        $market = Market::query()->create([
            'name' => 'Holiday market',
            'slug' => 'holiday-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $holiday = MarketHoliday::query()->create([
            'market_id' => (int) $market->id,
            'title' => '9 мая',
            'starts_at' => '2026-05-09',
            'ends_at' => null,
            'all_day' => true,
            'description' => 'День Победы',
            'source' => 'file',
            'cover_image' => 'market-holidays/events/test.webp',
            'public_payload' => [
                'summary' => 'Праздничная программа и специальные предложения.',
                'details' => 'На ярмарке пройдут тематические акции, музыка и семейные активности.',
            ],
        ]);

        $announcement = MarketplaceAnnouncement::query()
            ->where('market_holiday_id', (int) $holiday->id)
            ->first();

        $this->assertNotNull($announcement);
        $this->assertSame((int) $market->id, (int) $announcement->market_id);
        $this->assertSame('9 мая', $announcement->title);
        $this->assertSame('holiday', $announcement->kind);
        $this->assertSame('market-holidays/events/test.webp', $announcement->cover_image);
        $this->assertSame('Праздничная программа и специальные предложения.', $announcement->excerpt);
        $this->assertSame('На ярмарке пройдут тематические акции, музыка и семейные активности.', $announcement->content);
    }

    public function test_announcement_show_renders_structured_event_content_from_market_holiday(): void
    {
        $market = Market::query()->create([
            'name' => 'Event market',
            'slug' => 'event-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $holiday = MarketHoliday::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'День Победы',
            'starts_at' => '2026-05-09',
            'all_day' => true,
            'description' => 'Общее описание события.',
            'source' => 'market_event',
            'public_payload' => [
                'summary' => 'Праздничная программа на всей территории ярмарки.',
                'details' => 'Гостей ждут концерты, тематические витрины и семейные активности.',
                'time_note' => '10:00 - 18:00',
                'location_title' => 'Главная площадь Экоярмарки',
                'location_note' => 'Основная сцена напротив центрального входа.',
                'special_hours' => 'Некоторые павильоны работают по праздничному расписанию.',
                'primary_cta_label' => 'Смотреть праздничные товары',
                'primary_cta_url' => '/m/event-market/catalog',
                'schedule_items' => [
                    [
                        'time' => '10:00',
                        'title' => 'Открытие ярмарки',
                        'description' => 'Старт праздничной программы.',
                    ],
                ],
                'promo_items' => [
                    [
                        'badge' => 'Скидка 15%',
                        'title' => 'Фермерские наборы',
                        'description' => 'Специальные предложения на праздничные товары.',
                        'link_label' => 'Смотреть товары',
                        'link_url' => '/m/event-market/catalog',
                    ],
                ],
            ],
        ]);

        $announcement = MarketplaceAnnouncement::query()
            ->where('market_holiday_id', (int) $holiday->id)
            ->firstOrFail();

        $this->get(route('marketplace.announcement.show', [
            'marketSlug' => $market->slug,
            'announcementSlug' => $announcement->slug,
        ]))
            ->assertOk()
            ->assertSee('Праздничная программа на всей территории ярмарки.')
            ->assertSee('Главная площадь Экоярмарки')
            ->assertSee('10:00 - 18:00')
            ->assertSee('Программа')
            ->assertSee('Открытие ярмарки')
            ->assertSee('Акции и активности')
            ->assertSee('Скидка 15%')
            ->assertSee('Смотреть праздничные товары')
            ->assertDontSee('00:00');
    }

    public function test_announcement_show_falls_back_to_legacy_description_when_public_payload_is_empty(): void
    {
        $market = Market::query()->create([
            'name' => 'Legacy event market',
            'slug' => 'legacy-event-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $holiday = MarketHoliday::query()->create([
            'market_id' => (int) $market->id,
            'title' => 'Субботняя ярмарка',
            'starts_at' => '2026-05-16',
            'all_day' => true,
            'description' => 'Расширенная распродажа сезонных товаров и праздничная программа для семей.',
            'source' => 'market_event',
            'public_payload' => null,
        ]);

        $announcement = MarketplaceAnnouncement::query()
            ->where('market_holiday_id', (int) $holiday->id)
            ->firstOrFail();

        $this->get(route('marketplace.announcement.show', [
            'marketSlug' => $market->slug,
            'announcementSlug' => $announcement->slug,
        ]))
            ->assertOk()
            ->assertSee('Расширенная распродажа сезонных товаров и праздничная программа для семей.')
            ->assertSee('Что будет на событии')
            ->assertSee('Практическая информация')
            ->assertDontSee('Описание события появится позже.');
    }

    public function test_marketplace_bootstrap_seeds_ten_demo_products_per_tenant(): void
    {
        $market = Market::query()->create([
            'name' => 'Bootstrap market',
            'slug' => 'bootstrap-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenantOne = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant One',
            'short_name' => 'One',
            'slug' => 'tenant-one',
            'is_active' => true,
        ]);

        $tenantTwo = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant Two',
            'short_name' => 'Two',
            'slug' => 'tenant-two',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantOne->id,
            'number' => 'A-1',
            'status' => 'leased',
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantTwo->id,
            'number' => 'B-1',
            'status' => 'leased',
        ]);

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 10,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(10, MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenantOne->id)
            ->where('is_demo', true)
            ->count());

        $this->assertSame(10, MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenantTwo->id)
            ->where('is_demo', true)
            ->count());
    }

    public function test_marketplace_bootstrap_uses_tenant_and_space_profile_for_demo_products(): void
    {
        $market = Market::query()->create([
            'name' => 'Profile market',
            'slug' => 'profile-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Мясная лавка Фермера',
            'short_name' => 'Фермер',
            'slug' => 'farmer-meat-shop',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'M-12',
            'display_name' => 'Мясной отдел',
            'activity_type' => 'мясо и колбасы',
            'status' => 'leased',
        ]);

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 3,
            '--force' => true,
        ])->assertExitCode(0);

        $products = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_space_id', (int) $space->id)
            ->where('is_demo', true)
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $products);

        $meatCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('name', 'Мясо и рыба')
            ->first();

        $this->assertNotNull($meatCategory);
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => (int) $product->category_id === (int) $meatCategory->id
        ));
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => ($product->attributes['demo_profile'] ?? null) === 'meat_fish'
        ));
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => Str::contains(
                Str::lower((string) $product->title),
                ['мяс', 'рыб', 'колбас', 'полуфабрикат'],
            )
        ));
    }
    public function test_marketplace_bootstrap_applies_market_overrides_and_skips_non_retail_spaces(): void
    {
        $market = Market::query()->create([
            'name' => 'Eko Market',
            'slug' => 'eko-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Override Tenant',
            'short_name' => 'Override',
            'slug' => 'override-tenant',
            'is_active' => true,
        ]);

        $skippedSpace = MarketSpace::unguarded(fn (): MarketSpace => MarketSpace::query()->create([
            'id' => 74,
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'S-74',
            'display_name' => 'Skip Me',
            'activity_type' => 'Products',
            'status' => 'leased',
        ]));

        $overrideSpace = MarketSpace::unguarded(fn (): MarketSpace => MarketSpace::query()->create([
            'id' => 163,
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'S-163',
            'display_name' => 'Herbs and honey',
            'activity_type' => 'Herbs, balms, honey',
            'status' => 'leased',
        ]));

        $market->forceFill([
            'settings' => [
                'marketplace' => [
                    'demo_seed_overrides' => [
                        'skip_space_ids' => [74],
                        'profiles_by_space_id' => [
                            '163' => 'home',
                        ],
                    ],
                ],
            ],
        ])->save();

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 2,
            '--force' => true,
        ])->assertExitCode(0);

        $products = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_demo', true)
            ->orderBy('id')
            ->get();

        $homeCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'tovary-dlya-doma')
            ->first();

        $this->assertNotNull($homeCategory);
        $this->assertCount(2, $products);
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => (int) $product->market_space_id === 163
        ));
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => (int) $product->category_id === (int) $homeCategory->id
        ));
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => ($product->attributes['demo_profile'] ?? null) === 'home'
        ));
    }

    public function test_marketplace_bootstrap_supports_tenant_level_demo_seed_overrides(): void
    {
        $market = Market::query()->create([
            'name' => 'Tenant override market',
            'slug' => 'tenant-override-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenantToSkip = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Skip Tenant',
            'short_name' => 'Skip',
            'slug' => 'skip-tenant',
            'is_active' => true,
        ]);

        $tenantToProfile = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Profile Tenant',
            'short_name' => 'Profile',
            'slug' => 'profile-tenant',
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantToSkip->id,
            'number' => 'S-1',
            'display_name' => 'Storage',
            'activity_type' => 'Storage',
            'status' => 'leased',
        ]);

        $profileSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantToProfile->id,
            'number' => 'P-1',
            'display_name' => 'Parts desk',
            'activity_type' => 'Unknown',
            'status' => 'leased',
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenantToSkip->id,
            'market_space_id' => (int) $tenantToSkip->spaces()->value('id'),
            'title' => 'Legacy demo product',
            'slug' => 'legacy-demo-product',
            'price' => 1000,
            'currency' => 'RUB',
            'stock_qty' => 1,
            'is_active' => true,
            'is_demo' => true,
            'published_at' => now(),
        ]);

        $market->forceFill([
            'settings' => [
                'marketplace' => [
                    'demo_seed_overrides' => [
                        'skip_tenant_ids' => [(int) $tenantToSkip->id],
                        'profiles_by_tenant_id' => [
                            (string) $tenantToProfile->id => 'home',
                        ],
                    ],
                ],
            ],
        ])->save();

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 2,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenantToSkip->id)
            ->where('is_demo', true)
            ->count());

        $profileProducts = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenantToProfile->id)
            ->where('market_space_id', (int) $profileSpace->id)
            ->where('is_demo', true)
            ->get();

        $homeCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'tovary-dlya-doma')
            ->first();

        $this->assertNotNull($homeCategory);
        $this->assertCount(2, $profileProducts);
        $this->assertTrue($profileProducts->every(
            fn (MarketplaceProduct $product): bool => (int) $product->category_id === (int) $homeCategory->id
        ));
        $this->assertTrue($profileProducts->every(
            fn (MarketplaceProduct $product): bool => ($product->attributes['demo_profile'] ?? null) === 'home'
        ));
    }

    public function test_marketplace_bootstrap_creates_hierarchical_categories_and_root_filter_includes_children(): void
    {
        $market = Market::query()->create([
            'name' => 'Hierarchy market',
            'slug' => 'hierarchy-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'allow_public_sales_without_active_contracts' => true,
                ],
            ],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Hierarchy tenant',
            'short_name' => 'Hierarchy',
            'slug' => 'hierarchy-tenant',
            'is_active' => true,
        ]);

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 0,
            '--force' => true,
        ])->assertExitCode(0);

        $rootCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'produkty')
            ->first();

        $legacyChildCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'bakaleya')
            ->first();

        $this->assertNotNull($rootCategory);
        $this->assertNotNull($legacyChildCategory);
        $this->assertSame((int) $rootCategory->id, (int) $legacyChildCategory->parent_id);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $legacyChildCategory->id,
            'title' => 'Hierarchy product',
            'slug' => 'hierarchy-product',
            'price' => 1000,
            'currency' => 'RUB',
            'stock_qty' => 5,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $this->get(route('marketplace.catalog', [
            'marketSlug' => $market->slug,
            'category' => 'produkty',
        ]))
            ->assertOk()
            ->assertSee('Hierarchy product');
    }

    public function test_catalog_category_filter_shows_only_leaf_categories_with_visible_products(): void
    {
        $market = Market::query()->create([
            'name' => 'Visible categories market',
            'slug' => 'visible-categories-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'allow_public_sales_without_active_contracts' => true,
                ],
            ],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Visible categories tenant',
            'short_name' => 'Visible',
            'slug' => 'visible-categories-tenant',
            'is_active' => true,
        ]);

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 0,
            '--force' => true,
        ])->assertExitCode(0);

        $rootCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'tovary-dlya-doma-root')
            ->first();

        $leafCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'tovary-dlya-doma')
            ->first();

        $this->assertNotNull($rootCategory);
        $this->assertNotNull($leafCategory);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $leafCategory->id,
            'title' => 'Home goods product',
            'slug' => 'home-goods-product',
            'price' => 1200,
            'currency' => 'RUB',
            'stock_qty' => 4,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $response = $this->get(route('marketplace.catalog', ['marketSlug' => $market->slug]))
            ->assertOk();

        $categories = $response->viewData('categories');

        $this->assertCount(1, $categories);
        $this->assertSame((int) $leafCategory->id, (int) $categories->first()->id);
        $this->assertSame('tovary-dlya-doma', (string) $categories->first()->slug);
    }

    public function test_catalog_category_filter_deduplicates_same_named_leaf_categories(): void
    {
        $market = Market::query()->create([
            'name' => 'Duplicate categories market',
            'slug' => 'duplicate-categories-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'allow_public_sales_without_active_contracts' => true,
                ],
            ],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Duplicate categories tenant',
            'short_name' => 'Duplicate',
            'slug' => 'duplicate-categories-tenant',
            'is_active' => true,
        ]);

        $rootCategory = MarketplaceCategory::query()->create([
            'market_id' => null,
            'name' => 'Продукты',
            'slug' => 'products-root-test',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $firstLeafCategory = MarketplaceCategory::query()->create([
            'market_id' => null,
            'parent_id' => (int) $rootCategory->id,
            'name' => 'Бакалея',
            'slug' => 'bakaleya-first',
            'sort_order' => 11,
            'is_active' => true,
        ]);

        $secondLeafCategory = MarketplaceCategory::query()->create([
            'market_id' => null,
            'parent_id' => (int) $rootCategory->id,
            'name' => 'Бакалея',
            'slug' => 'bakaleya-second',
            'sort_order' => 12,
            'is_active' => true,
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $firstLeafCategory->id,
            'title' => 'Groceries first',
            'slug' => 'groceries-first',
            'price' => 100,
            'currency' => 'RUB',
            'stock_qty' => 3,
            'is_active' => true,
            'published_at' => now(),
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $secondLeafCategory->id,
            'title' => 'Groceries second',
            'slug' => 'groceries-second',
            'price' => 150,
            'currency' => 'RUB',
            'stock_qty' => 2,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $response = $this->get(route('marketplace.catalog', ['marketSlug' => $market->slug]))
            ->assertOk();

        $categories = $response->viewData('categories');

        $this->assertCount(1, $categories);
        $this->assertSame('bakaleya-first', (string) $categories->first()->slug);

        $this->get(route('marketplace.catalog', [
            'marketSlug' => $market->slug,
            'category' => 'bakaleya-first',
        ]))
            ->assertOk()
            ->assertSee('Groceries first')
            ->assertSee('Groceries second');
    }

    public function test_catalog_category_filter_deduplicates_same_named_categories_from_legacy_and_tree_nodes(): void
    {
        $market = Market::query()->create([
            'name' => 'Legacy duplicate market',
            'slug' => 'legacy-duplicate-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
            'settings' => [
                'marketplace' => [
                    'allow_public_sales_without_active_contracts' => true,
                ],
            ],
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Legacy duplicate tenant',
            'short_name' => 'Legacy',
            'slug' => 'legacy-duplicate-tenant',
            'is_active' => true,
        ]);

        $rootCategory = MarketplaceCategory::query()->create([
            'market_id' => null,
            'name' => 'Продукты',
            'slug' => 'legacy-products-root',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $legacyLeafCategory = MarketplaceCategory::query()->create([
            'market_id' => null,
            'name' => 'Овощи и фрукты',
            'slug' => 'legacy-vegetables',
            'sort_order' => 11,
            'is_active' => true,
        ]);

        $treeLeafCategory = MarketplaceCategory::query()->create([
            'market_id' => null,
            'parent_id' => (int) $rootCategory->id,
            'name' => 'Овощи и фрукты',
            'slug' => 'tree-vegetables',
            'sort_order' => 12,
            'is_active' => true,
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $legacyLeafCategory->id,
            'title' => 'Legacy vegetables',
            'slug' => 'legacy-vegetables-product',
            'price' => 180,
            'currency' => 'RUB',
            'stock_qty' => 7,
            'is_active' => true,
            'published_at' => now(),
        ]);

        MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'category_id' => (int) $treeLeafCategory->id,
            'title' => 'Tree vegetables',
            'slug' => 'tree-vegetables-product',
            'price' => 220,
            'currency' => 'RUB',
            'stock_qty' => 5,
            'is_active' => true,
            'published_at' => now(),
        ]);

        $response = $this->get(route('marketplace.catalog', ['marketSlug' => $market->slug]))
            ->assertOk();

        $categories = $response->viewData('categories');

        $this->assertCount(1, $categories);
        $this->assertSame('legacy-vegetables', (string) $categories->first()->slug);

        $this->get(route('marketplace.catalog', [
            'marketSlug' => $market->slug,
            'category' => 'legacy-vegetables',
        ]))
            ->assertOk()
            ->assertSee('Legacy vegetables')
            ->assertSee('Tree vegetables');
    }

    public function test_marketplace_bootstrap_uses_specialized_food_profiles_for_child_categories(): void
    {
        $market = Market::query()->create([
            'name' => 'Food profile market',
            'slug' => 'food-profile-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Cafe tenant',
            'short_name' => 'Cafe',
            'slug' => 'cafe-tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'C-1',
            'display_name' => 'Кафе',
            'activity_type' => 'Кафе',
            'status' => 'leased',
        ]);

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 2,
            '--force' => true,
        ])->assertExitCode(0);

        $products = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_space_id', (int) $space->id)
            ->where('is_demo', true)
            ->get();

        $readyFoodCategory = MarketplaceCategory::query()
            ->where('market_id', null)
            ->where('slug', 'kafe-i-gotovye-blyuda')
            ->first();

        $this->assertNotNull($readyFoodCategory);
        $this->assertCount(2, $products);
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => (int) $product->category_id === (int) $readyFoodCategory->id
        ));
        $this->assertTrue($products->every(
            fn (MarketplaceProduct $product): bool => ($product->attributes['demo_profile'] ?? null) === 'ready_food'
        ));
    }

    public function test_marketplace_bootstrap_assigns_profile_photo_bank_images(): void
    {
        $market = Market::query()->create([
            'name' => 'Photo bank market',
            'slug' => 'photo-bank-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Photo bank cafe',
            'short_name' => 'Photo bank',
            'slug' => 'photo-bank-cafe',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'P-1',
            'display_name' => 'Кафе',
            'activity_type' => 'Кафе',
            'status' => 'leased',
        ]);

        $this->artisan('marketplace:bootstrap', [
            '--market' => (string) $market->id,
            '--seed-products' => 1,
            '--force' => true,
        ])->assertExitCode(0);

        $product = MarketplaceProduct::query()
            ->where('market_id', (int) $market->id)
            ->where('tenant_id', (int) $tenant->id)
            ->where('market_space_id', (int) $space->id)
            ->where('is_demo', true)
            ->first();

        $this->assertNotNull($product);
        $this->assertSame(
            MarketplaceDemoAssets::imagePaths('ready_food')[0],
            (string) (($product->images ?? [])[0] ?? '')
        );
    }

    public function test_marketplace_localize_demo_assets_command_localizes_existing_demo_images(): void
    {
        Storage::fake('public');

        config()->set('marketplace.media_disk', 'public');
        config()->set('marketplace.media_fallback_disk', 'public');
        config()->set('marketplace.demo_assets.localize', true);
        config()->set('marketplace.demo_assets.directory', 'marketplace-demo-assets');
        config()->set('marketplace.demo_assets.timeout', 5);
        config()->set('marketplace.demo_assets.retries', 0);

        $market = Market::query()->create([
            'name' => 'Localized demo market',
            'slug' => 'localized-demo-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Localized demo tenant',
            'short_name' => 'Localized',
            'slug' => 'localized-demo-tenant',
            'is_active' => true,
        ]);

        $remoteImage = 'https://images.unsplash.com/photo-localize-demo?w=1400&q=80';

        $product = MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'title' => 'Localized demo product',
            'slug' => 'localized-demo-product',
            'price' => 1000,
            'currency' => 'RUB',
            'stock_qty' => 2,
            'images' => [$remoteImage],
            'attributes' => ['demo_profile' => 'ready_food'],
            'is_active' => true,
            'is_demo' => true,
            'published_at' => now(),
        ]);

        TenantShowcase::query()->create([
            'tenant_id' => (int) $tenant->id,
            'title' => 'Localized demo showcase',
            'photos' => [$remoteImage],
            'is_demo' => true,
        ]);

        $file = UploadedFile::fake()->image('demo.jpg', 1200, 900);
        $binary = (string) file_get_contents($file->getRealPath());

        Http::fake([
            $remoteImage => Http::response($binary, 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->artisan('marketplace:localize-demo-assets', [
            '--market' => (string) $market->id,
        ])->assertExitCode(0);

        $product->refresh();
        $showcase = TenantShowcase::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

        $this->assertStringStartsWith('marketplace-demo-assets/products/ready_food/', (string) (($product->images ?? [])[0] ?? ''));
        $this->assertStringStartsWith('marketplace-demo-assets/showcases/tenant/', (string) (($showcase->photos ?? [])[0] ?? ''));
        Storage::disk('public')->assertExists((string) (($product->images ?? [])[0] ?? ''));
        Storage::disk('public')->assertExists((string) (($showcase->photos ?? [])[0] ?? ''));
    }
}
