<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiConsultantContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiConsultantContextBuilderRentRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_context_includes_lowest_latest_accrual_rent_rate(): void
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
        $user = User::factory()->create();

        $lowTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Low Rate Tenant',
            'external_id' => 'tenant-low-rate',
            'is_active' => true,
        ]);
        $highTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'High Rate Tenant',
            'external_id' => 'tenant-high-rate',
            'is_active' => true,
        ]);

        $lowSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $lowTenant->id,
            'number' => 'A-1',
            'status' => 'occupied',
            'is_active' => true,
            'area_sqm' => 10,
            'rent_rate_value' => 120,
            'rent_rate_unit' => 'per_sqm_month',
        ]);
        $highSpace = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $highTenant->id,
            'number' => 'B-1',
            'status' => 'occupied',
            'is_active' => true,
            'area_sqm' => 10,
            'rent_rate_value' => 900,
            'rent_rate_unit' => 'per_sqm_month',
        ]);

        $now = now();
        DB::table('tenant_accruals')->insert([
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $highTenant->id,
                'market_space_id' => (int) $highSpace->id,
                'period' => '2026-05-01',
                'area_sqm' => 10,
                'rent_rate' => 50,
                'rent_amount' => 500,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $lowTenant->id,
                'market_space_id' => (int) $lowSpace->id,
                'period' => '2026-06-01',
                'area_sqm' => 10,
                'rent_rate' => 100,
                'rent_amount' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $highTenant->id,
                'market_space_id' => (int) $highSpace->id,
                'period' => '2026-06-01',
                'area_sqm' => 10,
                'rent_rate' => 300,
                'rent_amount' => 3000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $context = app(AiConsultantContextBuilder::class)->build(
            $user,
            (int) $market->id,
            'lowest rent rate',
        );

        $extremes = $context['attention']['rent_rate_extremes'] ?? [];

        $this->assertSame('2026-06-01', $extremes['latest_accrual_period'] ?? null);
        $this->assertSame((int) $lowTenant->id, $extremes['lowest_latest_accrual_rates'][0]['tenant_id'] ?? null);
        $this->assertSame(100.0, $extremes['lowest_latest_accrual_rates'][0]['rent_rate_rub'] ?? null);
        $this->assertSame((int) $highTenant->id, $extremes['highest_latest_accrual_rates'][0]['tenant_id'] ?? null);
    }
}
