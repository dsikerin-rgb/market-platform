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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            ->assertSee('Быстрое решение', false)
            ->assertSee('Совпало', false)
            ->assertSee('Конфликт по занятости', false)
            ->assertSee('Фигура не найдена на карте', false)
            ->assertSee('Уточнить', false)
            ->assertSee('Что значит «Уточнить»', false)
            ->assertSee('Это ручное решение для случаев, когда номер, название или другая идентичность места требуют дополнительной проверки. Данные места не меняются, а в истории ревизии фиксируется сам факт, что нужен отдельный разбор.', false);
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
            ->assertDontSee('Применить уточнение', false)
            ->assertDontSee('mrrClarifyModal', false)
            ->assertDontSee('data-mrr-clarify-action="open"', false)
            ->assertDontSee('mrrClarifyNumberInput', false)
            ->assertDontSee('mrrClarifyDisplayNameInput', false)
            ->assertDontSee('mrrClarifyInput', false)
            ->assertDontSee('data-space-number="П/3"', false)
            ->assertDontSee('data-space-display-name="Зоомир"', false)
            ->assertSee('Анализ связей', false)
            ->assertSee('Связи текущего места', false)
            ->assertSee('Карта: 1', false)
            ->assertSee('Кабинет: 1', false)
            ->assertSee('Кандидаты того же арендатора', false)
            ->assertSee('Есть более сильный кандидат', false)
            ->assertSee('Есть кандидат с более сильными подтверждёнными связями. Его нужно проверить как возможное основное место.', false)
            ->assertSee('#' . $candidate->id . ' · 5 / Зоомир ООО', false)
            ->assertSee('Договоры: 1', false)
            ->assertSee('Начисления: 1', false)
            ->assertSee('Открыть место', false)
            ->assertSee('Открыть карту', false)
            ->assertSee('Проверить как основное', false)
            ->assertSee('data-mrr-duplicate-plan-create', false)
            ->assertSee('mrrDuplicatePlanModal', false)
            ->assertSee('План безопасного разбора', false)
            ->assertSee('Выбрать кандидата основным', false)
            ->assertSee('Договоры, начисления и долги не переносятся', false);
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
            ],
            'created_by' => $user->id,
        ]);

        Livewire::test(\App\Filament\Pages\MapReviewResults::class)
            ->assertSee('Текущее место не слабее', false)
            ->assertSee('Текущее место не слабее кандидатов по подтверждённым связям. Не выбирайте кандидата основным без дополнительной проверки.', false)
            ->assertSee('#' . $candidate->id . ' · П3/2/склад / Электрооборудование', false)
            ->assertSee('Начисления: 2', false)
            ->assertSee('Начисления: 1', false);
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
            ->assertSee("opts.cache = opts.cache || 'no-store';", false)
            ->assertSee("const pendingCount = getPendingReviewNavCount();", false)
            ->assertSee("reviewNavStatus.textContent = 'Непройденных мест не осталось';", false);
    }
}
