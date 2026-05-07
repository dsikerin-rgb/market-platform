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
        $this->assertStringContainsString('01.05.2026', (string) ($diagnostics['relation_assessment'] ?? ''));
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
            ->assertSee('01.05.2026', false)
            ->assertSee('Ф/К-1 от 01.01.2026', false)
            ->assertSee('Подтвердить смену', false)
            ->assertDontSee('Изменить дату', false);
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

        $operation = Operation::query()->findOrFail((int) $response->json('operation.id'));
        $this->assertTrue((bool) data_get($operation->payload, 'review_close_on_effective_at'));
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
            ->addDay()
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
        $this->assertSame('conflict', (string) $space->map_review_status);
    }
}
