<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAccrualResourceTenantFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_filters_by_tenant_id_and_shows_full_report_columns(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenantA = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant B',
            'is_active' => true,
        ]);

        $this->insertAccrual((int) $market->id, (int) $tenantA->id, 12.50, 1200.00, 1500.00, '2026-04-10 12:00:00', 'a-row');
        $this->insertAccrual((int) $market->id, (int) $tenantB->id, 99.99, 987.65, 1111.11, '2026-04-11 13:30:00', 'b-row');

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-tenant-report@example.test',
        ]);
        $user->assignRole('market-admin');

        $this->actingAs($user);

        $response = $this->get(TenantAccrualResource::getUrl('index', ['tenantId' => $tenantA->id]));

        $response
            ->assertOk()
            ->assertSeeText('Tenant A')
            ->assertDontSeeText('Tenant B')
            ->assertSeeText('Дата расчёта 1С')
            ->assertSeeText('Площадь, м²')
            ->assertSeeText('Аренда')
            ->assertSeeText('2026-04-10 12:00')
            ->assertSeeText('12,50')
            ->assertSeeText('1 200,00 ₽')
            ->assertSeeText('1 500,00 ₽');
    }

    private function insertAccrual(
        int $marketId,
        int $tenantId,
        float $areaSqm,
        float $rentRate,
        float $rentAmount,
        string $calculatedAt,
        string $hashSeed,
    ): void {
        DB::table('tenant_accruals')->insert([
            'market_id' => $marketId,
            'tenant_id' => $tenantId,
            'tenant_contract_id' => null,
            'market_space_id' => null,
            'period' => '2026-04-01',
            'area_sqm' => $areaSqm,
            'rent_rate' => $rentRate,
            'rent_amount' => $rentAmount,
            'currency' => 'RUB',
            'total_with_vat' => $rentAmount,
            'source' => '1c',
            'status' => 'imported',
            'source_file' => '1c:accruals',
            'source_row_number' => 1,
            'source_row_hash' => hash('sha256', $hashSeed),
            'payload' => json_encode([
                'calculated_at' => $calculatedAt,
                'area_sqm' => $areaSqm,
                'rent_rate' => $rentRate,
                'rent_amount' => $rentAmount,
            ], JSON_UNESCAPED_UNICODE),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
