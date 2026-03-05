<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\OperationType;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationSnapshotSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_applied_operations_update_space_snapshot_and_canceled_do_not_override_it(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenantA = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Первый',
        ]);

        $tenantB = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Второй',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantA->id,
            'number' => 'A-1',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => CarbonImmutable::now('UTC')->subDay(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'from_tenant_id' => $tenantA->id,
                'to_tenant_id' => $tenantB->id,
            ],
        ]);

        $space->refresh();
        $this->assertSame($tenantB->id, $space->tenant_id);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => CarbonImmutable::now('UTC')->subHours(12),
            'status' => 'canceled',
            'payload' => [
                'market_space_id' => $space->id,
                'from_tenant_id' => $tenantB->id,
                'to_tenant_id' => $tenantA->id,
            ],
        ]);

        $space->refresh();
        $this->assertSame($tenantB->id, $space->tenant_id);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'effective_at' => CarbonImmutable::now('UTC')->subHours(1),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'area_sqm' => 42.5,
                'activity_type' => 'Овощи',
                'is_active' => true,
            ],
        ]);

        $space->refresh();
        $this->assertSame('42.50', (string) $space->area_sqm);
        $this->assertSame('Овощи', $space->activity_type);
        $this->assertTrue((bool) $space->is_active);
    }
}

