<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantAccruals\Pages\ListTenantAccruals;
use App\Filament\Resources\TenantAccruals\Tables\TenantAccrualsTable;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class TenantAccrualsMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tenant_accrual_resource_reads_selected_market_through_market_context(): void
    {
        $marketId = 82828;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resourceSelectedMarketId());
    }

    public function test_tenant_accrual_table_keeps_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 92929;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->tableSelectedMarketId());
    }

    public function test_tenant_accrual_list_page_keeps_dashboard_market_session_key(): void
    {
        $marketId = 13131;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->listPageSelectedMarketId());
    }

    public function test_tenant_accrual_sources_use_market_context_session_lookup(): void
    {
        foreach ([
            app_path('Filament/Resources/TenantAccruals/TenantAccrualResource.php'),
            app_path('Filament/Resources/TenantAccruals/Tables/TenantAccrualsTable.php'),
            app_path('Filament/Resources/TenantAccruals/Pages/ListTenantAccruals.php'),
        ] as $path) {
            $source = (string) file_get_contents($path);
            $start = strpos($source, 'function selectedMarketIdFromSession(): ?int');
            $end = is_int($start) ? strpos($source, "\n    }", $start) : false;
            $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

            self::assertNotSame('', $methodSource);
            self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $methodSource);
            self::assertStringNotContainsString('Filament::getCurrentPanel()?->getId()', $methodSource);
            self::assertStringNotContainsString('session($key)', $methodSource);
        }
    }

    private function resourceSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(TenantAccrualResource::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function tableSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(TenantAccrualsTable::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function listPageSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(ListTenantAccruals::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(new ListTenantAccruals);
    }
}
