<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OperationResource;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\TestCase;

class OperationResourceMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_operation_resource_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 525252;

        $this->actingAs($this->superAdmin());
        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, OperationResource::resolveMarketId());
    }

    public function test_operation_resource_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 626262;

        $this->actingAs($this->superAdmin());
        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, OperationResource::resolveMarketId());
    }

    public function test_operation_resource_keeps_legacy_filament_admin_selected_market_session_key(): void
    {
        $marketId = 727272;

        $this->actingAs($this->superAdmin());
        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, OperationResource::resolveMarketId());
    }

    public function test_operation_resource_keeps_legacy_filament_underscore_market_session_key(): void
    {
        $marketId = 828282;

        $this->actingAs($this->superAdmin());
        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, OperationResource::resolveMarketId());
    }

    public function test_operation_resource_keeps_market_user_own_market_id(): void
    {
        $this->actingAs($this->marketUser(929292));
        session(['selected_market_id' => 191919]);

        self::assertSame(929292, OperationResource::resolveMarketId());
    }

    public function test_operation_resource_returns_zero_without_user(): void
    {
        session(['selected_market_id' => 393939]);

        self::assertSame(0, OperationResource::resolveMarketId());
    }

    private function superAdmin(): User
    {
        return new class extends User {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };
    }

    private function marketUser(int $marketId): User
    {
        $user = new class extends User {
            public function isSuperAdmin(): bool
            {
                return false;
            }
        };

        $user->market_id = $marketId;

        return $user;
    }
}
