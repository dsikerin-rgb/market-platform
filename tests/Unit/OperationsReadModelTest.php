<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\OperationType;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Services\Operations\OperationsStateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_switch_picks_latest_before_period_end(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-10',
            'status' => 'occupied',
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => '2025-12-10 00:00:00',
            'effective_tz' => 'Europe/Moscow',
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'from_tenant_id' => null,
                'to_tenant_id' => 12,
            ],
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => '2026-01-15 00:00:00',
            'effective_tz' => 'Europe/Moscow',
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'from_tenant_id' => 12,
                'to_tenant_id' => 15,
            ],
        ]);

        $service = app(OperationsStateService::class);
        $period = CarbonImmutable::create(2025, 12, 1, 0, 0, 0, 'Europe/Moscow');
        $state = $service->getSpaceStateForPeriod((int) $market->id, $period, (int) $space->id);

        $this->assertSame(12, $state['tenant_id']);
    }

    public function test_electricity_is_summed_within_period(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'B-1',
            'status' => 'occupied',
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::ELECTRICITY_INPUT,
            'effective_at' => '2026-01-05 00:00:00',
            'effective_tz' => 'Europe/Moscow',
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'amount' => 100,
            ],
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::ELECTRICITY_INPUT,
            'effective_at' => '2026-01-20 00:00:00',
            'effective_tz' => 'Europe/Moscow',
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'amount' => 50,
            ],
        ]);

        $service = app(OperationsStateService::class);
        $period = CarbonImmutable::create(2026, 1, 1, 0, 0, 0, 'Europe/Moscow');
        $state = $service->getSpaceStateForPeriod((int) $market->id, $period, (int) $space->id);

        $this->assertSame(150.0, $state['electricity']);
    }
}
