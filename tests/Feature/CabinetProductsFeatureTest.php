<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CabinetProductsFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_create_and_update_product(): void
    {
        [$market, $tenant, $spaceA] = $this->createTenantContext();
        $category = MarketplaceCategory::query()->create([
            'market_id' => null,
            'name' => 'Овощи',
            'slug' => 'ovoshi',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $merchant = $this->createCabinetUser((int) $market->id, (int) $tenant->id, 'merchant');

        $this->actingAs($merchant, 'web');

        $this->post(route('cabinet.products.store'), [
            'title' => 'Картофель',
            'description' => 'Свежий картофель',
            'price' => '120.50',
            'stock_qty' => 55,
            'sku' => 'POTATO-01',
            'unit' => 'кг',
            'category_id' => (int) $category->id,
            'market_space_id' => (int) $spaceA->id,
            'is_active' => '1',
            'is_featured' => '1',
        ])->assertRedirect();

        $product = MarketplaceProduct::query()->where('title', 'Картофель')->first();
        $this->assertNotNull($product);
        $this->assertSame((int) $tenant->id, (int) $product->tenant_id);
        $this->assertSame((int) $spaceA->id, (int) $product->market_space_id);
        $this->assertTrue((bool) $product->is_active);
        $this->assertTrue((bool) $product->is_featured);

        $this->post(route('cabinet.products.update', ['product' => (int) $product->id]), [
            'title' => 'Картофель мытый',
            'description' => 'Обновленное описание',
            'price' => '150',
            'stock_qty' => 10,
            'sku' => 'POTATO-02',
            'unit' => 'упак',
            'category_id' => (int) $category->id,
            'market_space_id' => (int) $spaceA->id,
            'is_featured' => '1',
            // is_active не отправляем: товар должен стать скрытым
        ])->assertRedirect();

        $product->refresh();

        $this->assertSame('Картофель мытый', (string) $product->title);
        $this->assertSame(150.0, (float) $product->price);
        $this->assertSame(10, (int) $product->stock_qty);
        $this->assertFalse((bool) $product->is_active);
        $this->assertTrue((bool) $product->is_featured);
    }

    public function test_merchant_user_cannot_create_product_without_allowed_space(): void
    {
        [$market, $tenant, $spaceA, $spaceB] = $this->createTenantContext();

        $category = MarketplaceCategory::query()->create([
            'market_id' => null,
            'name' => 'Мясо',
            'slug' => 'myaso',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $merchantUser = $this->createCabinetUser((int) $market->id, (int) $tenant->id, 'merchant-user');
        $merchantUser->tenantSpaces()->sync([(int) $spaceA->id]);

        $this->actingAs($merchantUser, 'web');

        $this->post(route('cabinet.products.store'), [
            'title' => 'Стейк',
            'description' => 'Описание',
            'price' => '500',
            'stock_qty' => 5,
            'category_id' => (int) $category->id,
            // сотрудник без полного доступа не может создавать "без привязки"
            'is_active' => '1',
        ])->assertSessionHasErrors(['market_space_id']);

        $this->assertNull(MarketplaceProduct::query()->where('title', 'Стейк')->first());

        $this->post(route('cabinet.products.store'), [
            'title' => 'Стейк',
            'description' => 'Описание',
            'price' => '500',
            'stock_qty' => 5,
            'category_id' => (int) $category->id,
            // и не может выбрать чужое место
            'market_space_id' => (int) $spaceB->id,
            'is_active' => '1',
        ])->assertSessionHasErrors(['market_space_id']);

        $this->assertNull(MarketplaceProduct::query()->where('title', 'Стейк')->first());
    }

    public function test_merchant_user_can_manage_product_only_in_allowed_space(): void
    {
        [$market, $tenant, $spaceA, $spaceB] = $this->createTenantContext();

        $merchantUser = $this->createCabinetUser((int) $market->id, (int) $tenant->id, 'merchant-user');
        $merchantUser->tenantSpaces()->sync([(int) $spaceA->id]);

        $allowedProduct = MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $spaceA->id,
            'title' => 'Товар А',
            'slug' => 'product-a',
            'is_active' => true,
        ]);

        $foreignProduct = MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $spaceB->id,
            'title' => 'Товар Б',
            'slug' => 'product-b',
            'is_active' => true,
        ]);

        $this->actingAs($merchantUser, 'web');

        $this->get(route('cabinet.products.edit', ['product' => (int) $allowedProduct->id]))
            ->assertOk();

        $this->get(route('cabinet.products.edit', ['product' => (int) $foreignProduct->id]))
            ->assertNotFound();
    }

    private function createTenantContext(): array
    {
        $market = Market::query()->create([
            'name' => 'Тестовая ярмарка',
            'slug' => 'test-fair',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
            'slug' => 'tenant-test',
        ]);

        $spaceA = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'A-1',
            'display_name' => 'Ряд A-1',
            'is_active' => true,
        ]);

        $spaceB = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'B-2',
            'display_name' => 'Ряд B-2',
            'is_active' => true,
        ]);

        return [$market, $tenant, $spaceA, $spaceB];
    }

    private function createCabinetUser(int $marketId, int $tenantId, string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
        ]);

        $user->assignRole($role);

        return $user;
    }
}

