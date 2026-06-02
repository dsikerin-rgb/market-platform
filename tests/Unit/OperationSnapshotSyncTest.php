<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OperationSnapshotSyncTest extends TestCase
{
    use DatabaseTransactions;

    public function test_applied_operations_update_space_snapshot_and_canceled_do_not_override_it(): void
    {
        $market = Market::create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenantA = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant One',
        ]);

        $tenantB = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Two',
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
                'activity_type' => 'Vegetables',
                'number' => 'A-2',
                'display_name' => 'Shop A-2',
                'status' => 'maintenance',
                'is_active' => true,
            ],
        ]);

        $space->refresh();
        $this->assertSame('42.50', (string) $space->area_sqm);
        $this->assertSame('Vegetables', $space->activity_type);
        $this->assertSame('A-2', $space->number);
        $this->assertSame('Shop A-2', $space->display_name);
        $this->assertSame('maintenance', $space->status);
        $this->assertTrue((bool) $space->is_active);
    }

    public function test_rebuild_uses_later_created_review_closure_even_if_effective_at_is_backdated(): void
    {
        $market = Market::create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenantA = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant One',
        ]);

        $tenantB = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Two',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantA->id,
            'number' => 'A-3',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => CarbonImmutable::parse('2026-04-22 07:35:48', 'UTC'),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2026-04-22 07:35:48', 'UTC'),
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'reason' => 'Observed tenant mismatch',
                'observed_tenant_name' => 'Tenant Two',
            ],
        ]);

        $closingTenantSwitch = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => CarbonImmutable::parse('2026-04-30 18:00:00', 'UTC'),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'from_tenant_id' => $tenantA->id,
                'to_tenant_id' => $tenantB->id,
                'reason' => 'Confirmed by review',
                'review_close_on_effective_at' => true,
            ],
        ]);

        $closingMatchedReview = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2025-12-31 05:00:00', 'UTC'),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => 'matched',
            ],
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame($tenantB->id, $space->tenant_id);
        $this->assertSame('matched', $space->map_review_status);
        $this->assertSame(
            $closingMatchedReview->effective_at?->toDateTimeString(),
            $space->map_reviewed_at?->toDateTimeString()
        );
    }

    public function test_rebuild_keeps_retired_space_closed_even_if_older_effective_date_is_used(): void
    {
        $market = Market::create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant One',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'A-4',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => CarbonImmutable::parse('2026-05-28 08:19:48', 'UTC'),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2026-05-28 08:19:48', 'UTC'),
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Removed place',
            ],
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2025-12-31 11:00:00', 'UTC'),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::RETIRE_SPACE,
                'reason' => 'Removed place',
                'effective_date' => '2026-01-01',
            ],
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('changed', $space->map_review_status);
    }

    public function test_rebuild_closes_free_occupancy_observation_when_space_is_already_vacant(): void
    {
        $market = Market::create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => null,
            'number' => 'A-5',
            'status' => 'vacant',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => CarbonImmutable::parse('2026-05-22 11:19:04', 'UTC'),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2026-05-22 11:19:04', 'UTC'),
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Место свободно',
            ],
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('matched', $space->map_review_status);
        $this->assertSame('vacant', $space->status);
        $this->assertNull($space->tenant_id);
    }

    public function test_rebuild_closes_attention_observation_for_inactive_space(): void
    {
        $market = Market::create([
            'name' => 'Test market',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant One',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'A-6',
            'status' => 'occupied',
            'is_active' => false,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => CarbonImmutable::parse('2026-05-28 08:15:16', 'UTC'),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2026-05-28 08:15:16', 'UTC'),
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Observed tenant mismatch',
            ],
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('matched', $space->map_review_status);
        $this->assertFalse((bool) $space->is_active);
    }
}
