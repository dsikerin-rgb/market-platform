<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\TaskCalendarFilters;
use Filament\Facades\Filament;
use Tests\TestCase;

class TaskCalendarFiltersMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 2468;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, TaskCalendarFilters::resolveMarketIdForUser($this->superAdmin()));
    }

    public function test_super_admin_keeps_legacy_dashboard_market_session_key(): void
    {
        $marketId = 8642;

        session(['dashboard_market_id' => $marketId]);

        self::assertSame($marketId, TaskCalendarFilters::resolveMarketIdForUser($this->superAdmin()));
    }

    public function test_market_user_keeps_own_market_id(): void
    {
        session(['selected_market_id' => 1111]);

        self::assertSame(2222, TaskCalendarFilters::resolveMarketIdForUser($this->marketUser(2222)));
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
