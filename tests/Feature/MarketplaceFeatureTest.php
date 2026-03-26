<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantShowcase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
