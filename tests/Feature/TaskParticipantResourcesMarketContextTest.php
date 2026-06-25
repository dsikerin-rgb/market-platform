<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TaskCommentResource;
use App\Filament\Resources\TaskWatcherResource;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class TaskParticipantResourcesMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_task_participant_resources_read_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 12345;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(TaskCommentResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(TaskWatcherResource::class));
    }

    public function test_task_participant_resources_keep_legacy_filament_panel_selected_market_session_key(): void
    {
        $marketId = 54321;

        session(['filament.admin.selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(TaskCommentResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(TaskWatcherResource::class));
    }

    /**
     * @param class-string $resourceClass
     */
    private function resolvedMarketId(string $resourceClass): ?int
    {
        $method = new ReflectionMethod($resourceClass, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
