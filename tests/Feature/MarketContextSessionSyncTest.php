<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\MarketContext;
use Filament\Facades\Filament;
use Tests\TestCase;

class MarketContextSessionSyncTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_it_syncs_selected_market_to_all_context_session_keys(): void
    {
        session([
            'dashboard_market_id' => 101,
            'filament.admin.selected_market_id' => 101,
        ]);

        app(MarketContext::class)->syncSelectedMarketIdInSession(202, 'admin');

        foreach ($this->marketSessionKeys() as $key) {
            self::assertSame(202, session($key));
        }

        self::assertSame(202, app(MarketContext::class)->selectedMarketIdFromSession('admin'));
    }

    public function test_it_clears_all_context_session_keys(): void
    {
        session(array_fill_keys($this->marketSessionKeys(), 303));

        app(MarketContext::class)->syncSelectedMarketIdInSession(null, 'admin');

        foreach ($this->marketSessionKeys() as $key) {
            self::assertFalse(session()->has($key));
        }

        self::assertNull(app(MarketContext::class)->selectedMarketIdFromSession('admin'));
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
