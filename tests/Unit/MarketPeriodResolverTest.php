<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Market;
use App\Services\Operations\MarketPeriodResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketPeriodResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_default_period_in_market_timezone(): void
    {
        CarbonImmutable::setTestNow('2026-01-15 12:00:00');

        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $resolver = app(MarketPeriodResolver::class);
        $period = $resolver->resolveMarketPeriod($market, null);

        $this->assertSame('2026-01-01', $period->toDateString());
    }

    public function test_normalizes_input_period(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Bucharest',
        ]);

        $resolver = app(MarketPeriodResolver::class);
        $period = $resolver->resolveMarketPeriod($market, '2025-12-01');

        $this->assertSame('2025-12-01', $period->toDateString());
    }
}
