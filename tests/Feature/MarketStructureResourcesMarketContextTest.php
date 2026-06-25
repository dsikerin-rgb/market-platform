<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketLocationResource;
use App\Filament\Resources\MarketLocationResource\Pages\CreateMarketLocation;
use App\Filament\Resources\MarketLocationResource\Pages\EditMarketLocation;
use App\Filament\Resources\MarketSpaceGroupEpisodeResource;
use App\Models\User;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketStructureResourcesMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_market_structure_resources_read_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 22446;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(MarketLocationResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(MarketSpaceGroupEpisodeResource::class));
    }

    public function test_market_location_pages_use_market_context_session_keys(): void
    {
        $marketId = 91917;

        $this->actingAsSuperAdmin();
        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->mutateCreateMarketLocationData(['market_id' => null])['market_id']);
        self::assertSame($marketId, $this->mutateEditMarketLocationData(['market_id' => 12345])['market_id']);
    }

    public function test_market_location_pages_keep_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 71919;

        $this->actingAsSuperAdmin();
        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->mutateCreateMarketLocationData([])['market_id']);
        self::assertSame($marketId, $this->mutateEditMarketLocationData(['market_id' => 12345])['market_id']);
    }

    public function test_market_structure_resources_keep_legacy_filament_panel_market_session_key(): void
    {
        $marketId = 66442;

        session(['filament_admin_market_id' => $marketId]);

        self::assertSame($marketId, $this->resolvedMarketId(MarketLocationResource::class));
        self::assertSame($marketId, $this->resolvedMarketId(MarketSpaceGroupEpisodeResource::class));
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

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mutateCreateMarketLocationData(array $data): array
    {
        $method = new ReflectionMethod(CreateMarketLocation::class, 'mutateFormDataBeforeCreate');
        $method->setAccessible(true);

        return $method->invoke(new CreateMarketLocation, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function mutateEditMarketLocationData(array $data): array
    {
        $method = new ReflectionMethod(EditMarketLocation::class, 'mutateFormDataBeforeSave');
        $method->setAccessible(true);

        return $method->invoke(new EditMarketLocation, $data);
    }

    private function actingAsSuperAdmin(): void
    {
        $user = new class extends User
        {
            public function isSuperAdmin(): bool
            {
                return true;
            }
        };

        $user->id = 9001;
        $user->exists = true;

        $this->actingAs($user, Filament::getAuthGuard());
    }
}
