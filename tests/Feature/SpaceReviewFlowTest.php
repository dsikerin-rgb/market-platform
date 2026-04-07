<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SpaceReviewFlowTest extends TestCase
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

    private function createSpace(Market $market, array $overrides = []): MarketSpace
    {
        return MarketSpace::create(array_merge([
            'market_id' => $market->id,
            'number' => 'A-101',
            'display_name' => 'Space A-101',
            'code' => 'a-101',
            'status' => 'occupied',
            'is_active' => true,
        ], $overrides));
    }

    private function createShape(Market $market, ?int $marketSpaceId = null): MarketSpaceMapShape
    {
        return MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $marketSpaceId,
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

    public function test_applied_space_review_binds_shape_and_preserves_tenant_id(): void
    {
        $market = $this->createMarket();
        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
        ]);
        $shape = $this->createShape($market);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::BIND_SHAPE_TO_SPACE,
                'shape_id' => $shape->id,
            ],
            'created_by' => $user->id,
        ]);

        $space->refresh();
        $shape->refresh();
        $rawSpace = DB::table('market_spaces')->where('id', $space->id)->first();

        $this->assertSame($space->id, $shape->market_space_id);
        $this->assertSame($tenant->id, $space->tenant_id);
        $this->assertSame('changed', $rawSpace->map_review_status ?? null);
        $this->assertSame('changed', $space->map_review_status);
        $this->assertNotNull($space->map_reviewed_at);
        $this->assertSame($user->id, $space->map_reviewed_by);
    }

    public function test_observed_space_review_marks_space_without_mutating_live_fields(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $space = $this->createSpace($market, [
            'number' => 'B-202',
            'display_name' => 'Original name',
            'status' => 'occupied',
        ]);

        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Observed tenant',
                'reason' => 'Observed during review',
            ],
            'created_by' => $user->id,
        ]);

        $space->refresh();

        $this->assertSame('observed', $operation->status);
        $this->assertSame('changed_tenant', $space->map_review_status);
        $this->assertSame('B-202', $space->number);
        $this->assertSame('Original name', $space->display_name);
        $this->assertSame('occupied', $space->status);
        $this->assertSame($user->id, $space->map_reviewed_by);
    }

    public function test_review_decision_endpoint_uses_lightweight_mark_for_matched(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => 'matched',
            'market_space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'lightweight')
            ->assertJsonPath('item.market_space_id', $space->id)
            ->assertJsonPath('item.review_status', 'matched');

        $this->assertDatabaseMissing('operations', [
            'market_id' => $market->id,
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
        ]);
    }

    public function test_review_decision_endpoint_creates_operation_for_changed_cases(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
            'market_space_id' => $space->id,
            'observed_tenant_name' => 'Observed tenant',
            'reason' => 'Observed during review',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'observed')
            ->assertJsonPath('item.review_status', 'changed_tenant');

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'observed',
        ]);
    }
}
