<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Support\MarketContext;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class DashboardMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_dashboard_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 321;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_dashboard_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 654;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_dashboard_has_no_selected_context_when_session_is_empty(): void
    {
        self::assertNull($this->resolvedMarketId());
    }

    public function test_dashboard_syncs_selected_market_through_market_context(): void
    {
        $marketId = 87878;

        $this->actingAs($this->superAdmin(), Filament::getAuthGuard());

        session(['selected_market_id' => $marketId]);

        $this->invokeDashboardMethod('syncDashboardMarketId');

        foreach ($this->marketSessionKeys() as $key) {
            self::assertSame($marketId, session($key));
        }

        self::assertSame($marketId, app(MarketContext::class)->selectedMarketIdFromSession('admin'));
    }

    public function test_dashboard_source_uses_market_context_session_sync(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Pages/Dashboard.php'));
        $start = strpos($source, 'function syncDashboardMarketId(): void');
        $end = is_int($start) ? strpos($source, "\n    }", $start) : false;
        $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

        self::assertNotSame('', $methodSource);
        self::assertStringContainsString('app(MarketContext::class)->syncSelectedMarketIdInSession($marketId, $panelId)', $methodSource);
        self::assertStringNotContainsString("session(['dashboard_market_id' => \$marketId])", $methodSource);
        self::assertStringNotContainsString('session(["filament.{$panelId}.selected_market_id" => $marketId])', $methodSource);
        self::assertStringNotContainsString('session(["filament_{$panelId}_market_id" => $marketId])', $methodSource);
        self::assertStringNotContainsString("session(['filament.admin.selected_market_id' => \$marketId])", $methodSource);
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(Dashboard::class, 'resolveSelectedMarketIdFromContext');
        $method->setAccessible(true);

        return $method->invoke(new Dashboard);
    }

    private function invokeDashboardMethod(string $methodName): mixed
    {
        $method = new ReflectionMethod(Dashboard::class, $methodName);
        $method->setAccessible(true);

        return $method->invoke(new Dashboard);
    }

    private function superAdmin(): User
    {
        return new class extends User
        {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
    }

    /**
     * @return list<string>
     */
    private function marketSessionKeys(): array
    {
        return [
            'dashboard_market_id',
            'filament.admin.selected_market_id',
            'filament_admin_market_id',
            'selected_market_id',
        ];
    }
}
