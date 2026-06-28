<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

class BackfillMarketSpaceGroupEpisodesCommandMarketContextSourceTest extends TestCase
{
    public function test_backfill_market_space_group_episodes_wraps_market_filtered_execution_in_market_context(): void
    {
        $source = file_get_contents(app_path('Console/Commands/BackfillMarketSpaceGroupEpisodesCommand.php'));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('if ($marketId !== null) {', $source);
        self::assertStringContainsString('return app(MarketContext::class)->withMarket(', $source);
        self::assertStringContainsString('return $this->backfillGroupEpisodes(null, $validFrom, $previewLimit, $includeInactive, $apply);', $source);
    }
}
