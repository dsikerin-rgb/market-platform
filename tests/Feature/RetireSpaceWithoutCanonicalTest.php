<?php
# tests/Feature/RetireSpaceWithoutCanonicalTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\TenantAccrual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RetireSpaceWithoutCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private function createMarket(): Market
    {
        return Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);
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

    private function withCsrfToken(): self
    {
        $token = csrf_token();

        return $this->withHeaders([
            'X-CSRF-TOKEN' => $token,
        ]);
    }

    public function test_retire_space_without_canonical_archives_space_and_preserves_history(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'ПРМ СТ',
            'display_name' => 'Промостойка',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $user->id,
        ]);

        $shape = MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 0, 'y' => 0],
                ['x' => 10, 'y' => 0],
                ['x' => 10, 'y' => 10],
            ],
            'bbox_x1' => 0,
            'bbox_y1' => 0,
            'bbox_x2' => 10,
            'bbox_y2' => 10,
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::create([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'period' => '2025-03-01',
            'source_row_hash' => sha1('retired-space-accrual'),
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'tenant_id' => null,
            'tenant_contract_id' => null,
            'binding_type' => 'space_snapshot',
            'source' => 'test',
            'started_at' => now(),
            'ended_at' => null,
            'resolution_reason' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withCsrfToken()->postJson(route('filament.admin.map-review-results.retire-space'), [
            'market_space_id' => (int) $space->id,
            'effective_date' => '2025-04-01',
            'reason' => 'Промостойка демонтирована, на рынке больше не используется.',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'retire_space')
            ->assertJsonPath('operation.decision', SpaceReviewDecision::RETIRE_SPACE)
            ->assertJsonPath('operation.status', 'applied');

        $space->refresh();
        $shape->refresh();
        $accrual->refresh();

        $this->assertFalse((bool) $space->is_active);
        $this->assertSame('maintenance', $space->status);
        $this->assertSame('changed', $space->map_review_status);
        $this->assertSame((int) $user->id, (int) $space->map_reviewed_by);
        $this->assertStringContainsString('Место архивировано без основного места', (string) $space->notes);
        $this->assertStringContainsString('Промостойка демонтирована', (string) $space->notes);

        $this->assertNull($shape->market_space_id);
        $this->assertFalse((bool) $shape->is_active);
        $this->assertSame((int) $space->id, (int) $accrual->market_space_id);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'resolution_reason' => 'space_retired_without_canonical',
        ]);
        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'applied',
        ]);

        $operation = Operation::query()
            ->where('market_id', (int) $market->id)
            ->where('entity_id', (int) $space->id)
            ->latest('id')
            ->first();

        $this->assertSame(SpaceReviewDecision::RETIRE_SPACE, data_get($operation?->payload, 'decision'));
        $this->assertSame('2025-04-01', data_get($operation?->payload, 'effective_date'));
        $this->assertSame('Промостойка демонтирована, на рынке больше не используется.', data_get($operation?->payload, 'reason'));
    }
}
