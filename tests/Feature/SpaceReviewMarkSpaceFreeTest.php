<?php
# tests/Feature/SpaceReviewMarkSpaceFreeTest.php

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpaceReviewMarkSpaceFreeTest extends TestCase
{
    use RefreshDatabase;

    private function createMarket(array $attributes = []): Market
    {
        return Market::create(array_merge([
            'name' => 'Test Market',
            'slug' => 'test-market-' . uniqid(),
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ], $attributes));
    }

    private function createSpace(Market $market, array $attributes = []): MarketSpace
    {
        return MarketSpace::create(array_merge([
            'market_id' => $market->id,
            'number' => 'TEST-' . rand(1000, 9999),
            'status' => 'occupied',
            'is_active' => true,
        ], $attributes));
    }

    private function actingAsSuperAdmin(?int $marketId = null): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => $marketId,
        ]);
        $user->assignRole('super-admin');

        $this->actingAs($user, 'web');

        if (! config('auth.guards.filament')) {
            config()->set('auth.guards.filament', [
                'driver' => 'session',
                'provider' => 'users',
            ]);
        }

        $this->actingAs($user, 'filament');

        return $user;
    }

    private function withCsrfToken()
    {
        $token = csrf_token();
        return $this->withHeaders([
            'X-CSRF-TOKEN' => $token,
        ]);
    }

    /**
     * Тест: mark_space_free реально освобождает место (очищает tenant_id)
     * Проверяет баг П75: operation applied, но место всё ещё показывает арендатора
     */
    public function test_mark_space_free_clears_tenant_id_and_status(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'display_name' => 'Тестовый Арендатор',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        // До: место занято
        $this->assertSame($tenant->id, $space->tenant_id);
        $this->assertSame('occupied', $space->status);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk();

        // Проверяем operation
        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame('applied', $operation->status);
        $this->assertSame(SpaceReviewDecision::MARK_SPACE_FREE, $operation->payload['decision'] ?? null);
        $this->assertSame($space->id, $operation->payload['market_space_id'] ?? null);

        $space->refresh();

        // После: место реально освобождено
        $this->assertSame('vacant', $space->status, 'status должен стать vacant');
        $this->assertNull($space->tenant_id, 'tenant_id должен быть очищен (баг П75)');
        $this->assertFalse($space->isEffectivelyOccupied(), 'место не должно быть занятым');
        $this->assertSame('none', $space->effectiveOccupancySource());
    }

    /**
     * Тест: после MARK_SPACE_FREE место не считается занятым старым tenant_id
     * Проверяет, что fix не вызывает откат к старому tenant_id
     */
    public function test_space_is_not_seen_as_occupied_after_mark_space_free(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        // Применяем MARK_SPACE_FREE
        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk();

        $space->refresh();

        // Проверяем, что место освобождено и нет отката к старому tenant_id
        $this->assertNull($space->tenant_id, 'tenant_id должен быть null');
        $this->assertSame('vacant', $space->status, 'status должен быть vacant');
        $this->assertFalse($space->isEffectivelyOccupied(), 'isEffectivelyOccupied должен быть false');
        $this->assertSame('none', $space->effectiveOccupancySource(), 'effectiveOccupancySource должен быть none');

        // Проверяем operation
        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame(SpaceReviewDecision::MARK_SPACE_FREE, $operation->payload['decision'] ?? null);
        // В payload не должно быть отката к старому tenant_id
        $this->assertNull($operation->payload['to_tenant_id'] ?? null, 'payload не должен содержать to_tenant_id');
    }

    public function test_rebuild_snapshot_keeps_space_free_when_mark_space_free_is_newer_than_tenant_switch(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Before Review',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        $tenantSwitchAt = CarbonImmutable::parse('2026-01-10 10:00:00', 'UTC');

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => $tenantSwitchAt,
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'to_tenant_id' => $tenant->id,
            ],
            'created_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $tenantSwitchAt->addHour(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'created_by' => $user->id,
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('vacant', $space->status);
        $this->assertNull($space->tenant_id);
        $this->assertSame('matched', $space->map_review_status);
    }

    public function test_rebuild_snapshot_keeps_space_free_when_mark_space_free_is_newer_than_status_attrs_change(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant With Old Attrs',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        $attrsAt = CarbonImmutable::parse('2026-01-10 09:00:00', 'UTC');

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'effective_at' => $attrsAt,
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'status' => 'occupied',
            ],
            'created_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $attrsAt->addHour(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'created_by' => $user->id,
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('vacant', $space->status);
        $this->assertNull($space->tenant_id);
    }

    public function test_rebuild_snapshot_keeps_space_free_when_later_attrs_change_does_not_touch_status(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant With Unrelated Attrs',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        $reviewAt = CarbonImmutable::parse('2026-01-10 09:00:00', 'UTC');

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $reviewAt,
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'created_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'effective_at' => $reviewAt->addHour(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'number' => 'RENAMED-SPACE',
            ],
            'created_by' => $user->id,
        ]);

        $space->forceFill([
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ])->save();

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('vacant', $space->status);
        $this->assertNull($space->tenant_id);
        $this->assertSame('RENAMED-SPACE', $space->number);
    }

    public function test_rebuild_snapshot_uses_latest_applied_review_for_live_fields_even_if_newer_review_is_observed(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Before Observed Review',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
            'map_review_status' => 'matched',
        ]);

        $appliedAt = CarbonImmutable::parse('2026-01-10 09:00:00', 'UTC');

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $appliedAt,
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'created_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $appliedAt->addHour(),
            'status' => 'observed',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Observed later on site',
            ],
            'created_by' => $user->id,
        ]);

        $space->forceFill([
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ])->save();

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame('vacant', $space->status);
        $this->assertNull($space->tenant_id);
        $this->assertSame('conflict', $space->map_review_status);
    }

    public function test_rebuild_snapshot_keeps_later_tenant_switch_over_older_mark_space_free(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $oldTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'New Tenant',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $oldTenant->id,
            'status' => 'occupied',
        ]);

        $reviewAt = CarbonImmutable::parse('2026-01-10 09:00:00', 'UTC');

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $reviewAt,
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'created_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'effective_at' => $reviewAt->addHour(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'to_tenant_id' => $newTenant->id,
            ],
            'created_by' => $user->id,
        ]);

        Operation::rebuildMarketSpaceSnapshot((int) $market->id, (int) $space->id);

        $space->refresh();

        $this->assertSame($newTenant->id, $space->tenant_id);
    }

    public function test_rebuild_command_includes_spaces_with_only_space_review_operations(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Drifted Tenant',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => CarbonImmutable::parse('2026-01-10 09:00:00', 'UTC'),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'created_by' => $user->id,
        ]);

        $space->forceFill([
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ])->save();

        $this->artisan('operations:rebuild-space-snapshots', [
            '--market-id' => (int) $market->id,
        ])->assertExitCode(0);

        $space->refresh();

        $this->assertSame('vacant', $space->status);
        $this->assertNull($space->tenant_id);
    }
}
