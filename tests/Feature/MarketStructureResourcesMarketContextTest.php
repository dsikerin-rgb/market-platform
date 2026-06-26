<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketLocationResource;
use App\Filament\Resources\MarketLocationResource\Pages\CreateMarketLocation;
use App\Filament\Resources\MarketLocationResource\Pages\EditMarketLocation;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\MarketSpaceResource\Pages\CreateMarketSpace;
use App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace;
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
        self::assertSame($marketId, $this->resolvedMarketId(MarketSpaceResource::class));
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
        self::assertSame($marketId, $this->resolvedMarketId(MarketSpaceResource::class));
    }

    public function test_market_space_resource_uses_market_context_session_lookup(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/MarketSpaceResource.php'));
        $start = strpos($source, 'protected static function selectedMarketIdFromSession(): ?int');
        $end = is_int($start) ? strpos($source, '/**', $start) : false;
        $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

        self::assertNotSame('', $methodSource);
        self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $methodSource);
        self::assertStringNotContainsString('Filament::getCurrentPanel()?->getId()', $methodSource);
        self::assertStringNotContainsString('session($key)', $methodSource);
    }

    public function test_create_market_space_syncs_selected_market_through_market_context(): void
    {
        $marketId = 81828;

        $this->storeCreateMarketSpaceSelectedMarketId($marketId);

        foreach ([
            'dashboard_market_id',
            'filament.admin.selected_market_id',
            'filament_admin_market_id',
            'selected_market_id',
        ] as $key) {
            self::assertSame($marketId, session($key));
        }

        $source = (string) file_get_contents(app_path('Filament/Resources/MarketSpaceResource/Pages/CreateMarketSpace.php'));
        $start = strpos($source, 'private function storeSelectedMarketIdInSession(?int $marketId): void');
        $end = is_int($start) ? strpos($source, 'private function normalizeReturnUrl', $start) : false;
        $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

        self::assertNotSame('', $methodSource);
        self::assertStringContainsString('app(MarketContext::class)->syncSelectedMarketIdInSession($marketId)', $methodSource);
        self::assertStringNotContainsString('session(["filament_{$panelId}_market_id" => $marketId])', $methodSource);
    }

    public function test_edit_market_space_reads_selected_market_through_market_context(): void
    {
        $source = (string) file_get_contents(app_path('Filament/Resources/MarketSpaceResource/Pages/EditMarketSpace.php'));
        $start = strpos($source, 'protected function mutateFormDataBeforeSave(array $data): array');
        $end = is_int($start) ? strpos($source, 'private function prepareParentGroupMapShapeResolution', $start) : false;
        $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

        self::assertNotSame('', $methodSource);
        self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $methodSource);
        self::assertStringNotContainsString("session('filament.admin.selected_market_id')", $methodSource);
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

    private function storeCreateMarketSpaceSelectedMarketId(?int $marketId): void
    {
        $method = new ReflectionMethod(CreateMarketSpace::class, 'storeSelectedMarketIdInSession');
        $method->setAccessible(true);
        $method->invoke(new CreateMarketSpace, $marketId);
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
