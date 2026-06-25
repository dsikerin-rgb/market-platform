<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OpsDiagnostics;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class OpsDiagnosticsMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_ops_diagnostics_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 515151;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_ops_diagnostics_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 616161;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_ops_diagnostics_keeps_legacy_filament_admin_selected_market_session_key(): void
    {
        $marketId = 717171;

        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_ops_diagnostics_keeps_legacy_filament_underscore_market_session_key(): void
    {
        $marketId = 818181;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    private function resolvedMarketId(): int
    {
        $method = new ReflectionMethod(OpsDiagnostics::class, 'selectedMarketId');
        $method->setAccessible(true);

        return (int) $method->invoke(app(OpsDiagnostics::class));
    }
}
