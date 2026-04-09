<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\Market;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardMonthFilterHydrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bootstrap_advances_stale_hydrated_month_filter_to_latest_data(): void
    {
        Carbon::setTestNow('2026-04-09 12:00:00');

        $market = Market::query()->create([
            'name' => 'Эко Ярмарка',
            'timezone' => 'Asia/Barnaul',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Тестовый арендатор',
            'is_active' => true,
            'external_id' => 'tenant-dashboard-month-filter',
        ]);

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'dashboard-month-filter@example.test',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'period' => '2026-04-01',
            'currency' => 'RUB',
            'rent_amount' => 1000.00,
            'utilities_amount' => 0,
            'electricity_amount' => 0,
            'management_fee' => 0,
            'total_with_vat' => 1000.00,
            'source' => '1c',
            'source_row_hash' => hash('sha256', 'dashboard-month-filter'),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);
        session([
            'dashboard_market_id' => (int) $market->id,
            'dashboard_month' => '2026-03',
            'dashboard_period' => '2026-03-01',
        ]);

        $page = app(Dashboard::class);
        $page->filters = ['month' => '2026-03'];

        $resolveLastMonthWithData = new \ReflectionMethod($page, 'resolveLastMonthWithData');
        $resolveLastMonthWithData->setAccessible(true);

        $this->assertSame(
            '2026-04',
            $resolveLastMonthWithData->invoke($page, (int) $market->id, 'Asia/Barnaul')
        );

        $resolveMonthOrLatest = new \ReflectionMethod($page, 'resolveMonthOrLatest');
        $resolveMonthOrLatest->setAccessible(true);

        $this->assertSame('2026-04', $resolveMonthOrLatest->invoke($page, '2026-03', '2026-04'));

        $resolveMonthFromRequestPeriod = new \ReflectionMethod($page, 'resolveMonthFromRequestPeriod');
        $resolveMonthFromRequestPeriod->setAccessible(true);

        $this->assertNull($resolveMonthFromRequestPeriod->invoke($page, 'Asia/Barnaul'));

        $method = new \ReflectionMethod($page, 'bootstrapDashboardState');
        $method->setAccessible(true);
        $method->invoke($page);

        $this->assertSame('2026-04', $page->filters['month'] ?? null);
        $this->assertSame('2026-04', session('dashboard_month'));
        $this->assertSame('2026-04-01', session('dashboard_period'));

        Carbon::setTestNow();
    }
}
