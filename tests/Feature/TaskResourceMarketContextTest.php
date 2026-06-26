<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TaskResource;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class TaskResourceMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_task_resource_reads_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 45454;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_task_resource_keeps_legacy_filament_admin_market_session_key(): void
    {
        $marketId = 56565;

        session(['filament.admin.market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId());
    }

    public function test_task_resource_source_uses_market_context_session_lookup(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/TaskResource.php'));
        $start = strpos($source, 'protected static function selectedMarketIdFromSession(): ?int');
        $end = is_int($start) ? strpos($source, "\n    }", $start) : false;
        $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

        self::assertNotSame('', $methodSource);
        self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $methodSource);
        self::assertStringNotContainsString('Filament::getCurrentPanel()?->getId()', $methodSource);
        self::assertStringNotContainsString('session(', $methodSource);
    }

    private function resolvedMarketId(): ?int
    {
        $method = new ReflectionMethod(TaskResource::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
