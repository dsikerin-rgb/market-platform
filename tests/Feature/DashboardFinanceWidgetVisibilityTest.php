<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AccrualCompositionWidget;
use App\Filament\Widgets\MarketOverviewStatsWidget;
use App\Filament\Widgets\OneCAccrualPaymentReconciliationWidget;
use App\Filament\Widgets\OneCPaymentsSummaryWidget;
use App\Filament\Widgets\RevenueYearChartWidget;
use App\Models\Market;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DashboardFinanceWidgetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_map_only_user_does_not_see_financial_dashboard_widgets(): void
    {
        $market = $this->createMarket();
        $user = $this->createUserWithPermission($market, 'markets.view');

        $this->actingAsFilamentUser($user);

        self::assertFalse(AccrualCompositionWidget::canView());
        self::assertFalse(OneCAccrualPaymentReconciliationWidget::canView());
        self::assertFalse(OneCPaymentsSummaryWidget::canView());
        self::assertFalse(RevenueYearChartWidget::canView());

        $labels = $this->overviewStatLabels();

        self::assertNotContains('Средняя ставка, ₽/м²', $labels);
        self::assertNotContains('Начислено за месяц', $labels);
        self::assertNotContains('Оплачено за месяц', $labels);
        self::assertNotContains('Долг на конец месяца', $labels);

        $dashboard = new Dashboard();
        $hero = $dashboard->getWorkspaceHeroData();

        self::assertNotContains('Начисления', array_column($hero['links'], 'title'));
    }

    public function test_finance_user_sees_financial_dashboard_widgets(): void
    {
        $market = $this->createMarket();
        $user = $this->createUserWithPermission($market, 'finance.accruals.view');

        $this->actingAsFilamentUser($user);

        self::assertTrue(AccrualCompositionWidget::canView());
        self::assertTrue(OneCAccrualPaymentReconciliationWidget::canView());
        self::assertTrue(OneCPaymentsSummaryWidget::canView());
        self::assertTrue(RevenueYearChartWidget::canView());

        $labels = $this->overviewStatLabels();

        self::assertContains('Средняя ставка, ₽/м²', $labels);
        self::assertContains('Начислено за месяц', $labels);
        self::assertContains('Оплачено за месяц', $labels);
        self::assertContains('Долг на конец месяца', $labels);

        $dashboard = new Dashboard();
        $hero = $dashboard->getWorkspaceHeroData();

        self::assertContains('Начисления', array_column($hero['links'], 'title'));
    }

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
    }

    private function createUserWithPermission(Market $market, string $permissionName): User
    {
        Permission::findOrCreate($permissionName, 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => str_replace('.', '-', $permissionName) . '-' . uniqid('', true) . '@example.test',
        ]);

        $user->givePermissionTo($permissionName);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function actingAsFilamentUser(User $user): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($user, Filament::getAuthGuard());
    }

    /**
     * @return list<string>
     */
    private function overviewStatLabels(): array
    {
        $widget = new MarketOverviewStatsWidget();
        $method = new ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        return array_map(
            static fn ($stat): string => (string) $stat->getLabel(),
            $method->invoke($widget),
        );
    }
}
