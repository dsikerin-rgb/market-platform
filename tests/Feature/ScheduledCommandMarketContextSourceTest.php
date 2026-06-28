<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduledCommandMarketContextSourceTest extends TestCase
{
    #[DataProvider('scheduledCommandFiles')]
    public function test_scheduled_command_sets_market_context_for_market_work(string $path): void
    {
        $source = file_get_contents(app_path($path));

        self::assertIsString($source);
        self::assertStringContainsString('use App\Support\MarketContext;', $source);
        self::assertStringContainsString('app(MarketContext::class)->withMarket', $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scheduledCommandFiles(): array
    {
        return [
            'holiday csv sync' => ['Console/Commands/SyncMarketHolidays.php'],
            'sanitary day generation' => ['Console/Commands/GenerateSanitaryDays.php'],
            'calendar task generation' => ['Console/Commands/GenerateCalendarTasks.php'],
            'holiday notifications' => ['Console/Commands/NotifyMarketHolidays.php'],
            'operation snapshot rebuild' => ['Console/Commands/RebuildMarketSpaceSnapshotsFromOperations.php'],
        ];
    }
}
