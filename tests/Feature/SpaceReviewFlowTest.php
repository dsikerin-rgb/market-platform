<?php
# tests/Feature/SpaceReviewFlowTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Filament\Pages\MapReviewResults;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\User;
use App\Services\Ai\AiReviewService;
use App\Services\MarketMap\MapReviewResultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
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
        $user->forceFill(['name' => 'Review Admin'])->save();

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

    public function test_market_map_space_endpoint_returns_binding_risk_for_bound_business_data(): void
    {
        $market = $this->createMarket();
        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Risk',
            'debt_status' => 'orange',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => 'R-101',
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'DOG-RISK',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $space->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('space-endpoint-binding-risk'),
        ]);

        $response = $this->get('/admin/market-map/space?id=' . $space->id);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('found', true)
            ->assertJsonPath('item.id', $space->id)
            ->assertJsonPath('item.binding_risk.has_tenant', true)
            ->assertJsonPath('item.binding_risk.has_active_contract', true)
            ->assertJsonPath('item.binding_risk.has_accruals', true)
            ->assertJsonPath('item.binding_risk.debt_status', 'orange')
            ->assertJsonPath('item.binding_risk.requires_confirmation', true);

        $warnings = $response->json('item.binding_risk.warnings');
        $debtStatusLabel = $response->json('item.binding_risk.debt_status_label');
        $this->assertIsArray($warnings);
        $this->assertIsString($debtStatusLabel);
        $this->assertNotSame('', trim($debtStatusLabel));
        $this->assertContains('У места уже есть арендатор.', $warnings);
        $this->assertContains('У места есть активный договор.', $warnings);
        $this->assertContains('По месту есть начисления.', $warnings);
        $this->assertTrue(collect($warnings)->contains(
            static fn ($warning): bool => str_contains((string) $warning, 'По арендатору есть статус задолженности:')
        ));
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

    public function test_review_decision_endpoint_rejects_identity_clarification_without_reason(): void
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

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Для этого решения нужен комментарий.');

        $this->assertDatabaseMissing('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
        ]);
    }

    public function test_review_decision_endpoint_creates_observed_operation_for_identity_clarification_with_reason(): void
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
            'reason' => 'Needs manual clarification',
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

        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame('Needs manual clarification', $operation->comment);
        $this->assertSame('Needs manual clarification', $operation->payload['reason'] ?? null);
    }

    public function test_review_results_page_renders_quick_observed_actions(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'Q-101',
            'display_name' => 'Quick review candidate',
            'status' => 'occupied',
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
            'market_space_id' => $space->id,
            'reason' => 'Observed on quick review',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.review_status', 'conflict');

        $this->get(MapReviewResults::getUrl(['tab' => 'review']))
            ->assertOk()
            ->assertSee('Закрыть без изменений', false)
            ->assertSee('Комментарий к закрытию', false)
            ->assertSee('Карточка будет закрыта как проверенная, без изменения статуса, арендатора, карты и связей.', false)
            ->assertDontSee('Зафиксировать итог', false)
            ->assertDontSee('data-mrr-quick-review-choice="mark_space_free"', false);
    }

    public function test_review_results_page_shows_confirm_free_as_separate_action_for_free_conflicts(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'FREE-101',
            'display_name' => 'Free review candidate',
            'status' => 'occupied',
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
            'market_space_id' => $space->id,
            'reason' => 'Место свободно после проверки',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.review_status', 'conflict');

        $this->get(MapReviewResults::getUrl(['tab' => 'review']))
            ->assertOk()
            ->assertSee('data-mrr-confirm-free-open', false)
            ->assertSee('Подтвердить свободно', false)
            ->assertSee('Статус места изменится на свободное.', false)
            ->assertDontSee('data-mrr-quick-review-choice="mark_space_free"', false);
    }

    public function test_review_decision_endpoint_supports_quick_observed_reasoned_decisions(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $cases = [
            [
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Observed occupancy conflict',
                'expected_review_status' => 'conflict',
            ],
            [
                'decision' => SpaceReviewDecision::SHAPE_NOT_FOUND,
                'reason' => 'Shape not found on map',
                'expected_review_status' => 'not_found',
            ],
        ];

        foreach ($cases as $index => $case) {
            $space = $this->createSpace($market, [
                'number' => 'Q-' . ($index + 201),
                'display_name' => 'Quick case ' . ($index + 1),
                'status' => 'occupied',
            ]);

            $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
                'decision' => $case['decision'],
                'market_space_id' => $space->id,
                'reason' => $case['reason'],
            ]);

            $response->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('mode', 'operation')
                ->assertJsonPath('operation.status', 'observed')
                ->assertJsonPath('item.review_status', $case['expected_review_status']);

            $this->assertDatabaseHas('operations', [
                'market_id' => $market->id,
                'entity_type' => 'market_space',
                'entity_id' => $space->id,
                'type' => OperationType::SPACE_REVIEW,
                'status' => 'observed',
            ]);

            $operation = Operation::query()
                ->where('market_id', $market->id)
                ->where('entity_type', 'market_space')
                ->where('entity_id', $space->id)
                ->where('type', OperationType::SPACE_REVIEW)
                ->latest('id')
                ->first();

            $this->assertNotNull($operation);
            $this->assertSame($case['decision'], $operation->payload['decision'] ?? null);
        }
    }

    public function test_review_decision_endpoint_does_not_duplicate_identity_clarification(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'C-305',
            'display_name' => 'Original name',
            'status' => 'occupied',
        ]);

        $payload = [
            'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            'market_space_id' => $space->id,
            'reason' => 'Needs manual clarification',
        ];

        $firstResponse = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', $payload);
        $secondResponse = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', $payload);

        $firstResponse->assertOk()
            ->assertJsonPath('mode', 'operation');
        $secondResponse->assertOk()
            ->assertJsonPath('mode', 'already_marked')
            ->assertJsonPath('message', 'Это место уже отмечено как требующее уточнения.');

        $this->assertSame(1, Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('payload->decision', SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION)
            ->count());
    }

    public function test_review_decision_endpoint_allows_reopening_identity_clarification_after_matched(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'C-305-R',
            'display_name' => 'Reopened clarification',
            'status' => 'occupied',
        ]);

        $payload = [
            'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
            'market_space_id' => $space->id,
            'reason' => 'Needs manual clarification again',
        ];

        $this->withCsrfToken()->postJson('/admin/market-map/review-decision', $payload)
            ->assertOk()
            ->assertJsonPath('mode', 'operation');

        $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => 'matched',
            'market_space_id' => $space->id,
        ])->assertOk()
            ->assertJsonPath('item.review_status', 'matched');

        $reopenedResponse = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', $payload);

        $reopenedResponse->assertOk()
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('item.review_status', 'conflict');

        $space->refresh();
        $this->assertSame('conflict', $space->map_review_status);

        $this->assertSame(2, Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('payload->decision', SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION)
            ->count());
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

    public function test_map_review_results_page_renders_duplicate_review_plan_without_identity_fix_action(): void
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

        $currentSpaceContract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'CURRENT-DOG-P3',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
            'external_id' => 'CURRENT-EXT-ID-P3',
]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $currentSpaceContract->id,
            'market_space_id' => $space->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('review-result-current-accrual'),
        ]);

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
                'reason' => 'Needs manual clarification',
            ],
            'created_by' => $user->id,
        ]);

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertDontSee('Применить уточнение', false)
            ->assertDontSee('mrrClarifyModal', false)
            ->assertDontSee('data-mrr-clarify-action="open"', false)
            ->assertDontSee('mrrClarifyNumberInput', false)
            ->assertDontSee('mrrClarifyDisplayNameInput', false)
            ->assertDontSee('mrrClarifyInput', false)
            ->assertDontSee('data-space-number="П/3"', false)
            ->assertDontSee('data-space-display-name="Зоомир"', false)
            ->assertSee('План безопасного разбора', false)
            ->assertSee('Связи текущего места', false)
            ->assertSee('Карта: 1', false)
            ->assertSee('Кабинет: 1', false)
            ->assertSee('Возможные дубли', false)
            ->assertSee('Найдено 1 место по связанному арендатору или точному совпадению нормализованного названия', false)
            ->assertSee('Разобрать дубль', false)
            ->assertSee('Открыть место', false)
            ->assertSee('Открыть карту', false)
            ->assertSee('data-mrr-duplicate-plan="open"', false)
            ->assertSee('data-mrr-duplicate-plan-create', false)
            ->assertSee('mrrDuplicatePlanModal', false)
            ->assertSee('План безопасного разбора', false)
            ->assertSee('Оставить основным', false)
            ->assertSee('Договоры, начисления и долги не переносятся', false)
            ->assertSee('CURRENT-DOG-P3', false)
            ->assertSee('DOG-5', false)
            ->assertSee(now()->startOfMonth()->format('m.Y'), false);
    }

    public function test_map_review_results_finds_duplicate_candidate_from_observed_tenant_when_current_space_is_empty(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Fayzulloeva D.M IP',
            'is_active' => true,
        ]);

        $canonical = $this->createSpace($market, [
            'number' => 'P70',
            'display_name' => 'P70',
            'code' => 'P/70',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'P/70 from 01.05.2024',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
            'external_id' => 'P70-CONTRACT',
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $canonical->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_place_code' => 'P/70',
            'source_row_hash' => sha1('observed-tenant-canonical-accrual'),
        ]);

        $duplicate = $this->createSpace($market, [
            'number' => '70',
            'display_name' => 'Cafe',
            'code' => 'raw-map-space',
            'status' => 'vacant',
            'tenant_id' => null,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $user->id,
        ]);
        $this->createShape($market, (int) $duplicate->id);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $duplicate->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $duplicate->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'reason' => 'Long ago',
                'observed_tenant_name' => 'Fayzuloeva',
            ],
            'created_by' => $user->id,
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $row = collect($rows)->firstWhere('space_id', (int) $duplicate->id);

        $this->assertNotNull($row);
        $this->assertSame((int) $canonical->id, (int) data_get($row, 'diagnostics.candidate_spaces.0.space_id'));
        $this->assertTrue((bool) data_get($row, 'diagnostics.candidate_spaces.0.is_stronger_than_current'));

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('data-mrr-duplicate-plan="open"', false)
            ->assertSee('data-current-space-id="' . $duplicate->id . '"', false)
            ->assertSee('data-candidate-space-id="' . $canonical->id . '"', false);
    }

    public function test_map_review_results_blocks_duplicate_resolution_when_current_has_tenant_but_observed_differs_and_candidate_is_stronger(): void
    {
        // П52у/2-3-like scenario:
        // - current space tenant = СЕРВИСМАРКЕТ;
        // - current space имеет map_shape, accruals;
        // - observed tenant = другой арендатор;
        // - candidate = П55 с тем же tenant СЕРВИСМАРКЕТ и сильными договорными/финансовыми связями;
        // - candidate отображается как диагностический;
        // - кнопка "Разобрать дубль" не отображается;
        // - warning/block reason отображается.
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'СЕРВИСМАРКЕТ ООО',
            'is_active' => true,
        ]);

        $candidateSpace = $this->createSpace($market, [
            'number' => 'П55',
            'display_name' => 'П55',
            'code' => 'P/55',
            'status' => 'occupied',
            'tenant_id' => $currentTenant->id,
        ]);
        $this->createShape($market, (int) $candidateSpace->id);

        $candidateContract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'market_space_id' => $candidateSpace->id,
            'number' => 'P/55 from 01.01.2024',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
            'external_id' => 'P55-CONTRACT',
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'tenant_contract_id' => $candidateContract->id,
            'market_space_id' => $candidateSpace->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_place_code' => 'P/55',
            'source_row_hash' => sha1('candidate-accrual-1'),
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'tenant_contract_id' => $candidateContract->id,
            'market_space_id' => $candidateSpace->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'source_place_code' => 'P/55',
            'source_row_hash' => sha1('candidate-accrual-2'),
        ]);

        $currentSpace = $this->createSpace($market, [
            'number' => 'П52у/2-3',
            'display_name' => 'П52у/2-3',
            'code' => 'P/52u/2-3',
            'status' => 'occupied',
            'tenant_id' => $currentTenant->id,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $user->id,
        ]);

        // current space имеет map_shape — это independent anchor
        $this->createShape($market, (int) $currentSpace->id);

        // current space имеет accruals — это дополнительный independent anchor
        $currentAccrual = TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'market_space_id' => $currentSpace->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_place_code' => 'P/52u/2-3',
            'source_row_hash' => sha1('current-accrual-1'),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $currentSpace->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $currentSpace->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'reason' => 'на месте другой арендатор',
                'observed_tenant_name' => 'Другой Арендатор ООО',
            ],
            'created_by' => $user->id,
        ]);

        // Создаём observed tenant, чтобы он нашёлся в системе
        $observedTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Другой Арендатор ООО',
            'is_active' => true,
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $row = collect($rows)->firstWhere('space_id', (int) $currentSpace->id);

        $this->assertNotNull($row);
        $this->assertSame((int) $candidateSpace->id, (int) data_get($row, 'diagnostics.candidate_spaces.0.space_id'));
        $this->assertTrue((bool) data_get($row, 'diagnostics.candidate_spaces.0.is_stronger_than_current'));
        // Кандидат показывается как диагностическая подсказка
        $this->assertTrue((bool) data_get($row, 'diagnostics.has_candidates'));

        // Проверка блокировки duplicate resolution:
        // current tenant = СЕРВИСМАРКЕТ, observed tenant = Другой Арендатор (отличается)
        // candidate найден по тому же tenant, current space ИМЕЕТ independent anchors
        // Поэтому can_apply_duplicate_resolution = false
        $candidate = data_get($row, 'diagnostics.candidate_spaces.0', []);
        $this->assertNotNull($candidate);
        $this->assertSame((int) $candidateSpace->id, (int) ($candidate['space_id'] ?? null));
        $this->assertFalse((bool) ($candidate['can_apply_duplicate_resolution'] ?? true));
        $this->assertNotNull($candidate['duplicate_resolution_block_reason'] ?? null);
        $this->assertStringContainsString('Кандидат найден по текущему арендатору', $candidate['duplicate_resolution_block_reason'] ?? '');

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('data-mrr-duplicate-blocked-warning', false)
            ->assertSee($candidate['duplicate_resolution_block_reason'] ?? '', false);
    }

    public function test_map_review_results_blocks_duplicate_resolution_for_occupancy_conflict_with_stronger_same_tenant_candidate(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'СЕРВИСМАРКЕТ ООО',
            'is_active' => true,
        ]);

        $candidateSpace = $this->createSpace($market, [
            'number' => 'П55',
            'display_name' => 'П55',
            'code' => 'P/55',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);
        $this->createShape($market, (int) $candidateSpace->id);

        $candidateContract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $candidateSpace->id,
            'number' => 'P/55 from 01.01.2024',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
            'external_id' => 'P55-CONTRACT',
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $candidateContract->id,
            'market_space_id' => $candidateSpace->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_place_code' => 'P/55',
            'source_row_hash' => sha1('candidate-accrual-1'),
        ]);

        $currentSpace = $this->createSpace($market, [
            'number' => 'П52у/2-3',
            'display_name' => 'П52у/2-3',
            'code' => 'P/52u/2-3',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $currentSpace->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $currentSpace->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Конфликт по занятости',
            ],
            'created_by' => $user->id,
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $row = collect($rows)->firstWhere('space_id', (int) $currentSpace->id);

        $this->assertNotNull($row);
        $candidate = data_get($row, 'diagnostics.candidate_spaces.0', []);
        $this->assertNotNull($candidate);
        $this->assertSame((int) $candidateSpace->id, (int) ($candidate['space_id'] ?? null));
        $this->assertFalse((bool) ($candidate['can_apply_duplicate_resolution'] ?? true));
        $this->assertNotNull($candidate['duplicate_resolution_block_reason'] ?? null);
        $this->assertStringContainsString('Кандидат найден по текущему арендатору', $candidate['duplicate_resolution_block_reason'] ?? '');

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('data-mrr-duplicate-blocked-warning', false)
            ->assertSee($candidate['duplicate_resolution_block_reason'] ?? '', false)
            ->assertSee('data-mrr-manual-tenant-switch-open', false)
            ->assertSee('Сменить арендатора', false);
    }

    public function test_map_review_results_finds_duplicate_candidate_by_normalized_name_across_tenants(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Current',
            'is_active' => true,
        ]);
        $candidateTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Candidate',
            'is_active' => true,
        ]);

        $current = $this->createSpace($market, [
            'number' => 'Холодильная камера 3 (просто договор)',
            'display_name' => 'Холодильная камера камера 3 (просто договор)',
            'tenant_id' => $currentTenant->id,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $user->id,
        ]);

        $candidate = $this->createSpace($market, [
            'number' => 'Холодильная камера 3',
            'display_name' => 'Холодильная камера 3',
            'tenant_id' => $candidateTenant->id,
        ]);
        $this->createShape($market, (int) $candidate->id);

        $nearbyDifferentNumber = $this->createSpace($market, [
            'number' => 'Холодильная камера 2',
            'display_name' => 'Холодильная камера 2',
            'tenant_id' => $candidateTenant->id,
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $current->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $current->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Needs duplicate review',
            ],
            'created_by' => $user->id,
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $row = collect($rows)->firstWhere('space_id', (int) $current->id);

        $this->assertNotNull($row);
        $this->assertSame((int) $candidate->id, (int) data_get($row, 'diagnostics.candidate_spaces.0.space_id'));
        $this->assertSame('name', data_get($row, 'diagnostics.candidate_spaces.0.match_source'));
        $this->assertSame(SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL, data_get($row, 'diagnostics.candidate_spaces.0.resolution_decision'));
        $this->assertNotContains((int) $nearbyDifferentNumber->id, collect(data_get($row, 'diagnostics.candidate_spaces', []))->pluck('space_id')->map(fn ($id): int => (int) $id)->all());

        $aiPack = app(\App\Services\Ai\AiContextPackBuilder::class)->build((int) $current->id, (int) $market->id);
        $this->assertSame((int) $candidate->id, (int) data_get($aiPack, 'relation_context.name_duplicate_candidates.0.id'));
        $this->assertNotContains((int) $nearbyDifferentNumber->id, collect(data_get($aiPack, 'relation_context.name_duplicate_candidates', []))->pluck('id')->map(fn ($id): int => (int) $id)->all());

        Livewire::test(MapReviewResults::class)
            ->assertSee('Найден возможный дубль по названию', false)
            ->assertSee('data-mrr-duplicate-plan="open"', false)
            ->assertSee('data-candidates=', false)
            ->assertSee('Холодильная камера 3', false)
            ->assertSee('Tenant Candidate', false)
            ->assertSee('merge_space_into_canonical', false);
    }

    public function test_map_review_results_warns_when_current_space_is_not_weaker_than_candidate(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Электрооборудование',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'П3/2',
            'display_name' => 'Электрооборудование',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);
        $this->createShape($market, (int) $space->id);

        $candidate = $this->createSpace($market, [
            'number' => 'П3/2/склад',
            'display_name' => 'Электрооборудование',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('current-stronger-accrual-1'),
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'period' => now()->subMonth()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('current-stronger-accrual-2'),
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $candidate->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('weak-candidate-accrual'),
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
                'reason' => 'Needs manual clarification',
            ],
            'created_by' => $user->id,
        ]);

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('Текущее место не слабее', false)
            ->assertSee('Текущее место не слабее кандидатов по подтверждённым связям. Не выбирайте кандидата основным без дополнительной проверки.', false)
            ->assertSee('Возможные дубли', false)
            ->assertSee('Разобрать дубль', false)
            ->assertSee('Начисления: 2', false);
    }

    public function test_map_review_results_has_separate_tab_for_unconfirmed_space_links(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Зоомир ООО',
            'debt_status' => 'orange',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'П/3',
            'display_name' => 'Зоомир',
            'status' => 'occupied',
            'tenant_id' => $tenant->id,
        ]);
        $this->createShape($market, (int) $space->id);

        Livewire::withQueryParams(['tab' => 'unconfirmed_links'])
            ->test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('Связь не подтверждена', false)
            ->assertSee('Связь с местом не подтверждена', false)
            ->assertDontSee('Обычный порядок', false)
            ->assertDontSee('AI-приоритет', false)
            ->assertDontSee('Последнее решение', false)
            ->assertDontSee('Переходы', false)
            ->assertDontSee('Системно найдено', false)
            ->assertSee('П/3', false)
            ->assertSee('Зоомир', false);

        Livewire::withQueryParams(['tab' => 'review'])
            ->test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('Сейчас нет мест, требующих уточнения.', false)
            ->assertDontSee('Системно найдено', false);
    }

    public function test_map_review_results_renders_attention_filters_with_correct_data_attributes(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        // Создаём место с decision = space_identity_needs_clarification
        $space = $this->createSpace($market, [
            'number' => 'FILTER-TEST',
            'display_name' => 'Filter test space',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
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
                'reason' => 'Needs manual clarification',
            ],
            'created_by' => $user->id,
        ]);

        Livewire::test(MapReviewResults::class)
            // Проверяем наличие кнопок фильтров
            ->assertSee('Уточнить номер / название', false)
            ->assertSee('Конфликт по занятости', false)
            ->assertSee('Сменился арендатор', false)
            ->assertSee('Фигура не найдена', false)
            ->assertSee('Все', false)
            // Проверяем data-атрибуты фильтров
            ->assertSee('data-mrr-attention-filter="all"', false)
            ->assertSee('data-mrr-attention-filter="occupancy_conflict"', false)
            ->assertSee('data-mrr-attention-filter="space_identity_needs_clarification"', false)
            ->assertSee('data-mrr-attention-filter="tenant_changed_on_site"', false)
            ->assertSee('data-mrr-attention-filter="shape_not_found"', false)
            ->assertSee('data-mrr-attention-search', false)
            // Проверяем наличие карточки с правильным data-mrr-decision
            ->assertSee('data-mrr-attention-card', false)
            ->assertSee('data-mrr-decision="space_identity_needs_clarification"', false)
            // Проверяем наличие счётчика
            ->assertSee('class="mrr-attention-filter-count"', false)
            // Проверяем что карточка видна (есть номер места)
            ->assertSee('FILTER-TEST', false);
    }

    public function test_manual_tenant_switch_modal_no_auto_select_single_partial_match(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $dinislovaTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Динисламова ЕД ИП',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'P52U-2-3',
            'display_name' => 'Test P52u',
            'tenant_id' => $oldTenant->id,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Conflict detected on P52u',
            ],
            'created_by' => $user->id,
        ]);

        $html = Livewire::test(MapReviewResults::class)->html();

        // Кнопка "Сменить арендатора" должна быть видна
        $this->assertStringContainsString('data-mrr-manual-tenant-switch-open', $html);
        $this->assertStringContainsString('Сменить арендатора', $html);

        // Проверяем, что в HTML нет автоселекта через selectManualTenantSwitchTenant для partial match
        $this->assertStringNotContainsString('selectManualTenantSwitchTenant(matchedOptions[0])', $html);

        // Проверяем наличие текста подсказки для одного кандидата
        $this->assertStringContainsString('Найден один похожий арендатор. Нажмите на него, чтобы выбрать.', $html);
    }

    public function test_map_review_results_shows_tenant_change_card_with_review_hint(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'CHANGE-TEST',
            'display_name' => 'Change Test Space',
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Бакиева',
                'reason' => 'с 01.05.26 г',
            ],
            'created_by' => $user->id,
        ]);

        $html = Livewire::test(MapReviewResults::class)->html();

        $this->assertStringContainsString('CHANGE-TEST', $html);
        $this->assertStringContainsString('Сменился арендатор', $html);
        $this->assertStringContainsString('Фактический арендатор', $html);
        $this->assertStringContainsString('Бакиева', $html);
        $this->assertStringContainsString('Подсказка ревизора', $html);
        $this->assertStringContainsString('с 01.05.26 г', $html);
        $this->assertStringNotContainsString('Автор', $html);
        $this->assertStringNotContainsString('Дата фиксации', $html);
    }

    public function test_map_review_results_shows_ai_only_for_first_batch_with_clear_limit_message(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        app()->instance(AiReviewService::class, new class extends AiReviewService {
            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                return [
                    'review' => [
                        'summary' => 'Проверить спорную связь',
                        'why_flagged' => 'Есть спорный кейс',
                        'recommended_next_step' => 'Открыть место и сверить контекст',
                        'risk_score' => 6,
                        'confidence' => 0.8,
                    ],
                    'error_type' => null,
                ];
            }
        });

        for ($i = 1; $i <= 6; $i++) {
            $this->createSpace($market, [
                'number' => 'AI-' . $i,
                'display_name' => 'AI space ' . $i,
                'map_review_status' => 'conflict',
                'map_reviewed_at' => now()->subMinutes($i),
            ]);
        }

        Livewire::test(MapReviewResults::class)
            ->assertSee('Загрузить ИИ-разбор', false)
            ->assertDontSee('ИИ-разбор показан для первых 5 мест в текущем списке', false);
    }

    public function test_map_review_results_selects_ai_batch_from_current_visible_order(): void
    {
        $page = new class extends MapReviewResults
        {
            public function exposedBuildNeedsAttentionRows(array $needsAttention, array $aiSummaries, string $attentionTab): array
            {
                return $this->buildNeedsAttentionRows($needsAttention, $aiSummaries, $attentionTab);
            }

            public function exposedSelectVisibleAiBatch(array $rows): array
            {
                return $this->selectVisibleAiBatch($rows);
            }
        };

        $needsAttention = [
            ['space_id' => 101, 'review_status' => 'unconfirmed_link', 'diagnostics' => []],
            ['space_id' => 102, 'review_status' => 'unconfirmed_link', 'diagnostics' => ['has_stronger_candidate' => true]],
            ['space_id' => 103, 'review_status' => 'unconfirmed_link', 'diagnostics' => ['has_candidates' => true]],
            ['space_id' => 104, 'review_status' => 'unconfirmed_link', 'diagnostics' => []],
            ['space_id' => 105, 'review_status' => 'unconfirmed_link', 'diagnostics' => ['has_stronger_candidate' => true]],
            ['space_id' => 106, 'review_status' => 'unconfirmed_link', 'diagnostics' => []],
        ];

        $visibleRows = $page->exposedBuildNeedsAttentionRows($needsAttention, [], 'unconfirmed_links');
        $selectedBatch = $page->exposedSelectVisibleAiBatch($visibleRows);

        $this->assertSame([102, 105, 103, 101, 104], array_column($selectedBatch, 'space_id'));
    }

    public function test_ai_context_builder_uses_schema_safe_fallback_columns(): void
    {
        $market = $this->createMarket();
        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Full Tenant',
            'short_name' => 'Short Tenant',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'period' => '2026-05-01',
            'total_no_vat' => 1234.56,
            'total_with_vat' => 9999.99,
            'cash_amount' => 777.77,
            'source' => '1c',
        ]);

        $existingTables = [];
        $existingColumns = [];
        foreach ([
            'tenants',
            'tenant_accruals',
            'tenant_contracts',
            'contract_debts',
            'market_space_map_shapes',
            'market_space_tenant_histories',
        ] as $table) {
            $existingTables[$table] = Schema::hasTable($table);
            $existingColumns[$table] = $existingTables[$table]
                ? Schema::getColumnListing($table)
                : [];
        }

        $schemaMock = Schema::partialMock();
        $schemaMock
            ->shouldReceive('hasColumn')
            ->andReturnUsing(function (string $table, string $column) use ($existingColumns): bool {
                if ($table === 'tenants' && $column === 'display_name') {
                    return false;
                }

                if ($table === 'tenant_accruals' && $column === 'total_with_vat') {
                    return false;
                }

                if ($table === 'tenant_accruals' && $column === 'amount') {
                    return false;
                }

                if ($table === 'market_space_tenant_histories' && $column === 'change_type') {
                    return false;
                }

                if ($table === 'market_space_map_shapes' && $column === 'label') {
                    return false;
                }

                return in_array($column, $existingColumns[$table] ?? [], true);
            });
        $schemaMock
            ->shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table): bool => (bool) ($existingTables[$table] ?? false));

        $pack = app(\App\Services\Ai\AiContextPackBuilder::class)->build((int) $space->id, (int) $market->id);

        $this->assertSame('Short Tenant', $pack['tenant_context']['tenant']['display_name']);
        $this->assertSame(1234.56, $pack['accrual_context']['latest_total_with_vat']);
        $this->assertSame('1c', $pack['accrual_context']['latest_source']);
    }

    public function test_ai_map_review_results_service_uses_schema_safe_financial_signal_paths(): void
    {
        $market = $this->createMarket();
        $currentTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);
        $observedTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Observed Temp',
            'is_active' => true,
        ]);
        $candidateTenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Legal Entity 77',
            'short_name' => 'Observed Temp',
            'is_active' => true,
        ]);
        $space = $this->createSpace($market, [
            'tenant_id' => $currentTenant->id,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $observedTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'total_no_vat' => 4321.0,
            'cash_amount' => 321.0,
            'source' => '1c',
            'source_file' => '1c:accruals',
            'source_place_name' => 'Observed Place',
            'source_place_code' => 'A-101',
        ]);

        $existingTables = [];
        $existingColumns = [];
        foreach ([
            'tenants',
            'tenant_accruals',
            'tenant_contracts',
            'contract_debts',
            'market_space_map_shapes',
            'market_space_tenant_histories',
            'market_spaces',
        ] as $table) {
            $existingTables[$table] = Schema::hasTable($table);
            $existingColumns[$table] = $existingTables[$table]
                ? Schema::getColumnListing($table)
                : [];
        }

        $schemaMock = Schema::partialMock();
        $schemaMock
            ->shouldReceive('hasColumn')
            ->andReturnUsing(function (string $table, string $column) use ($existingColumns): bool {
                if ($table === 'tenants' && $column === 'display_name') {
                    return false;
                }

                if ($table === 'tenant_accruals' && $column === 'amount') {
                    return false;
                }

                if ($table === 'tenant_accruals' && $column === 'total_with_vat') {
                    return false;
                }

                if ($table === 'market_space_tenant_histories' && $column === 'change_type') {
                    return false;
                }

                if ($table === 'market_space_map_shapes' && $column === 'label') {
                    return false;
                }

                return in_array($column, $existingColumns[$table] ?? [], true);
            });
        $schemaMock
            ->shouldReceive('hasTable')
            ->andReturnUsing(fn (string $table): bool => (bool) ($existingTables[$table] ?? false));

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 50);
        $row = collect($rows)->firstWhere('space_id', (int) $space->id);

        $this->assertNotNull($row);
        $this->assertSame($candidateTenant->id, data_get($row, 'diagnostics.financial_signal.existing_tenant_candidate_id'));
        $this->assertSame('Observed Temp', data_get($row, 'diagnostics.financial_signal.existing_tenant_candidate_name'));
        $this->assertEquals(4321.0, data_get($row, 'diagnostics.accrual_details.0.amount'));
        $this->assertSame('Observed Place', data_get($row, 'diagnostics.accrual_details.0.source_place_name'));
        $this->assertSame('A-101', data_get($row, 'diagnostics.accrual_details.0.source_place_code'));
    }

    public function test_map_review_results_loads_ai_on_demand_for_skipped_row_and_keeps_previous_loaded_rows(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        app()->instance(AiReviewService::class, new class extends AiReviewService {
            /** @var array<int, array{summary:string, why_flagged:string, recommended_next_step:string, risk_score:int, confidence:float}> */
            private array $cached = [];

            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                $review = [
                    'summary' => 'Проверить спорную связь ' . $spaceId,
                    'why_flagged' => 'Есть спорный кейс ' . $spaceId,
                    'recommended_next_step' => 'Открыть место и сверить контекст ' . $spaceId,
                    'risk_score' => 6,
                    'confidence' => 0.8,
                ];

                $this->cached[$spaceId] = $review;

                return [
                    'review' => $review,
                    'error_type' => null,
                ];
            }

            public function getCachedReviewForSpace(int $spaceId, int $marketId): ?array
            {
                return $this->cached[$spaceId] ?? null;
            }
        });

        $spaces = [];
        for ($i = 1; $i <= 6; $i++) {
            $spaces[] = $this->createSpace($market, [
                'number' => 'AI-OD-' . $i,
                'display_name' => 'AI on demand ' . $i,
                'map_review_status' => 'conflict',
                'map_reviewed_at' => now()->subMinutes($i),
            ]);
        }

        Livewire::withQueryParams(['ai_load_space_id' => $spaces[5]->id])
            ->test(MapReviewResults::class)
            ->assertSee('Проверить спорную связь ' . $spaces[5]->id, false);

        $spaces[] = $this->createSpace($market, [
            'number' => 'AI-OD-7',
            'display_name' => 'AI on demand 7',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now()->subMinutes(7),
        ]);

        Livewire::withQueryParams(['ai_load_space_id' => $spaces[6]->id])
            ->test(MapReviewResults::class)
            ->assertSee('Проверить спорную связь ' . $spaces[5]->id, false)
            ->assertSee('Проверить спорную связь ' . $spaces[6]->id, false);
    }

    public function test_map_review_results_keeps_persisted_ai_summary_when_current_batch_returns_null(): void
    {
        $page = new class extends MapReviewResults
        {
            public function exposedLoadCachedVisibleAiSummaries(int $marketId, array $visibleRows): array
            {
                return $this->loadCachedVisibleAiSummaries($marketId, $visibleRows);
            }
        };

        app(AiReviewService::class)->cacheSuccess(101, 77, [
            'summary' => 'Persisted summary',
            'why_flagged' => 'Persisted reason',
            'recommended_next_step' => 'Persisted step',
            'risk_score' => 7,
            'confidence' => 0.9,
        ]);

        $loaded = $page->exposedLoadCachedVisibleAiSummaries(77, [
            ['space_id' => 101],
            ['space_id' => 102],
        ]);

        $this->assertSame('Persisted summary', $loaded[101]['summary']);
        $this->assertArrayNotHasKey(102, $loaded);
    }

    public function test_map_review_results_keeps_cached_ai_summary_for_current_row_outside_visible_batch(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        app()->instance(AiReviewService::class, new class extends AiReviewService {
            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                return [
                    'review' => [
                        'summary' => 'Batch review ' . $spaceId,
                        'why_flagged' => 'Batch reason ' . $spaceId,
                        'recommended_next_step' => 'Batch step ' . $spaceId,
                        'risk_score' => 5,
                        'confidence' => 0.7,
                    ],
                    'error_type' => null,
                ];
            }
        });

        $spaces = [];
        for ($i = 1; $i <= 6; $i++) {
            $spaces[] = $this->createSpace($market, [
                'number' => 'AI-CACHED-' . $i,
                'display_name' => 'AI cached ' . $i,
                'map_review_status' => 'conflict',
                'map_reviewed_at' => now()->subMinutes($i),
            ]);
        }

        app(AiReviewService::class)->cacheSuccess($spaces[5]->id, (int) $market->id, [
            'summary' => 'Persisted off-batch summary ' . $spaces[5]->id,
            'why_flagged' => 'Persisted off-batch reason ' . $spaces[5]->id,
            'recommended_next_step' => 'Persisted off-batch step ' . $spaces[5]->id,
            'risk_score' => 9,
            'confidence' => 0.91,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Persisted off-batch summary ' . $spaces[5]->id, false);
    }

    public function test_map_review_results_keeps_cached_ai_summary_on_repeat_visit_after_short_delay(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'AI-REVISIT-1',
            'display_name' => 'AI revisit 1',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        app(AiReviewService::class)->cacheSuccess($space->id, (int) $market->id, [
            'summary' => 'Stable revisit summary ' . $space->id,
            'why_flagged' => 'Stable revisit reason ' . $space->id,
            'recommended_next_step' => 'Stable revisit step ' . $space->id,
            'risk_score' => 8,
            'confidence' => 0.93,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Stable revisit summary ' . $space->id, false);

        $this->travel(15)->minutes();

        Livewire::test(MapReviewResults::class)
            ->assertSee('Stable revisit summary ' . $space->id, false);
    }

    public function test_map_review_results_shows_recommended_action_label_from_ai_summary(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'AI-ACTION-1',
            'display_name' => 'AI action 1',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        app(AiReviewService::class)->cacheSuccess($space->id, (int) $market->id, [
            'summary' => 'Action summary ' . $space->id,
            'why_flagged' => 'Action reason ' . $space->id,
            'recommended_next_step' => 'Action step ' . $space->id,
            'recommended_action' => 'resolve_duplicate',
            'recommended_action_label' => 'Разобрать дубль',
            'missing_evidence' => [
                'Manual confirmation ' . $space->id,
            ],
            'ui_gap' => 'No safe UI action ' . $space->id,
            'risk_score' => 8,
            'confidence' => 0.93,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Рекомендованное действие:', false)
            ->assertSee('Разобрать дубль', false)
            ->assertSee('Action step ' . $space->id, false)
            ->assertSee('Чего не хватает:', false)
            ->assertSee('Manual confirmation ' . $space->id, false)
            ->assertSee('Пробел в UI:', false)
            ->assertSee('No safe UI action ' . $space->id, false)
            ->assertSee('data-mrr-ai-regenerate', false)
            ->assertSee('Обновить', false);
    }

    public function test_ai_review_regenerate_endpoint_clears_cached_summary_and_refetches(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'AI-REGEN-1',
            'display_name' => 'AI regen 1',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        $service = new class extends AiReviewService {
            public int $calls = 0;

            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                $this->calls++;

                return [
                    'review' => [
                        'summary' => 'Regenerated summary ' . $spaceId,
                        'why_flagged' => 'Regenerated reason ' . $marketId,
                        'recommended_next_step' => 'Regenerated step ' . $spaceId,
                        'recommended_action' => 'manual_review',
                        'recommended_action_label' => 'Ручная проверка',
                        'missing_evidence' => ['Regenerated missing evidence ' . $spaceId],
                        'ui_gap' => 'Regenerated UI gap ' . $spaceId,
                        'risk_score' => 9,
                        'confidence' => 0.81,
                    ],
                    'error_type' => null,
                ];
            }
        };

        app()->instance(AiReviewService::class, $service);
        $service->cacheSuccess($space->id, (int) $market->id, [
            'summary' => 'Old cached summary ' . $space->id,
            'why_flagged' => 'Old cached reason ' . $space->id,
            'recommended_next_step' => 'Old cached step ' . $space->id,
            'risk_score' => 7,
            'confidence' => 0.75,
        ]);

        $this->assertSame('Old cached summary ' . $space->id, $service->getCachedReviewForSpace($space->id, (int) $market->id)['summary']);

        $response = $this->withCsrfToken()->postJson('/admin/map-review-results/ai-review/regenerate', [
            'space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('review.summary', 'Regenerated summary ' . $space->id)
            ->assertJsonPath('review.recommended_action', 'manual_review')
            ->assertJsonPath('review.recommended_action_label', 'Ручная проверка')
            ->assertJsonPath('review.missing_evidence.0', 'Regenerated missing evidence ' . $space->id)
            ->assertJsonPath('review.ui_gap', 'Regenerated UI gap ' . $space->id)
            ->assertJsonPath('error_type', null);

        $this->assertSame(1, $service->calls);
        $this->assertNull($service->getCachedReviewForSpace($space->id, (int) $market->id));
    }

    public function test_review_hint_can_be_updated_only_by_operation_author(): void
    {
        $market = $this->createMarket();
        $author = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'observed',
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Old hint',
            ],
            'created_by' => $author->id,
        ]);

        $page = Livewire::test(MapReviewResults::class);
        $page->call('updateReviewHint', $operation->id, 'Updated hint')
            ->assertReturned([
                'ok' => true,
                'message' => null,
            ]);

        $operation->refresh();
        $this->assertSame('Updated hint', $operation->payload['reason'] ?? null);

        $otherUser = User::factory()->create(['market_id' => $market->id]);
        $otherUser->assignRole('super-admin');
        $this->actingAs($otherUser, 'web');
        $this->actingAs($otherUser, 'filament');

        Livewire::test(MapReviewResults::class)
            ->call('updateReviewHint', $operation->id, 'Another hint')
            ->assertReturned([
                'ok' => false,
                'message' => 'Операция не найдена или у вас нет прав на редактирование.',
            ]);

        $operation->refresh();
        $this->assertSame('Updated hint', $operation->payload['reason'] ?? null);
    }

    public function test_review_rows_expose_hint_edit_permissions_for_operation_author(): void
    {
        $market = $this->createMarket();
        $author = $this->actingAsSuperAdmin((int) $market->id);

        $space = $this->createSpace($market, [
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'observed',
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Editable hint',
            ],
            'created_by' => $author->id,
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 50);
        $row = collect($rows)->firstWhere('space_id', (int) $space->id);

        $this->assertNotNull($row);
        $this->assertSame((int) $operation->id, $row['review_operation_id']);
        $this->assertSame((int) $author->id, $row['review_created_by']);
        $this->assertTrue($row['can_edit_reason']);

        $otherUser = User::factory()->create(['market_id' => $market->id]);
        $otherUser->assignRole('super-admin');
        $this->actingAs($otherUser, 'web');
        $this->actingAs($otherUser, 'filament');

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 50);
        $row = collect($rows)->firstWhere('space_id', (int) $space->id);

        $this->assertFalse($row['can_edit_reason']);
    }

    public function test_map_review_results_explains_ai_unavailable_reason_for_policy_fail(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        app()->instance(AiReviewService::class, new class extends AiReviewService {
            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                return [
                    'review' => null,
                    'error_type' => 'policy',
                ];
            }
        });

        $this->createSpace($market, [
            'number' => 'AI-POLICY',
            'display_name' => 'AI policy',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('ИИ-анализ отклонён проверкой качества ответа', false);
    }

    public function test_map_review_results_explains_provider_billing_without_enabling_connectivity_cooldown(): void
    {
        Cache::flush();

        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'AI-BILLING',
            'display_name' => 'AI billing',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        app()->instance(AiReviewService::class, new class extends AiReviewService {
            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                return [
                    'review' => null,
                    'error_type' => 'provider_billing',
                ];
            }
        });

        Livewire::test(MapReviewResults::class)
            ->assertSee('AI-сводка недоступна: требуется оплата или лимит провайдера', false);

        $this->assertFalse(Cache::has('gigachat_connectivity_down:market:' . $market->id));

        $response = $this->getJson('/admin/map-review-results/ai-review?space_id=' . $space->id);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('review', null)
            ->assertJsonPath('error_type', 'provider_billing');
    }

    public function test_map_review_results_connectivity_cooldown_is_scoped_to_market(): void
    {
        Cache::flush();

        $firstMarket = $this->createMarket();
        $secondMarket = Market::create([
            'name' => 'Test Market 2',
            'slug' => 'test-market-2',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $this->actingAsSuperAdmin((int) $firstMarket->id);

        $firstSpace = $this->createSpace($firstMarket, [
            'number' => 'AI-COOLDOWN-1',
            'display_name' => 'AI cooldown 1',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        $secondSpace = $this->createSpace($secondMarket, [
            'number' => 'AI-COOLDOWN-2',
            'display_name' => 'AI cooldown 2',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        app()->instance(AiReviewService::class, new class ((int) $firstMarket->id) extends AiReviewService {
            public function __construct(private readonly int $failingMarketId)
            {
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getReviewForSpace(int $spaceId, int $marketId): array
            {
                if ($marketId === $this->failingMarketId) {
                    return [
                        'review' => null,
                        'error_type' => 'connectivity',
                    ];
                }

                return [
                    'review' => [
                        'summary' => 'Available summary ' . $spaceId,
                        'why_flagged' => 'Available reason ' . $spaceId,
                        'recommended_next_step' => 'Available step ' . $spaceId,
                        'risk_score' => 6,
                        'confidence' => 0.82,
                    ],
                    'error_type' => null,
                ];
            }
        });

        $this->withSession([
            'filament.admin.selected_market_id' => (int) $firstMarket->id,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertDontSee('Available summary ' . $firstSpace->id, false);

        $this->withSession([
            'filament.admin.selected_market_id' => (int) $secondMarket->id,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Available summary ' . $secondSpace->id, false);
    }

    public function test_review_decision_endpoint_resolves_duplicate_by_transferring_safe_links(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $duplicate = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => 'P/3',
            'display_name' => 'Zoo',
            'status' => 'occupied',
        ]);

        $candidate = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => '5',
            'display_name' => 'Zoo canonical',
            'status' => 'occupied',
        ]);

        $duplicateShape = $this->createShape($market, (int) $duplicate->id);
        $conflictingCandidateShape = $this->createShape($market, (int) $candidate->id);

        DB::table('tenant_user_market_spaces')->insert([
            'user_id' => $user->id,
            'market_space_id' => $duplicate->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('marketplace_products')->insertGetId([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'title' => 'Zoo product',
            'slug' => 'zoo-product-' . $duplicate->id,
            'currency' => 'RUB',
            'stock_qty' => 0,
            'is_active' => true,
            'is_featured' => false,
            'created_at' => now(),
            'updated_at' => now(),
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

        $accrual = TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $candidate->id,
            'period' => now()->startOfMonth()->toDateString(),
            'source_row_hash' => sha1('duplicate-resolution-candidate-accrual'),
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION,
            'market_space_id' => $duplicate->id,
            'candidate_market_space_id' => $candidate->id,
            'reason' => 'Manual duplicate review',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'applied')
            ->assertJsonPath('operation.decision', SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION)
            ->assertJsonPath('item.review_status', 'changed');

        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_id', $duplicate->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('applied', $operation->status);
        $this->assertSame(SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION, $operation->payload['decision'] ?? null);
        $this->assertSame($candidate->id, $operation->payload['candidate_market_space_id'] ?? null);
        $this->assertSame('Manual duplicate review', $operation->payload['reason'] ?? null);

        $duplicate->refresh();
        $duplicateShape->refresh();
        $conflictingCandidateShape->refresh();
        $contract->refresh();
        $accrual->refresh();

        $this->assertFalse((bool) $duplicate->is_active);
        $this->assertSame('P/3', $duplicate->number);
        $this->assertSame('Zoo', $duplicate->display_name);
        $this->assertSame('occupied', $duplicate->status);
        $this->assertSame('changed', $duplicate->map_review_status);
        $this->assertSame($candidate->id, $duplicateShape->market_space_id);
        $this->assertTrue((bool) $duplicateShape->is_active);
        $this->assertNull($conflictingCandidateShape->market_space_id);
        $this->assertFalse((bool) $conflictingCandidateShape->is_active);
        $this->assertSame($candidate->id, $contract->market_space_id);
        $this->assertSame($candidate->id, $accrual->market_space_id);
        $this->assertSame($candidate->id, DB::table('marketplace_products')->where('id', $productId)->value('market_space_id'));

        $this->assertDatabaseHas('tenant_user_market_spaces', [
            'user_id' => $user->id,
            'market_space_id' => $candidate->id,
        ]);
        $this->assertDatabaseMissing('tenant_user_market_spaces', [
            'user_id' => $user->id,
            'market_space_id' => $duplicate->id,
        ]);

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('Разбор дубля места', false)
            ->assertSee('Основное: #' . $candidate->id . ' · 5 / Zoo canonical', false)
            ->assertSee('Дубль выведен из контура: #' . $duplicate->id . ' · P/3 / Zoo', false)
            ->assertSee('Перенесено: карта 1, кабинет 1, товары 1', false)
            ->assertSee('Блокирующие связи на дубле: договоры 0, начисления 0', false)
            ->assertSee('Договоры, начисления и долги не переносились', false);
    }

    public function test_review_decision_endpoint_resolves_duplicate_with_snapshot_binding_and_closes_canonical_review(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant Snapshot',
            'is_active' => true,
        ]);

        $duplicate = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => '218',
            'display_name' => 'СТ/склад/11/1',
            'status' => 'occupied',
        ]);

        $canonical = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => '116',
            'display_name' => '116 / Samokat',
            'status' => 'occupied',
        ]);

        $duplicateShape = $this->createShape($market, (int) $duplicate->id);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $market->id,
            'market_space_id' => $duplicate->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => $user->id,
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => json_encode([
                'status' => 'occupied',
                'is_active' => true,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $canonicalContract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $canonical->id,
            'number' => 'DOG-116',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $canonicalContract->id,
            'market_space_id' => $canonical->id,
            'period' => '2026-01-01',
            'source_row_hash' => sha1('canonical-latest-accrual'),
        ]);

        foreach (['2025-01-01', '2025-02-01', '2025-03-01', '2025-04-01'] as $period) {
            TenantAccrual::create([
                'market_id' => $market->id,
                'tenant_id' => $tenant->id,
                'market_space_id' => $duplicate->id,
                'period' => $period,
                'source' => '1c',
                'total_with_vat' => 30000,
                'tenant_contract_id' => null,
            ]);
        }

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION,
            'market_space_id' => $duplicate->id,
            'candidate_market_space_id' => $canonical->id,
            'reason' => 'Snapshot binding duplicate resolution',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'applied')
            ->assertJsonPath('operation.decision', SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION)
            ->assertJsonPath('item.review_status', 'changed');

        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_id', $duplicate->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('applied', $operation->status);
        $this->assertSame($canonical->id, $operation->payload['candidate_market_space_id'] ?? null);
        $this->assertSame('Snapshot binding duplicate resolution', $operation->payload['reason'] ?? null);

        $duplicate->refresh();
        $duplicateShape->refresh();
        $canonical->refresh();

        $this->assertFalse((bool) $duplicate->is_active);
        $this->assertSame('changed', $duplicate->map_review_status);
        $this->assertSame('matched', $canonical->map_review_status);
        $this->assertSame($canonical->id, $duplicateShape->market_space_id);
        $this->assertSame(0, DB::table('market_space_map_shapes')->where('market_space_id', $duplicate->id)->count());

        $attentionRows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 50);
        $attentionSpaceIds = collect($attentionRows)->pluck('space_id')->all();

        $this->assertNotContains($canonical->id, $attentionSpaceIds);
        $this->assertNotContains($duplicate->id, $attentionSpaceIds);
    }

    public function test_review_decision_endpoint_blocks_duplicate_resolution_when_duplicate_has_business_links(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $duplicate = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => 'P/3',
            'display_name' => 'Zoo',
            'status' => 'occupied',
        ]);

        $candidate = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'number' => '5',
            'display_name' => 'Zoo canonical',
            'status' => 'occupied',
        ]);

        $duplicateShape = $this->createShape($market, (int) $duplicate->id);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $duplicate->id,
            'number' => 'DOG-DUP',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION,
            'market_space_id' => $duplicate->id,
            'candidate_market_space_id' => $candidate->id,
            'reason' => 'Manual duplicate review',
        ]);

        $response->assertStatus(422);

        $duplicate->refresh();
        $duplicateShape->refresh();

        $this->assertTrue((bool) $duplicate->is_active);
        $this->assertSame($duplicate->id, $duplicateShape->market_space_id);
        $this->assertTrue((bool) $duplicateShape->is_active);
        $this->assertSame(0, Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_id', $duplicate->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->count());
    }

    public function test_review_decision_endpoint_retires_merged_space_without_moving_financial_history(): void
    {
        $market = $this->createMarket();
        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant History',
            'is_active' => true,
        ]);
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $retired = $this->createSpace($market, [
            'number' => 'OLD-1',
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
            'is_active' => true,
        ]);
        $canonical = $this->createSpace($market, [
            'number' => 'MAIN-1',
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
            'is_active' => true,
        ]);
        $shape = $this->createShape($market, (int) $retired->id);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $retired->id,
            'number' => 'C-OLD',
            'status' => 'active',
            'starts_at' => '2026-01-01',
            'is_active' => true,
        ]);

        TenantAccrual::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $retired->id,
            'period' => '2026-04-01',
            'rent_rate' => 1000,
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $retired->id,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'test',
            'started_at' => '2026-04-01 00:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL,
            'market_space_id' => $retired->id,
            'candidate_market_space_id' => $canonical->id,
            'effective_date' => '2026-05-01',
            'reason' => 'Merged into main space.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operation.decision', SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL);

        $retired->refresh();
        $shape->refresh();

        $this->assertFalse((bool) $retired->is_active);
        $this->assertSame('maintenance', $retired->status);
        $this->assertStringContainsString('MAIN-1', (string) $retired->notes);
        $this->assertStringContainsString('2026-05-01', (string) $retired->notes);
        $this->assertNull($shape->market_space_id);
        $this->assertFalse((bool) $shape->is_active);

        $this->assertDatabaseHas('tenant_contracts', [
            'id' => $contract->id,
            'market_space_id' => $retired->id,
        ]);
        $this->assertDatabaseHas('tenant_accruals', [
            'tenant_contract_id' => $contract->id,
            'market_space_id' => $retired->id,
        ]);
        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'market_id' => $market->id,
            'market_space_id' => $retired->id,
            'resolution_reason' => 'space_merged_into_canonical',
        ]);
    }

    public function test_review_results_page_renders_merge_retirement_action_for_merge_conflict(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $user->forceFill(['name' => 'Review Admin'])->save();

        $space = $this->createSpace($market, [
            'number' => 'OLD-2',
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $user->id,
        ]);

        Operation::create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'observed',
            'payload' => [
                'market_space_id' => (int) $space->id,
                'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                'reason' => 'Удалить место. Прибавлено к соседнему месту.',
            ],
            'created_by' => $user->id,
        ]);

        $this->withSession(['filament.admin.selected_market_id' => $market->id]);

        $this->get(MapReviewResults::getUrl(['tab' => 'review']))
            ->assertOk()
            ->assertSee('Упразднить и связать с основным местом', false)
            ->assertSee('data-mrr-merge-retire-open', false)
            ->assertSee('ID основного места', false)
            ->assertSee('Дата действия', false);
    }

    public function test_review_decision_endpoint_creates_operation_for_matched(): void
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
            'reason' => 'Checked manually, no data change needed',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'lightweight')
            ->assertJsonPath('item.market_space_id', $space->id)
            ->assertJsonPath('item.review_status', 'matched');

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'observed',
        ]);

        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame('matched', $operation->payload['decision'] ?? null);
        $this->assertSame('Checked manually, no data change needed', $operation->payload['reason'] ?? null);
        $this->assertSame('Checked manually, no data change needed', $operation->comment);
    }

    public function test_review_decision_endpoint_applies_mark_space_free_and_shows_it_in_applied_changes(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'status' => 'occupied',
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'applied')
            ->assertJsonPath('item.market_space_id', $space->id)
            ->assertJsonPath('item.review_status', 'changed')
            ->assertJsonPath('item.review_status_label', 'Есть безопасное изменение');

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
        $this->assertNull($operation->comment);
        $this->assertSame($user->id, $operation->created_by);

        $space->refresh();
        $this->assertSame('vacant', $space->status);
        $this->assertSame('changed', $space->map_review_status);
        $this->assertNotNull($space->map_reviewed_at);
        $this->assertSame($user->id, $space->map_reviewed_by);

        Livewire::withQueryParams(['tab' => 'review'])
            ->test(MapReviewResults::class)
            ->assertSee('Применено', false)
            ->assertSee('Отметить место как свободное', false)
            ->assertSee('Есть безопасное изменение', false);
    }

    public function test_review_decision_endpoint_applies_mark_space_free_and_closes_only_snapshot_binding(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Snapshot Tenant',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => $user->id,
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => json_encode([
                'status' => 'occupied',
                'is_active' => true,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'SNAP-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'binding_type' => 'space_snapshot',
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'ended_at' => null,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_FREE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'applied')
            ->assertJsonPath('item.review_status', 'changed')
            ->assertJsonPath('item.review_status_label', 'Есть безопасное изменение');

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
        $this->assertSame('vacant', $space->status);
        $this->assertSame('changed', $space->map_review_status);

        $snapshotBinding = DB::table('market_space_tenant_bindings')
            ->where('market_space_id', $space->id)
            ->whereNull('tenant_contract_id')
            ->where('binding_type', 'space_snapshot')
            ->orderByDesc('id')
            ->first();

        $contractBinding = DB::table('market_space_tenant_bindings')
            ->where('market_space_id', $space->id)
            ->where('tenant_contract_id', $contract->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($snapshotBinding);
        $this->assertNotNull($contractBinding);
        $this->assertNotNull($snapshotBinding->ended_at);
        $this->assertNull($contractBinding->ended_at);
        $this->assertTrue((bool) $contract->fresh()->is_active);
    }

    public function test_review_decision_endpoint_applies_mark_space_service_and_closes_only_snapshot_binding(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Service Tenant',
            'is_active' => true,
        ]);

        $space = $this->createSpace($market, [
            'tenant_id' => $tenant->id,
            'status' => 'occupied',
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => $user->id,
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => json_encode([
                'status' => 'occupied',
                'is_active' => true,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $contract = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'SER-001',
            'status' => 'active',
            'starts_at' => now()->startOfMonth()->toDateString(),
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'binding_type' => 'space_snapshot',
            'ended_at' => null,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => $contract->id,
            'ended_at' => null,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::MARK_SPACE_SERVICE,
            'market_space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'operation')
            ->assertJsonPath('operation.status', 'applied')
            ->assertJsonPath('item.review_status', 'changed')
            ->assertJsonPath('item.review_status_label', 'Есть безопасное изменение');

        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame('applied', $operation->status);
        $this->assertSame(SpaceReviewDecision::MARK_SPACE_SERVICE, $operation->payload['decision'] ?? null);
        $this->assertSame($space->id, $operation->payload['market_space_id'] ?? null);
        $this->assertNull($operation->comment);

        $space->refresh();
        $this->assertSame('maintenance', $space->status);
        $this->assertSame('changed', $space->map_review_status);

        $snapshotBinding = DB::table('market_space_tenant_bindings')
            ->where('market_space_id', $space->id)
            ->whereNull('tenant_contract_id')
            ->where('binding_type', 'space_snapshot')
            ->orderByDesc('id')
            ->first();

        $contractBinding = DB::table('market_space_tenant_bindings')
            ->where('market_space_id', $space->id)
            ->where('tenant_contract_id', $contract->id)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($snapshotBinding);
        $this->assertNotNull($contractBinding);
        $this->assertNotNull($snapshotBinding->ended_at);
        $this->assertNull($contractBinding->ended_at);
        $this->assertTrue((bool) $contract->fresh()->is_active);
    }
    public function test_review_decision_endpoint_clears_cached_ai_summary_for_reviewed_space(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        app(AiReviewService::class)->cacheSuccess($space->id, (int) $market->id, [
            'summary' => 'Cached before review ' . $space->id,
            'why_flagged' => 'Cached reason ' . $space->id,
            'recommended_next_step' => 'Cached step ' . $space->id,
            'risk_score' => 7,
            'confidence' => 0.89,
        ]);

        $this->assertNotNull(app(AiReviewService::class)->getCachedReviewForSpace($space->id, (int) $market->id));

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => 'matched',
            'market_space_id' => $space->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.review_status', 'matched');

        $this->assertNull(app(AiReviewService::class)->getCachedReviewForSpace($space->id, (int) $market->id));
    }

    public function test_snapshot_affecting_operation_clears_cached_ai_summary_for_space(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);

        $space = $this->createSpace($market, [
            'area_sqm' => 10,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
        ]);

        app(AiReviewService::class)->cacheSuccess($space->id, (int) $market->id, [
            'summary' => 'Cached before attrs change ' . $space->id,
            'why_flagged' => 'Cached attrs reason ' . $space->id,
            'recommended_next_step' => 'Cached attrs step ' . $space->id,
            'risk_score' => 7,
            'confidence' => 0.88,
        ]);

        $this->assertNotNull(app(AiReviewService::class)->getCachedReviewForSpace($space->id, (int) $market->id));

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'status' => 'applied',
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'area_sqm' => 25,
            ],
            'created_by' => $user->id,
        ]);

        $space->refresh();

        $this->assertSame(25.0, (float) $space->area_sqm);
        $this->assertNull(app(AiReviewService::class)->getCachedReviewForSpace($space->id, (int) $market->id));
    }

    public function test_review_history_is_preserved_when_conflict_is_resolved_with_matched(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market);

        $conflictResponse = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
            'market_space_id' => $space->id,
            'reason' => 'Conflict detected on inspection',
        ]);

        $conflictResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('operation.status', 'observed')
            ->assertJsonPath('item.review_status', 'conflict');

        $matchedResponse = $this->withCsrfToken()->postJson('/admin/market-map/review-decision', [
            'decision' => 'matched',
            'market_space_id' => $space->id,
        ]);

        $matchedResponse->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('item.review_status', 'matched');

        $operations = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $operations);
        $this->assertSame(SpaceReviewDecision::OCCUPANCY_CONFLICT, $operations[0]->payload['decision'] ?? null);
        $this->assertSame('matched', $operations[1]->payload['decision'] ?? null);

        $space->refresh();
        $this->assertSame('matched', $space->map_review_status);
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

    public function test_map_review_results_shows_tenant_change_operation_details_on_card(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $user->forceFill(['name' => 'Super Admin'])->save();

        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'ОС20/3',
            'display_name' => 'Остров 20 (рыба)',
        ]);

        Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Бакиева',
                'reason' => 'с 01.05.26 г',
            ],
            'created_by' => $user->id,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Фактический арендатор', false)
            ->assertSee('Бакиева', false)
            ->assertSee('Подсказка ревизора', false)
            ->assertSee('с 01.05.26 г', false)
            ->assertDontSee('Автор', false)
            ->assertSee('Super Admin', false)
            ->assertDontSee('Дата фиксации', false);
    }

    public function test_map_review_results_hides_empty_observed_tenant_line_for_legacy_tenant_change_operation(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $user->forceFill(['name' => 'Super Admin'])->save();

        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'ОС20/4',
            'display_name' => 'Остров 20 (мясо)',
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
                'observed_tenant_name' => 'Временный арендатор',
                'reason' => 'Комментарий без арендатора в payload',
            ],
            'created_by' => $user->id,
        ]);

        DB::table('operations')
            ->where('id', $operation->id)
            ->update([
                'payload' => json_encode([
                    'market_space_id' => $space->id,
                    'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                    'reason' => 'Комментарий без арендатора в payload',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        Livewire::test(MapReviewResults::class)
            ->assertDontSee('Фактический арендатор', false)
            ->assertSee('Подсказка ревизора', false)
            ->assertSee('Комментарий без арендатора в payload', false)
            ->assertDontSee('Автор', false)
            ->assertDontSee('Дата фиксации', false);
    }

    public function test_market_map_review_navigation_uses_fresh_snapshot_and_explicitly_handles_no_pending_places(): void
    {
        $market = $this->createMarket();
        $market->forceFill([
            'settings' => [
                'map_pdf_path' => 'market-maps/test-map.pdf',
            ],
        ])->save();

        Storage::disk('local')->put('market-maps/test-map.pdf', 'fake pdf');

        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $response = $this->get('/admin/market-map?mode=review');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private')
            ->assertSee("opts.cache = opts.cache || 'no-store';", false)
            ->assertSee('await loadShapes();', false)
            ->assertSee("const pendingCount = getPendingReviewNavCount();", false)
            ->assertSee('Непройденных мест не осталось', false)
            ->assertSee('Есть непройденные места без фигур на карте', false);
    }

    public function test_market_map_review_navigation_sorts_items_by_visible_label_order(): void
    {
        $blade = file_get_contents(resource_path('views/admin/market-map.blade.php'));
        $start = strpos($blade, 'function getReviewNavSortLabel(item)');
        $end = strpos($blade, 'function getReviewCurrentIndex()', $start);

        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $this->assertGreaterThan($start, $end);

        $script = substr($blade, $start, $end - $start);
        $script .= <<<'JS'

const items = [
  { id: 1, number: '1', code: '', displayName: '' },
  { id: 2, number: '2', code: '', displayName: '' },
  { id: 10, number: '10', code: '', displayName: '' },
  { id: 20, number: '', code: '', displayName: '11' },
  { id: 30, number: '', code: '12', displayName: '' },
];

const order = items.slice().sort(compareReviewNavItems).map((item) => String(item.id));
console.log(JSON.stringify(order));
JS;

        $process = new Process(['node', '-e', $script]);
        $process->setTimeout(20);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());

        $order = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['1', '2', '10', '20', '30'], $order);
    }

    public function test_market_map_review_navigation_hides_matched_on_free_spaces(): void
    {
        $blade = file_get_contents(resource_path('views/admin/market-map.blade.php'));
        $this->assertStringNotContainsString('Подсказка:', $blade);
        $this->assertStringNotContainsString('Свободно — место фактически пустое. Совпало — место занято и соответствует данным системы.', $blade);
        $this->assertStringContainsString('data-decision="matched"', $blade);
        $this->assertStringContainsString('data-decision="mark_space_free"', $blade);
    }

    public function test_market_map_review_navigation_skips_stale_reviewed_candidate_for_next_pending(): void
    {
        $blade = file_get_contents(resource_path('views/admin/market-map.blade.php'));
        $start = strpos($blade, 'function getReviewCurrentIndex()');
        $end = strpos($blade, 'updateReviewNavUi = function ()', $start);

        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $this->assertGreaterThan($start, $end);

        $script = "const SPACE_URL = '/admin/market-map/space';\nlet reviewNavItems = [];\n";
        $script .= substr($blade, $start, $end - $start);
        $script .= <<<'JS'

reviewNavItems = [
  { id: 1, number: '1', reviewStatus: 'matched', reviewStatusLabel: 'Совпало' },
  { id: 2, number: '2', reviewStatus: '', reviewStatusLabel: '' },
  { id: 3, number: '3', reviewStatus: '', reviewStatusLabel: '' },
];

(async () => {
  const requested = [];
  const target = await resolveNextPendingReviewTarget(0, async (spaceId) => {
    requested.push(spaceId);

    if (spaceId === 2) {
      return null;
    }

    if (spaceId === 3) {
      return {
        id: 3,
        number: '3',
        reviewStatus: '',
        reviewStatusLabel: '',
      };
    }

    return null;
  });

  console.log(JSON.stringify({
    targetIndex: target ? target.index : null,
    targetId: target?.item?.id ?? null,
    requested,
    statuses: reviewNavItems.map((item) => item.reviewStatus || ''),
  }));
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
JS;

        $process = new Process(['node', '-e', $script]);
        $process->setTimeout(20);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());

        $payload = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(2, $payload['targetIndex']);
        $this->assertSame(3, $payload['targetId']);
        $this->assertSame([2, 3], $payload['requested']);
        $this->assertSame(['matched', '', ''], $payload['statuses']);
    }

    public function test_market_map_review_navigation_advances_across_repeated_next_pending_clicks(): void
    {
        $blade = file_get_contents(resource_path('views/admin/market-map.blade.php'));
        $start = strpos($blade, 'function getReviewCurrentIndex()');
        $end = strpos($blade, 'function normalizeBbox(raw)', $start);

        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $this->assertGreaterThan($start, $end);

        $script = "const SPACE_URL = '/admin/market-map/space';\n";
        $script .= "let reviewNavItems = [];\n";
        $script .= "let chosenSpace = null;\n";
        $script .= "let currentViewport = { convertToViewportPoint: () => [0, 0] };\n";
        $script .= "let canvas = { getBoundingClientRect: () => ({ left: 0, top: 0 }) };\n";
        $script .= "let overlay = { dispatchEvent: () => {} };\n";
        $script .= "let isProgrammaticNavigation = false;\n";
        $script .= "let navigateReview = null;\n";
        $script .= "let updateReviewNavUi = function () {};\n";
        $script .= "function setChosenSpace(space) { chosenSpace = space ? { ...space } : null; }\n";
        $script .= "async function loadShapes() {}\n";
        $script .= "async function refreshChosenSpaceFromServer() { chosenSpace = null; }\n";
        $script .= "async function centerOnBbox() {}\n";
        $script .= "function nextUiFrame() { return Promise.resolve(); }\n";
        $script .= "function MouseEvent(type, init) { this.type = type; this.init = init || {}; }\n";
        $script .= "const window = { location: { origin: 'http://example.test' } };\n";
        $script .= substr($blade, $start, $end - $start);
        $script .= <<<'JS'

updateReviewNavUi = function () {};
fetchReviewNavSpaceById = async function (spaceId) {
  return reviewNavItems.find((item) => Number(item.id) === Number(spaceId)) || null;
};

reviewNavItems = [
  { id: 1, number: '1', reviewStatus: 'matched', reviewStatusLabel: 'matched', bbox: { x1: 0, y1: 0, x2: 10, y2: 10 } },
  { id: 2, number: '2', reviewStatus: '', reviewStatusLabel: '', bbox: { x1: 10, y1: 0, x2: 20, y2: 10 } },
  { id: 3, number: '3', reviewStatus: '', reviewStatusLabel: '', bbox: { x1: 20, y1: 0, x2: 30, y2: 10 } },
];
chosenSpace = { ...reviewNavItems[0] };

(async () => {
  await navigateReview('next-pending');
  const firstChosenId = chosenSpace ? chosenSpace.id : null;

  await navigateReview('next-pending');
  const secondChosenId = chosenSpace ? chosenSpace.id : null;

  console.log(JSON.stringify({
    firstChosenId,
    secondChosenId,
  }));
})().catch((error) => {
  console.error(error);
  process.exit(1);
});
JS;

        $scriptPath = tempnam(sys_get_temp_dir(), 'review-nav-next-pending-');
        $this->assertNotFalse($scriptPath);
        file_put_contents($scriptPath, $script);

        try {
            $process = new Process(['node', $scriptPath]);
            $process->setTimeout(20);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());

            $payload = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($scriptPath);
        }

        $this->assertSame(2, $payload['firstChosenId']);
        $this->assertSame(3, $payload['secondChosenId']);
    }

    public function test_market_map_spaces_without_shapes_filter_returns_unreviewed_places_without_usable_bbox(): void
    {
        $this->withoutExceptionHandling();

        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-spaces-without-shapes',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'short_name' => 'TT',
        ]);

        // Место 1: непройденное, без shape → ПОПАДАЕТ
        $spaceWithoutShape = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '1',
            'code' => 'space-1',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        // Место 2: непройденное, с active shape и usable bbox → НЕ попадает
        $spaceWithUsableBbox = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '2',
            'code' => 'space-2',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        if (Schema::hasTable('market_space_map_shapes')) {
            MarketSpaceMapShape::create([
                'market_id' => $market->id,
                'market_space_id' => $spaceWithUsableBbox->id,
                'page' => 1,
                'version' => 1,
                'polygon' => [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 0], ['x' => 10, 'y' => 10], ['x' => 0, 'y' => 10]],
                'bbox_x1' => 0,
                'bbox_y1' => 0,
                'bbox_x2' => 10,
                'bbox_y2' => 10,
                'is_active' => true,
            ]);
        }

        // Место 3: непройденное, с active shape но БЕЗ usable bbox (polygon <3 точек) → ПОПАДАЕТ
        // bbox_x1..y2 = 0 (не null, но x1 >= x2 и y1 >= y2 → не usable)
        $spaceWithUnusableBbox = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '3',
            'code' => 'space-3',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        if (Schema::hasTable('market_space_map_shapes')) {
            MarketSpaceMapShape::create([
                'market_id' => $market->id,
                'market_space_id' => $spaceWithUnusableBbox->id,
                'page' => 1,
                'version' => 1,
                'polygon' => [['x' => 0, 'y' => 0], ['x' => 10, 'y' => 10]], // только 2 точки
                'bbox_x1' => 0,
                'bbox_y1' => 0,
                'bbox_x2' => 0,
                'bbox_y2' => 0,
                'is_active' => true,
            ]);
        }

        // Место 4: непройденное, с active shape но БЕЗ usable bbox (bbox 0/0/0/0, polygon empty) → ПОПАДАЕТ
        $spaceWithZeroBbox = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '4',
            'code' => 'space-4',
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        if (Schema::hasTable('market_space_map_shapes')) {
            MarketSpaceMapShape::create([
                'market_id' => $market->id,
                'market_space_id' => $spaceWithZeroBbox->id,
                'page' => 1,
                'version' => 1,
                'polygon' => [],
                'bbox_x1' => 0,
                'bbox_y1' => 0,
                'bbox_x2' => 0,
                'bbox_y2' => 0,
                'is_active' => true,
            ]);
        }

        // Место 5: пройденное (есть map_review_status), без shape → НЕ попадает
        $spaceReviewed = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '5',
            'code' => 'space-5',
            'tenant_id' => $tenant->id,
            'is_active' => true,
            'map_review_status' => 'matched',
            'map_reviewed_at' => now(),
        ]);

        // Место 6: неактивное, без shape → НЕ попадает
        $spaceInactive = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '6',
            'code' => 'space-6',
            'tenant_id' => $tenant->id,
            'is_active' => false,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        $response = $this->get('/admin/market-map/spaces?without_shapes=1');

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('meta.without_shapes', true);

        $items = $response->json('items');
        $this->assertIsArray($items);

        $ids = array_column($items, 'id');

        // ДОЛЖНЫ попасть: непройденные, активные, без usable bbox
        $this->assertContains($spaceWithoutShape->id, $ids, 'Место без shape должно попасть');
        $this->assertContains($spaceWithUnusableBbox->id, $ids, 'Место с unusable bbox (polygon <3) должно попасть');
        $this->assertContains($spaceWithZeroBbox->id, $ids, 'Место с zero bbox должно попасть');

        // НЕ должны попасть
        $this->assertNotContains($spaceWithUsableBbox->id, $ids, 'Место с usable bbox не должно попасть');
        $this->assertNotContains($spaceReviewed->id, $ids, 'Пройденное место не должно попасть');
        $this->assertNotContains($spaceInactive->id, $ids, 'Неактивное место не должно попасть');

        // Проверяем структуру ответа
        $item = reset($items);
        if ($item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('number', $item);
            $this->assertArrayHasKey('code', $item);
            $this->assertArrayHasKey('display_name', $item);
            $this->assertArrayHasKey('tenant', $item);
            $this->assertArrayHasKey('without_shapes', $item);
            $this->assertArrayHasKey('binding_risk', $item);
            $this->assertTrue($item['without_shapes']);
            $this->assertIsArray($item['binding_risk']);
            $this->assertArrayHasKey('requires_confirmation', $item['binding_risk']);
            $this->assertArrayHasKey('warnings', $item['binding_risk']);
        }
    }

    public function test_market_map_spaces_without_shapes_filter_requires_tables_and_columns(): void
    {
        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-without-shapes-requirements',
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        // Если таблица market_space_map_shapes не существует, должен быть error
        if (! Schema::hasTable('market_space_map_shapes')) {
            $response = $this->get('/admin/market-map/spaces?without_shapes=1');
            $response->assertStatus(422)
                ->assertJsonPath('ok', false);
            return;
        }

        // Если колонки map_review_status нет, должен быть error
        if (! $this->hasMapReviewColumns()) {
            $response = $this->get('/admin/market-map/spaces?without_shapes=1');
            $response->assertStatus(422)
                ->assertJsonPath('ok', false);
            return;
        }

        // Если всё есть — успешный ответ
        $response = $this->get('/admin/market-map/spaces?without_shapes=1');
        $response->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_market_map_spaces_without_shapes_with_q_filter_by_number(): void
    {
        if (! Schema::hasTable('market_space_map_shapes') || ! $this->hasMapReviewColumns()) {
            $this->markTestSkipped('Required tables/columns not available');
        }

        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-without-shapes-search-' . uniqid(),
        ]);

        $tenant = \App\Models\Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'ООО "Автосервис"',
            'short_name' => 'Автосервис',
        ]);

        // Место без фигур с номером "А-101"
        $space1 = \App\Models\MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => 'А-101',
            'code' => 'A101',
            'display_name' => 'Место А-101',
            'tenant_id' => (int) $tenant->id,
        ]);

        // Место без фигур с номером "Б-202"
        $space2 = \App\Models\MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => 'Б-202',
            'code' => 'B202',
            'display_name' => 'Место Б-202',
            'tenant_id' => null,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        // Поиск по номеру "А-"
        $response = $this->get('/admin/market-map/spaces?without_shapes=1&q=А-');
        $response->assertOk()
            ->assertJsonPath('ok', true);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals('А-101', $items[0]['number']);

        // Поиск по display_name "Место Б"
        $response = $this->get('/admin/market-map/spaces?without_shapes=1&q=Место+Б');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals('Б-202', $items[0]['number']);
    }

    public function test_market_map_spaces_without_shapes_with_q_filter_by_tenant(): void
    {
        if (! Schema::hasTable('market_space_map_shapes') || ! $this->hasMapReviewColumns()) {
            $this->markTestSkipped('Required tables/columns not available');
        }

        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-without-shapes-tenant-search',
        ]);

        $tenant1 = \App\Models\Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'ООО "Автосервис"',
            'short_name' => 'Автосервис',
            'display_name' => 'Автосервис Плюс',
        ]);

        $tenant2 = \App\Models\Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'ИП Иванов',
            'short_name' => 'Иванов',
            'display_name' => '',
        ]);

        $space1 = \App\Models\MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => 'А-101',
            'code' => 'A101',
            'display_name' => 'Место А-101',
            'tenant_id' => (int) $tenant1->id,
        ]);

        $space2 = \App\Models\MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => 'Б-202',
            'code' => 'B202',
            'display_name' => 'Место Б-202',
            'tenant_id' => (int) $tenant2->id,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        // Поиск по tenant name "Авто"
        $response = $this->get('/admin/market-map/spaces?without_shapes=1&q=Авто');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals('А-101', $items[0]['number']);

        // Поиск по tenant short_name "Иванов"
        $response = $this->get('/admin/market-map/spaces?without_shapes=1&q=Иванов');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertEquals('Б-202', $items[0]['number']);
    }

    public function test_market_map_spaces_without_shapes_excludes_places_with_usable_shapes(): void
    {
        if (! Schema::hasTable('market_space_map_shapes') || ! $this->hasMapReviewColumns()) {
            $this->markTestSkipped('Required tables/columns not available');
        }

        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-market-without-shapes-exclude',
        ]);

        $tenant = \App\Models\Tenant::create([
            'market_id' => (int) $market->id,
            'name' => 'ООО "Автосервис"',
            'short_name' => 'Автосервис',
        ]);

        // Место БЕЗ фигур
        $spaceWithoutShapes = \App\Models\MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => 'А-101',
            'code' => 'A101',
            'display_name' => 'Место А-101',
            'tenant_id' => (int) $tenant->id,
        ]);

        // Место С usable bbox (должно быть исключено даже при совпадении q)
        $spaceWithShapes = \App\Models\MarketSpace::create([
            'market_id' => (int) $market->id,
            'number' => 'Автосервис-1',
            'code' => 'AUTO1',
            'display_name' => 'Автосервис Место',
            'tenant_id' => (int) $tenant->id,
        ]);

        \App\Models\MarketSpaceMapShape::create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $spaceWithShapes->id,
            'page' => 1,
            'version' => 1,
            'is_active' => true,
            'bbox_x1' => 10.0,
            'bbox_y1' => 10.0,
            'bbox_x2' => 20.0,
            'bbox_y2' => 20.0,
        ]);

        $this->actingAsSuperAdmin((int) $market->id);

        // Поиск по "Авто" должен вернуть только место без фигур
        $response = $this->get('/admin/market-map/spaces?without_shapes=1&q=Авто');
        $response->assertOk();
        $items = $response->json('items');

        // Должно быть только 1 место (без фигур), место с фигурами исключено
        $this->assertCount(1, $items);
        $this->assertEquals('А-101', $items[0]['number']);

        // Убедимся что место с usable bbox не попало
        $ids = array_column($items, 'id');
        $this->assertNotContains((int) $spaceWithShapes->id, $ids);
    }

    public function test_applied_space_review_shows_auto_close_info_when_payload_contains_auto_closed_by_reconciliation(): void
    {
        $market = $this->createMarket();
        $user = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $space = $this->createSpace($market, [
            'number' => 'AUTO-CLOSE-TEST',
            'display_name' => 'Auto-close test space',
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
        ]);

        $operation = Operation::create([
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => now(),
            'status' => 'applied',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => 'Some tenant',
                'reason' => 'Tenant changed on site',
                'auto_closed_by_reconciliation' => true,
                'auto_close_at' => '2026-04-30 11:06:00',
                'auto_close_binding_id' => 247,
            ],
            'created_by' => $user->id,
        ]);

        $this->assertTrue($operation->payload['auto_closed_by_reconciliation'] ?? false);
        $this->assertSame('2026-04-30 11:06:00', $operation->payload['auto_close_at'] ?? null);
        $this->assertSame(247, $operation->payload['auto_close_binding_id'] ?? null);

        $service = app(\App\Services\MarketMap\MapReviewResultsService::class);
        $changes = $service->appliedChanges((int) $market->id, 50);

        $this->assertNotEmpty($changes, 'Applied changes should not be empty');

        $autoClosedChange = collect($changes)->first(fn ($change) => $change['space_id'] === $space->id);

        $this->assertNotNull($autoClosedChange, 'Should find change for space');
        $this->assertTrue($autoClosedChange['is_auto_closed'] ?? false, 'is_auto_closed should be true');
        $this->assertSame('30.04.2026 11:06', $autoClosedChange['auto_close_at'] ?? null);
        $this->assertSame(247, $autoClosedChange['auto_close_binding_id'] ?? null);

        $html = Livewire::test(MapReviewResults::class)->html();

        $this->assertStringContainsString('AUTO-CLOSE-TEST', $html, 'Space number should be in HTML');
        $this->assertStringContainsString('Закрыто автоматически', $html, 'Auto-close label should be in HTML');
        $this->assertStringContainsString('Основание: договорная привязка #247', $html, 'Binding ID should be in HTML');
    }

    private function hasMapReviewColumns(): bool
    {
        if (! Schema::hasTable('market_spaces')) {
            return false;
        }

        return Schema::hasColumn('market_spaces', 'map_review_status');
    }
}
