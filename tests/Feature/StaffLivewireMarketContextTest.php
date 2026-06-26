<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Admin\OnlineStaffRail;
use App\Livewire\Admin\QuickChatDrawer;
use App\Livewire\Admin\StaffLiveFeed;
use ReflectionMethod;
use Tests\TestCase;

class StaffLivewireMarketContextTest extends TestCase
{
    public function test_staff_livewire_components_read_market_through_market_context(): void
    {
        foreach ([
            StaffLiveFeed::class,
            OnlineStaffRail::class,
            QuickChatDrawer::class,
        ] as $className) {
            $methodSource = $this->methodSource($className, 'resolveMarketId');

            self::assertStringContainsString('app(MarketContext::class)->currentMarketId($user)', $methodSource);
            self::assertStringNotContainsString("session('dashboard_market_id')", $methodSource);
            self::assertStringNotContainsString('session("filament.{$panelId}.selected_market_id")', $methodSource);
            self::assertStringNotContainsString('session("filament_{$panelId}_market_id")', $methodSource);
            self::assertStringNotContainsString("session('filament.admin.selected_market_id')", $methodSource);
        }
    }

    /**
     * @param class-string $className
     */
    private function methodSource(string $className, string $methodName): string
    {
        $method = new ReflectionMethod($className, $methodName);
        $fileName = $method->getFileName();

        self::assertIsString($fileName);

        $lines = file($fileName);

        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
