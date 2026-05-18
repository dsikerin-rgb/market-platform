<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Filament\Pages\MapReviewResults;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantAccrual;
use App\Models\TenantContract;
use App\Models\User;
use App\Services\MarketMap\MapReviewResultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MapReviewContractOverrideTest extends TestCase
{
    use RefreshDatabase;

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Review Test Market',
            'slug' => 'review-test-market',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);
    }

    private function actingAsSuperAdmin(int $marketId): User
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
        return $this->withHeaders([
            'X-CSRF-TOKEN' => csrf_token(),
        ]);
    }

    public function test_review_results_service_marks_current_place_confirmed_by_direct_contract_with_effective_date(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Баходурзода Сорбон ИП',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'НИЁЗОВ РИЗВОНШОХ Баходурович ИП',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'ФК1',
            'display_name' => 'Кафе',
            'code' => 'fk1',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'Ф/К-1 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]);

        TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'market_space_id' => $space->id,
            'period' => '2026-01-01',
            'source_row_hash' => sha1('review-contract-override'),
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);

        $this->assertCount(1, $rows);
        $diagnostics = $rows[0]['diagnostics'] ?? [];

        $this->assertTrue((bool) ($diagnostics['current_place_confirmed_by_contract'] ?? false));
        $this->assertSame((int) $newTenant->id, (int) data_get($diagnostics, 'contract_override.tenant_id'));
        $this->assertSame('2026-05-01', data_get($diagnostics, 'contract_override.starts_at'));
        $this->assertSame('01.05.2026', data_get($diagnostics, 'contract_override.starts_at_label'));
        $this->assertStringNotContainsString('01.05.2026', (string) ($diagnostics['relation_assessment'] ?? ''));
        $this->assertStringContainsString('финансовым хвостом', (string) ($diagnostics['relation_assessment'] ?? ''));
    }

    public function test_map_review_page_shows_contract_confirmation_and_effective_date(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Баходурзода Сорбон ИП',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'НИЁЗОВ РИЗВОНШОХ Баходурович ИП',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'ФК1',
            'display_name' => 'Кафе',
            'code' => 'fk1-page',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'Ф/К-1 от 01.01.2026',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Подтвердить смену арендатора', false)
            ->assertSee('Было', false)
            ->assertSee('Станет', false)
            ->assertSee('Баходурзода Сорбон ИП', false)
            ->assertSee('НИЁЗОВ РИЗВОНШОХ Баходурович ИП', false)
            ->assertDontSee('01.05.2026', false)
            ->assertSee('Ф/К-1 от 01.01.2026', false)
            ->assertSee('Подтвердить смену', false)
            ->assertDontSee('Изменить дату', false);
    }

    public function test_map_review_page_shows_manual_tenant_switch_action_for_tenant_conflict(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Belova A.N.',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'A-18',
            'display_name' => 'Fish counter',
            'code' => 'a-18',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        Operation::query()->create([
            'type' => OperationType::SPACE_REVIEW,
            'entity_type' => MarketSpace::class,
            'entity_id' => $space->id,
            'market_id' => $market->id,
            'status' => 'pending',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'observed_tenant_name' => $newTenant->name,
                'reason' => 'Belova now occupies this place',
            ],
            'created_by' => $reviewer->id,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('data-mrr-manual-tenant-switch-open', false)
            ->assertSee('Сменить арендатора', false);
    }

    public function test_contract_override_takes_priority_over_identity_clarification_action(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'П/13',
            'display_name' => 'Фикспрайс',
            'code' => 'p-13-contract-override',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'П-13 от 01.04.2026',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        Operation::query()->create([
            'type' => OperationType::SPACE_REVIEW,
            'entity_type' => MarketSpace::class,
            'entity_id' => $space->id,
            'market_id' => $market->id,
            'status' => 'pending',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
                'reason' => 'Нужно уточнить название',
            ],
            'created_by' => $reviewer->id,
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('Подтвердить смену арендатора', false)
            ->assertSee('Подтвердить смену', false);
    }

    public function test_review_contract_tenant_switch_endpoint_plans_tenant_switch_from_contract_data(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'П/13',
            'display_name' => 'Фикспрайс',
            'code' => 'p-13-switch',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'П-13 от 01.04.2026',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $contract->id,
            'effective_date' => '2026-04-01',
            'reason' => 'Смена арендатора по договору',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_switch')
            ->assertJsonPath('operation.status', 'applied');

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'status' => 'applied',
        ]);

        $this->assertDatabaseHas('market_spaces', [
            'id' => $space->id,
            'tenant_id' => $newTenant->id,
            'map_review_status' => 'matched',
        ]);

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'applied',
        ]);

        $operation = Operation::query()->findOrFail((int) $response->json('operation.id'));
        $this->assertTrue((bool) data_get($operation->payload, 'review_close_on_effective_at'));

        $reviewOperation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('matched', data_get($reviewOperation->payload, 'decision'));

        $changes = app(MapReviewResultsService::class)->appliedChanges((int) $market->id, 20);
        $appliedReview = collect($changes)
            ->first(fn (array $change): bool => (int) ($change['operation_id'] ?? 0) === (int) $reviewOperation->id);

        $this->assertNotNull($appliedReview);
        $this->assertSame('matched', $appliedReview['decision'] ?? null);
        $this->assertSame('Подтверждено', $appliedReview['decision_label'] ?? null);
    }

    public function test_review_contract_tenant_switch_endpoint_can_terminate_previous_contract(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'New Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'P/21',
            'display_name' => 'Bakery',
            'code' => 'p-21-switch-close',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        $oldContract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'market_space_id' => $space->id,
            'number' => 'OLD-21',
            'status' => 'active',
            'starts_at' => '2025-01-01',
            'is_active' => true,
        ]);

        $newContract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'NEW-21',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $newContract->id,
            'effective_date' => '2026-04-15',
            'close_previous_contract' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true);

        $oldContract->refresh();
        $this->assertSame('terminated', $oldContract->status);
        $this->assertFalse((bool) $oldContract->is_active);
        $this->assertSame('2026-04-01', optional($oldContract->ends_at)->format('Y-m-d'));

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'status' => 'applied',
        ]);

        $reviewOperation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('matched', data_get($reviewOperation->payload, 'decision'));
        $this->assertSame('Смена арендатора подтверждена договором NEW-21', data_get($reviewOperation->payload, 'reason'));

        $changes = app(MapReviewResultsService::class)->appliedChanges((int) $market->id, 20);
        $appliedReview = collect($changes)
            ->first(fn (array $change): bool => (int) ($change['operation_id'] ?? 0) === (int) $reviewOperation->id);

        $this->assertNotNull($appliedReview);
        $this->assertSame('matched', $appliedReview['decision'] ?? null);
        $this->assertNotSame('matched', (string) ($appliedReview['decision_label'] ?? ''));
        $this->assertSame('Смена арендатора подтверждена договором NEW-21', $appliedReview['summary'] ?? null);
    }

    public function test_review_tenant_switch_endpoint_plans_manual_switch_and_can_terminate_previous_contract(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Old Tenant',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'New Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'P/22',
            'display_name' => 'Coffee',
            'code' => 'p-22-manual-switch',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        $oldContract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'market_space_id' => $space->id,
            'number' => 'OLD-22',
            'status' => 'active',
            'starts_at' => '2025-01-01',
            'is_active' => true,
        ]);

        TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'NEW-22',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'effective_date' => '2026-04-15',
            'reason' => 'Confirmed on review card',
            'close_previous_contract' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_switch_manual');

        $this->assertDatabaseHas('market_spaces', [
            'id' => $space->id,
            'tenant_id' => $newTenant->id,
            'map_review_status' => 'matched',
        ]);

        $oldContract->refresh();
        $this->assertSame('terminated', $oldContract->status);
        $this->assertFalse((bool) $oldContract->is_active);
        $this->assertSame('2026-04-01', optional($oldContract->ends_at)->format('Y-m-d'));
    }

    public function test_review_contract_tenant_switch_endpoint_keeps_review_open_for_future_effective_date(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'П/14',
            'display_name' => 'Точка',
            'code' => 'p-14-switch-future',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        $futureDate = now($market->timezone ?? config('app.timezone', 'UTC'))
            ->addMonth()
            ->toDateString();

        $contract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'П-14 от ' . $futureDate,
            'status' => 'active',
            'starts_at' => $futureDate,
            'is_active' => true,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $contract->id,
            'effective_date' => $futureDate,
            'reason' => 'Запланированная смена арендатора',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_switch');

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
            'status' => 'applied',
        ]);

        $operation = Operation::query()->latest('id')->firstOrFail();
        $this->assertTrue((bool) data_get($operation->payload, 'review_close_on_effective_at'));

        $space->refresh();
        // Для future effective_date ревизия остаётся открытой
        $this->assertNotSame('matched', (string) $space->map_review_status);
    }

    public function test_review_contract_tenant_switch_is_idempotent_when_tenant_already_current(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'number' => 'П/15',
            'display_name' => 'Точка',
            'code' => 'p-15-already-current',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'П-15 от 01.05.2026',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $contract->id,
            'effective_date' => '2026-05-01',
            'reason' => 'Подтвердить смену (уже применена)',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_switch_already_current')
            ->assertJsonPath('operation', null);

        $this->assertDatabaseMissing('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
        ]);

        $space->refresh();
        $this->assertSame('matched', (string) $space->map_review_status);
        $this->assertSame((int) $newTenant->id, (int) $space->tenant_id);
    }

    public function test_review_contract_tenant_switch_rejected_when_space_identity_needs_clarification(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'OS11/3',
            'display_name' => 'Осипенко 11/3',
            'code' => 'os113-clarification',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        // Создаём SPACE_REVIEW с decision = space_identity_needs_clarification
        Operation::query()->create([
            'type' => OperationType::SPACE_REVIEW,
            'entity_type' => MarketSpace::class,
            'entity_id' => $space->id,
            'market_id' => $market->id,
            'status' => 'pending',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
                'reason' => 'Найден договор другого арендатора, но точная связь места требует уточнения',
            ],
            'created_by' => $reviewer->id,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'А ОС 11/3 от 01.06.2023',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        // Попытка подтвердить смену арендатора — должна быть отклонена
        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $contract->id,
            'effective_date' => '2026-04-01',
            'reason' => 'Смена арендатора по договору',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'Найден договор другого арендатора, но точная связь места требует уточнения. Сначала разберите место/дубли, затем подтверждайте смену.');

        // Данные не должны измениться
        $this->assertDatabaseMissing('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
        ]);

        $space->refresh();
        $this->assertNotSame('matched', (string) $space->map_review_status);
        $this->assertSame((int) $oldTenant->id, (int) $space->tenant_id);
    }

    public function test_normal_contract_override_still_works_without_identity_clarification(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'NORMAL-OVERRIDE',
            'display_name' => 'Нормальный оверрайд',
            'code' => 'normal-override',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        // НЕТ space_identity_needs_clarification — только contract override
        TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'Нормальный договор от 01.05.2026',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'is_active' => true,
        ]);

        Livewire::test(MapReviewResults::class)
            // Должно показывать кнопку подтверждения
            ->assertSee('Подтвердить смену', false)
            ->assertSee('data-mrr-contract-tenant-switch-apply', false);
    }

    public function test_review_contract_tenant_switch_not_blocked_when_identity_clarification_is_for_different_space(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        // Создаём ДРУГОЕ место с identity clarification
        $otherSpace = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'OTHER-SPACE',
            'display_name' => 'Другое место',
            'code' => 'other-space-clarification',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        Operation::query()->create([
            'type' => OperationType::SPACE_REVIEW,
            'entity_type' => MarketSpace::class,
            'entity_id' => $otherSpace->id,
            'market_id' => $market->id,
            'status' => 'pending',
            'payload' => [
                'market_space_id' => $otherSpace->id,
                'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
                'reason' => 'Нужно уточнить другое место',
            ],
            'created_by' => $reviewer->id,
        ]);

        // ТЕКУЩЕЕ место БЕЗ identity clarification
        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'CURRENT-SPACE',
            'display_name' => 'Текущее место',
            'code' => 'current-space-no-clarification',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'Договор для текущего места',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        // Смена арендатора для ТЕКУЩЕГО места должна пройти успешно
        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $contract->id,
            'effective_date' => '2026-04-01',
            'reason' => 'Смена арендатора по договору',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_switch');

        // Операция должна быть создана
        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
        ]);

        $space->refresh();
        $this->assertSame('matched', (string) $space->map_review_status);
        $this->assertSame((int) $newTenant->id, (int) $space->tenant_id);
    }

    public function test_review_contract_tenant_switch_not_blocked_if_later_space_review_has_different_decision(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'LATER-REVIEW',
            'display_name' => 'Место с более поздней ревизией',
            'code' => 'later-review-space',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'conflict',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        // Сначала создаём SPACE_REVIEW с decision = space_identity_needs_clarification
        Operation::query()->create([
            'type' => OperationType::SPACE_REVIEW,
            'entity_type' => MarketSpace::class,
            'entity_id' => $space->id,
            'market_id' => $market->id,
            'status' => 'pending',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
                'reason' => 'Сначала нужно уточнение',
            ],
            'created_by' => $reviewer->id,
        ]);

        // Затем создаём БОЛЕЕ ПОЗДНЮЮ SPACE_REVIEW с другим decision (tenant_changed_on_site)
        // Это симулирует ситуацию, где пользователь уже принял другое решение
        Operation::query()->create([
            'type' => OperationType::SPACE_REVIEW,
            'entity_type' => MarketSpace::class,
            'entity_id' => $space->id,
            'market_id' => $market->id,
            'status' => 'pending',
            'payload' => [
                'market_space_id' => $space->id,
                'decision' => SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                'reason' => 'Позже пользователь отметил смену арендатора',
                'observed_tenant_name' => 'Новый арендатор',
            ],
            'created_by' => $reviewer->id,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'А ОС 11/3 от 01.06.2023',
            'status' => 'active',
            'starts_at' => '2026-04-01',
            'is_active' => true,
        ]);

        // Смена арендатора должна пройти успешно, потому что ПОСЛЕДНЯЯ операция — tenant_changed_on_site
        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-contract-tenant-switch', [
            'market_space_id' => $space->id,
            'target_tenant_id' => $newTenant->id,
            'contract_id' => $contract->id,
            'effective_date' => '2026-04-01',
            'reason' => 'Смена арендатора по договору',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_switch');

        // Операция должна быть создана
        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::TENANT_SWITCH,
        ]);

        $space->refresh();
        $this->assertSame('matched', (string) $space->map_review_status);
        $this->assertSame((int) $newTenant->id, (int) $space->tenant_id);
    }

    public function test_review_contract_override_keeps_dates_out_of_primary_facts(): void
    {
        $market = $this->createMarket();
        $reviewer = $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $oldTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Старый арендатор',
            'is_active' => true,
        ]);

        $newTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Новый арендатор',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $oldTenant->id,
            'number' => 'SIGNED-AT-TEST',
            'display_name' => 'Тест signed_at',
            'code' => 'signed-at-test',
            'status' => 'occupied',
            'is_active' => true,
            'map_review_status' => 'changed_tenant',
            'map_reviewed_at' => now(),
            'map_reviewed_by' => $reviewer->id,
        ]);

        TenantContract::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $newTenant->id,
            'market_space_id' => $space->id,
            'number' => 'Договор с signed_at',
            'status' => 'active',
            'starts_at' => '2026-05-01',
            'signed_at' => '2023-06-01', // <-- Вот она, дата подписания
            'is_active' => true,
        ]);

        // 1. Проверка на уровне сервиса
        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $this->assertCount(1, $rows);
        $diagnostics = $rows[0]['diagnostics'] ?? [];
        $contractOverride = data_get($diagnostics, 'contract_override');

        $this->assertNotNull($contractOverride);
        $this->assertSame('2026-05-01', $contractOverride['starts_at']);
        $this->assertSame('01.05.2026', $contractOverride['starts_at_label']);
        $this->assertSame('2023-06-01', $contractOverride['signed_at']);
        $this->assertSame('01.06.2023', $contractOverride['signed_at_label']);

        // 2. Проверка на уровне отображения
        Livewire::test(MapReviewResults::class)
            ->assertSee('Подтвердить смену арендатора', false)
            ->assertSee('Старый арендатор', false)
            ->assertSee('Новый арендатор', false)
            ->assertSee('Договор: Договор с signed_at', false)
            ->assertDontSee('Дата из 1С starts_at: 01.05.2026', false)
            ->assertDontSee('Дата договора: 01.06.2023', false)
            ->assertDontSee('С даты: 01.05.2026', false);
    }
}
