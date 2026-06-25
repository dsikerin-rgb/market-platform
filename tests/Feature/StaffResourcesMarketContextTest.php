<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Staff\StaffResource;
use App\Filament\Resources\Staff\Schemas\StaffForm;
use App\Filament\Resources\StaffInvitationResource;
use App\Filament\Resources\StaffInvitationResource\Schemas\StaffInvitationForm;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class StaffResourcesMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_staff_resources_read_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 11223;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(StaffResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(StaffForm::class));
        self::assertSame($marketId, $this->resolvedMarketId(StaffInvitationResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(StaffInvitationForm::class));
    }

    public function test_staff_resources_keep_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 33211;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(StaffResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(StaffForm::class));
        self::assertSame($marketId, $this->resolvedMarketId(StaffInvitationResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(StaffInvitationForm::class));
    }

    /**
     * @param class-string $className
     */
    private function resolvedMarketId(string $className): ?int
    {
        $method = new ReflectionMethod($className, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
