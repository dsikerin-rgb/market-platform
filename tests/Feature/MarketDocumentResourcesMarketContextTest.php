<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketDocumentActivityEventResource;
use App\Filament\Resources\MarketDocumentResource;
use App\Filament\Resources\MarketDocumentResource\Pages\ListMarketDocuments;
use Filament\Facades\Filament;
use ReflectionMethod;
use Tests\TestCase;

class MarketDocumentResourcesMarketContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        session()->flush();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_document_resources_read_selected_market_through_market_context_session_keys(): void
    {
        $marketId = 24242;

        session(['selected_market_id' => $marketId]);

        self::assertSame($marketId, $this->documentResourceSelectedMarketId());
        self::assertSame($marketId, $this->listPageSelectedMarketId());
        self::assertSame($marketId, $this->activityResourceSelectedMarketId());
    }

    public function test_document_resources_keep_legacy_filament_admin_market_session_key(): void
    {
        $marketId = 34343;

        session(['filament.admin.market_id' => $marketId]);

        self::assertSame($marketId, $this->documentResourceSelectedMarketId());
        self::assertSame($marketId, $this->listPageSelectedMarketId());
        self::assertSame($marketId, $this->activityResourceSelectedMarketId());
    }

    public function test_document_sources_use_market_context_session_lookup(): void
    {
        foreach ([
            app_path('Filament/Resources/MarketDocumentResource.php'),
            app_path('Filament/Resources/MarketDocumentResource/Pages/ListMarketDocuments.php'),
            app_path('Filament/Resources/MarketDocumentActivityEventResource.php'),
        ] as $path) {
            $source = (string) file_get_contents($path);
            $start = strpos($source, 'function selectedMarketIdFromSession(): ?int');
            $end = is_int($start) ? strpos($source, "\n    }", $start) : false;
            $methodSource = (is_int($start) && is_int($end)) ? substr($source, $start, $end - $start) : '';

            self::assertNotSame('', $methodSource);
            self::assertStringContainsString('app(MarketContext::class)->selectedMarketIdFromSession()', $methodSource);
            self::assertStringNotContainsString('Filament::getCurrentPanel()?->getId()', $methodSource);
            self::assertStringNotContainsString('session(', $methodSource);
        }
    }

    private function documentResourceSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketDocumentResource::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }

    private function listPageSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(ListMarketDocuments::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(new ListMarketDocuments);
    }

    private function activityResourceSelectedMarketId(): ?int
    {
        $method = new ReflectionMethod(MarketDocumentActivityEventResource::class, 'selectedMarketIdFromSession');
        $method->setAccessible(true);

        return $method->invoke(null);
    }
}
