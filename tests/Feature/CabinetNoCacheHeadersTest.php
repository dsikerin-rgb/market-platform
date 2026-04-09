<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketplaceProduct;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CabinetNoCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_cabinet_pages_send_no_store_cache_headers(): void
    {
        [$market, $tenant, $space] = $this->createTenantContext();
        $user = $this->createCabinetUser((int) $market->id, (int) $tenant->id, 'merchant');

        $product = MarketplaceProduct::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'title' => 'Товар',
            'slug' => 'tovar',
            'is_active' => true,
        ]);

        $loginResponse = $this->get(route('cabinet.login'));
        $loginResponse->assertOk();
        $this->assertCabinetNoCacheHeaders($loginResponse);

        $this->actingAs($user, 'web');

        $editResponse = $this->get(route('cabinet.products.edit', ['product' => (int) $product->id]));
        $editResponse->assertOk();
        $this->assertCabinetNoCacheHeaders($editResponse);
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

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'A-1',
            'display_name' => 'Ряд A-1',
            'is_active' => true,
        ]);

        return [$market, $tenant, $space];
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

    private function assertCabinetNoCacheHeaders(\Illuminate\Testing\TestResponse $response): void
    {
        $cacheControl = (string) $response->headers->get('Cache-Control', '');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringContainsString('must-revalidate', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertSame('no-cache', (string) $response->headers->get('Pragma', ''));
        $this->assertSame('0', (string) $response->headers->get('Expires', ''));
    }
}
