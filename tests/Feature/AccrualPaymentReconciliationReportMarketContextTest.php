<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\OneC\AccrualPaymentReconciliationReport;
use Filament\Facades\Filament;
use Tests\TestCase;

class AccrualPaymentReconciliationReportMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_super_admin_report_uses_market_context_session_keys(): void
    {
        $superAdmin = new class
        {
            public ?int $market_id = null;

            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        foreach ([
            'selected_market_id' => 1001,
            'dashboard_market_id' => 1002,
            'filament.admin.selected_market_id' => 1003,
            'filament_admin_market_id' => 1004,
        ] as $key => $marketId) {
            session()->flush();
            session([$key => $marketId]);

            self::assertSame($marketId, $this->resolveMarketIdForUser($superAdmin));
        }
    }

    public function test_regular_user_report_uses_user_market_over_selected_session(): void
    {
        $user = new class
        {
            public int $market_id = 2001;

            public function isSuperAdmin(): bool
            {
                return false;
            }
        };

        session(['selected_market_id' => 2002]);

        self::assertSame(2001, $this->resolveMarketIdForUser($user));
    }

    public function test_report_resolver_no_longer_reads_legacy_session_keys_directly(): void
    {
        $source = (string) file_get_contents(app_path('Support/OneC/AccrualPaymentReconciliationReport.php'));
        $start = strpos($source, 'public function resolveMarketIdForUser(mixed $user): ?int');
        $end = is_int($start) ? strpos($source, '/**', $start + 1) : false;
        $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

        self::assertNotSame('', $methodSource);
        self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $methodSource);
        self::assertStringNotContainsString("session('dashboard_market_id')", $methodSource);
        self::assertStringNotContainsString('session("filament.{$panelId}.selected_market_id")', $methodSource);
        self::assertStringNotContainsString('session("filament_{$panelId}_market_id")', $methodSource);
        self::assertStringNotContainsString("session('filament.admin.selected_market_id')", $methodSource);

        self::assertStringContainsString("Market::query()\n            ->orderBy('id')", $methodSource);
    }

    private function resolveMarketIdForUser(object $user): ?int
    {
        return app(AccrualPaymentReconciliationReport::class)->resolveMarketIdForUser($user);
    }
}
