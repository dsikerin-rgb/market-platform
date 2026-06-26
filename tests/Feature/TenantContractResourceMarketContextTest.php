<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantContractResource\Pages\ListTenantContracts;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class TenantContractResourceMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tenant_contract_resource_reads_selected_market_through_market_context(): void
    {
        $marketId = 52525;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resourceSelectedMarketId());
    }

    public function test_tenant_contract_resource_keeps_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 62626;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resourceSelectedMarketId());
        self::assertSame($marketId, $this->listPageSelectedMarketId());
    }

    public function test_tenant_contract_list_page_keeps_dashboard_market_session_key(): void
    {
        $marketId = 72727;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->listPageSelectedMarketId());
    }

    public function test_tenant_contract_sources_use_market_context_session_lookup(): void
    {
        foreach ([
            app_path('Filament/Resources/TenantContractResource.php'),
            app_path('Filament/Resources/TenantContractResource/Pages/ListTenantContracts.php'),
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
        $method = new ReflectionMethod(TenantContractResource::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function listPageSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(ListTenantContracts::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(new ListTenantContracts);
    }
}
