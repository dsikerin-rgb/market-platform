<?php

# tests/Feature/SpaceReviewFlowTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

    public function test_review_decision_endpoint_creates_observed_operation_for_identity_clarification(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'C-303',
            'display_name' => 'Original name',
            'status' => 'occupied',
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'observed')
            ->assertJsonPath('item.review_status', 'conflict');

        $space->refresh();

        $this->assertSame('C-303', $space->number);
        $this->assertSame('Original name', $space->display_name);
        $this->assertSame('conflict', $space->map_review_status);
    }

    public function test_review_decision_endpoint_applies_identity_fix_and_updates_live_fields(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'C-304',
            'display_name' => 'Before fix',
            'status' => 'occupied',
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::FIX_SPACE_IDENTITY,
            'market_space_id' => $space->id,
            'number' => 'C-304A',
            'display_name' => 'After fix',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'applied')
            ->assertJsonPath('item.review_status', 'changed');

        $space->refresh();

        $this->assertSame('C-304A', $space->number);
        $this->assertSame('After fix', $space->display_name);
        $this->assertSame('changed', $space->map_review_status);
    }

    public function test_map_review_results_page_renders_split_identity_clarification_fields(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Зоомир ООО',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'П/3',
            'display_name' => 'Зоомир',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);
        $this->createShape($market, (int) $space->id);

        $candidate = $this->createSpace($market, [
            'number' => '5',
            'display_name' => 'Зоомир ООО',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $candidate->id,
            'number' => 'DOG-5',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $candidate->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('review-result-candidate-accrual'),
        ]);

        DB::table('tenant_user_market_spaces')->insert([
            'user_id' => $user->id,
            'market_space_id' => $space->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            ],
            'created_by' => $user->id,
        ]);

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('Применить уточнение', false)
            ->assertSee('mrrClarifyModal', false)
            ->assertSee('data-mrr-clarify-action="open"', false)
            ->assertSee('mrrClarifyNumberInput', false)
            ->assertSee('mrrClarifyDisplayNameInput', false)
            ->assertDontSee('mrrClarifyInput', false)
            ->assertSee('data-space-number="П/3"', false)
            ->assertSee('data-space-display-name="Зоомир"', false)
            ->assertSee('Связи и кандидаты', false)
            ->assertSee('Связи текущего места', false)
            ->assertSee('Карта: 1', false)
            ->assertSee('Кабинет: 1', false)
            ->assertSee('Кандидаты того же арендатора', false)
            ->assertSee('#' . $candidate->id . ' · 5 / Зоомир ООО', false)
            ->assertSee('Договоры: 1', false)
            ->assertSee('Начисления: 1', false)
            ->assertSee('Открыть место', false)
            ->assertSee('Открыть карту', false)
            ->assertSee('План разбора', false)
            ->assertSee('mrrDuplicatePlanModal', false)
            ->assertSee('План безопасного разбора', false)
            ->assertSee('Это только подсказка для ручной проверки.', false)
            ->assertSee('Договоры, начисления, долги и историю нельзя переносить автоматически', false);
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
