<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Кабинет покупателя');
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
}

