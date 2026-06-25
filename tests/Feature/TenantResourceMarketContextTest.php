<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantResource;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class TenantResourceMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_tenant_resource_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 56565;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_tenant_resource_keeps_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 65656;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(TenantResource::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
