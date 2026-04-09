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

    public function test_booted_ignores_stale_persisted_dashboard_filter_session_and_uses_latest_month(): void
    {
        [$page, $filtersSessionKey] = $this->bootstrapDashboardPageWithLatestMonth(
            dashboardMonth: '2026-03',
            filtersMonth: '2026-03',
            activeFilters: null,
        );

        $page->mountHasFilters();
        $this->invokeDashboardHook($page, 'booted');

        $this->assertSame('2026-04', $page->filters['month'] ?? null);
        $this->assertSame('2026-04', session('dashboard_month'));
        $this->assertSame('2026-04-01', session('dashboard_period'));
        $this->assertNull(session('dashboard_month_mode'));
        $this->assertFalse(session()->has($filtersSessionKey));

        Carbon::setTestNow();
    }

    public function test_booted_preserves_explicit_historical_month_from_current_page_state(): void
    {
        [$page, $filtersSessionKey] = $this->bootstrapDashboardPageWithLatestMonth(
            dashboardMonth: '2026-03',
            filtersMonth: '2026-03',
            activeFilters: ['month' => '2026-03'],
        );

        $page->mountHasFilters();
        $this->invokeDashboardHook($page, 'booted');

        $this->assertSame('2026-03', $page->filters['month'] ?? null);
        $this->assertSame('2026-03', session('dashboard_month'));
        $this->assertSame('2026-03-01', session('dashboard_period'));
        $this->assertNull(session('dashboard_month_mode'));
        $this->assertFalse(session()->has($filtersSessionKey));

        Carbon::setTestNow();
    }

    /**
     * @return array{0: Dashboard, 1: string}
     */
    private function bootstrapDashboardPageWithLatestMonth(
        string $dashboardMonth,
        string $filtersMonth,
        ?array $activeFilters,
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
            'source_row_hash' => hash('sha256', 'dashboard-month-filter-' . $dashboardMonth . '-' . ($activeFilters['month'] ?? 'latest')),
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

        $page->filters = $activeFilters;

        return [$page, $filtersSessionKey];
    }

    private function invokeDashboardHook(Dashboard $page, string $method): void
    {
        $reflection = new \ReflectionMethod($page, $method);
        $reflection->invoke($page);
    }
}
