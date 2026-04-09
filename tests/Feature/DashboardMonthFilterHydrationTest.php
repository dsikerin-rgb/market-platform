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

    public function test_booted_reconciles_stale_auto_dashboard_month_filter_after_filament_restores_filters(): void
    {
        [$page, $filtersSessionKey] = $this->bootstrapDashboardPageWithLatestMonth(
            dashboardMonth: '2026-03',
            filtersMonth: '2026-03',
            monthMode: null,
        );

        $page->mountHasFilters();
        $page->booted();

        $this->assertSame('2026-04', $page->filters['month'] ?? null);
        $this->assertSame('2026-04', session('dashboard_month'));
        $this->assertSame('2026-04-01', session('dashboard_period'));
        $this->assertSame('auto', session('dashboard_month_mode'));
        $this->assertSame('2026-04', data_get(session($filtersSessionKey), 'month'));

        Carbon::setTestNow();
    }

    public function test_booted_preserves_manual_historical_dashboard_month_filter(): void
    {
        [$page, $filtersSessionKey] = $this->bootstrapDashboardPageWithLatestMonth(
            dashboardMonth: '2026-03',
            filtersMonth: '2026-03',
            monthMode: 'manual',
        );

        $page->mountHasFilters();
        $page->booted();

        $this->assertSame('2026-03', $page->filters['month'] ?? null);
        $this->assertSame('2026-03', session('dashboard_month'));
        $this->assertSame('2026-03-01', session('dashboard_period'));
        $this->assertSame('manual', session('dashboard_month_mode'));
        $this->assertSame('2026-03', data_get(session($filtersSessionKey), 'month'));

        Carbon::setTestNow();
    }

    /**
     * @return array{0: Dashboard, 1: string}
     */
    private function bootstrapDashboardPageWithLatestMonth(
        string $dashboardMonth,
        string $filtersMonth,
        ?string $monthMode,
    ): array {
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
            'source_row_hash' => hash('sha256', 'dashboard-month-filter-' . $dashboardMonth . '-' . ($monthMode ?? 'auto')),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user);

        $page = app(Dashboard::class);
        $filtersSessionKey = $page->getFiltersSessionKey();

        session([
            'dashboard_market_id' => (int) $market->id,
            'dashboard_month' => $dashboardMonth,
            'dashboard_period' => $dashboardMonth . '-01',
            $filtersSessionKey => ['month' => $filtersMonth],
        ]);

        if ($monthMode !== null) {
            session(['dashboard_month_mode' => $monthMode]);
        }

        $page->filters = ['month' => $filtersMonth];

        return [$page, $filtersSessionKey];
    }
}
