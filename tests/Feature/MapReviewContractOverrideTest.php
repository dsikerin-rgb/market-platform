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

        $accrual = TenantAccrual::query()->create([
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

    public function test_review_results_service_adds_financial_signal_when_accrual_has_different_tenant_without_contract(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Samkolbas LLC',
            'is_active' => true,
        ]);

        $financialTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => '1c-detyateva',
            'name' => 'Detyateva O.S. IP',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/2',
            'display_name' => 'Odex',
            'code' => 'p56-2',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $financialTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_file' => 'accruals-1c.csv',
            'source_row_hash' => sha1('financial-signal-different-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'contract_link_note' => 'No imported contract matched this accrual.',
            'imported_at' => '2026-05-18 10:00:00',
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);

        $this->assertCount(1, $rows);
        $this->assertSame((int) $space->id, (int) $rows[0]['space_id']);
        $this->assertSame('conflict', $rows[0]['review_status']);
        $this->assertSame(SpaceReviewDecision::TENANT_CHANGED_ON_SITE, $rows[0]['decision']);
        $this->assertSame('Detyateva O.S. IP', data_get($rows[0], 'diagnostics.financial_signal.tenant_name'));
        $this->assertSame('Samkolbas LLC', data_get($rows[0], 'diagnostics.financial_signal.current_tenant_name'));
        $this->assertSame('05.2026', data_get($rows[0], 'diagnostics.financial_signal.latest_period_label'));
        $this->assertSame(TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED, data_get($rows[0], 'diagnostics.financial_signal.contract_link_status'));
        $this->assertSame('Detyateva O.S. IP', data_get($rows[0], 'tenant_change_details.observed_tenant_name'));
        $this->assertStringContainsString('Detyateva O.S. IP', (string) $rows[0]['reason']);
        $this->assertStringContainsString('Samkolbas LLC', (string) $rows[0]['reason']);
    }

    public function test_review_results_service_does_not_add_financial_signal_when_accrual_tenant_matches_space(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);

        $tenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Detyateva O.S. IP',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P56/2',
            'display_name' => 'Odex',
            'code' => 'p56-2-current',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source_row_hash' => sha1('financial-signal-same-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);

        $this->assertSame([], $rows);
    }

    public function test_map_review_page_shows_financial_signal_card(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Samkolbas LLC',
            'is_active' => true,
        ]);

        $financialTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => '1c-detyateva-page',
            'name' => 'Detyateva O.S. IP',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/2',
            'display_name' => 'Odex',
            'code' => 'p56-2-page',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $financialTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_file' => 'accruals-1c.csv',
            'source_row_hash' => sha1('financial-signal-page'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'imported_at' => '2026-05-18 10:00:00',
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('P56/2', false)
            ->assertSee('Odex', false)
            ->assertSee('Detyateva O.S. IP', false)
            ->assertSee('Samkolbas LLC', false)
            ->assertSee('05.2026', false)
            ->assertSee('unmatched', false)
            ->assertSee('accruals-1c.csv', false);
    }

    public function test_review_results_service_marks_financial_signal_for_tenant_resolution_when_accrual_tenant_is_inactive(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Samkolbas LLC',
            'is_active' => true,
        ]);

        $inactiveTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => '1c-inactive-detyateva',
            'name' => 'Detyateva O.S. IP',
            'inn' => '5400000001',
            'is_active' => false,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/2',
            'display_name' => 'Odex',
            'code' => 'p56-2-resolution',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $inactiveTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_file' => 'accruals-1c.csv',
            'source_row_hash' => sha1('financial-signal-inactive-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_external_id' => '1c-inactive-detyateva',
                'tenant_name' => 'Detyateva O.S. IP',
                'inn' => '5400000001',
                'kpp' => '540001001',
            ], JSON_UNESCAPED_UNICODE),
            'imported_at' => '2026-05-18 10:00:00',
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);

        $this->assertTrue((bool) data_get($rows[0], 'diagnostics.financial_signal.requires_tenant_resolution'));
        $this->assertSame('1c-inactive-detyateva', data_get($rows[0], 'diagnostics.financial_signal.tenant_external_id'));
        $this->assertSame('5400000001', data_get($rows[0], 'diagnostics.financial_signal.tenant_inn'));
        $this->assertSame('540001001', data_get($rows[0], 'diagnostics.financial_signal.tenant_kpp'));
    }

    public function test_map_review_page_shows_financial_tenant_resolution_action(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Samkolbas LLC',
            'is_active' => true,
        ]);

        $inactiveTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => '1c-review-only',
            'name' => 'Detyateva O.S. IP',
            'inn' => '5400000002',
            'is_active' => false,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/3',
            'display_name' => 'Odex',
            'code' => 'p56-3-resolution',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $inactiveTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_file' => 'accruals-1c.csv',
            'source_row_hash' => sha1('financial-signal-resolution-page'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_external_id' => '1c-review-only',
                'tenant_name' => 'Detyateva O.S. IP',
                'inn' => '5400000002',
            ], JSON_UNESCAPED_UNICODE),
            'imported_at' => '2026-05-18 10:00:00',
        ]);

        Livewire::test(MapReviewResults::class)
            ->assertSee('data-mrr-accrual-id="' . $accrual->id . '"', false);
    }

    public function test_review_resolve_financial_tenant_endpoint_matches_existing_tenant_by_inn_and_activates_it(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $matchedTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Detyateva O.S. IP',
            'inn' => '5400000010',
            'is_active' => false,
        ]);

        $placeholderTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => 'placeholder-detyateva',
            'name' => 'Imported placeholder',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/4',
            'display_name' => 'Odex',
            'code' => 'p56-4-resolution',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $placeholderTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_row_hash' => sha1('financial-signal-match-by-inn'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_external_id' => '550e8400-e29b-41d4-a716-446655440000',
                'tenant_name' => 'Detyateva O.S. IP',
                'inn' => '5400000010',
                'kpp' => '540001010',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-resolve-financial-tenant', [
            'market_space_id' => $space->id,
            'accrual_id' => $accrual->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_resolved_existing')
            ->assertJsonPath('tenant.id', (int) $matchedTenant->id);

        $matchedTenant->refresh();
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $matchedTenant->external_id);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $matchedTenant->one_c_uid);
        $this->assertTrue((bool) $matchedTenant->is_active);
        $this->assertSame('540001010', $matchedTenant->kpp);

        $accrual->refresh();
        $this->assertSame((int) $matchedTenant->id, (int) $accrual->tenant_id);
    }

    public function test_review_resolve_financial_tenant_endpoint_activates_existing_tenant_and_shows_manual_switch(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $inactiveTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => '1c-inactive-detyateva',
            'name' => 'Detyateva O.S. IP',
            'inn' => '5400000010',
            'is_active' => false,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/6',
            'display_name' => 'Odex',
            'code' => 'p56-6-resolution',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $inactiveTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_row_hash' => sha1('financial-signal-activate-existing-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_external_id' => '1c-inactive-detyateva',
                'tenant_name' => 'Detyateva O.S. IP',
                'inn' => '5400000010',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-resolve-financial-tenant', [
            'market_space_id' => $space->id,
            'accrual_id' => $accrual->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_activated_existing')
            ->assertJsonPath('tenant.id', (int) $inactiveTenant->id);

        $inactiveTenant->refresh();
        $this->assertTrue((bool) $inactiveTenant->is_active);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $row = array_values($rows)[0] ?? [];

        $this->assertCount(1, $rows);
        $this->assertFalse((bool) data_get($row, 'diagnostics.financial_signal.requires_tenant_resolution'));
        $this->assertSame((int) $inactiveTenant->id, (int) ($row['suggested_target_tenant_id'] ?? 0));
        $this->assertSame('Detyateva O.S. IP', $row['suggested_target_tenant_name'] ?? '');

        Livewire::test(MapReviewResults::class)
            ->assertSee(' data-mrr-manual-tenant-switch-open', false)
            ->assertSee('data-mrr-suggested-tenant-id="' . $inactiveTenant->id . '"', false)
            ->assertSee('data-mrr-suggested-tenant-name="Detyateva O.S. IP"', false)
            ->assertSee('Сменить арендатора', false)
            ->assertDontSee(' data-mrr-financial-tenant-resolve-open', false)
            ->assertDontSee('Активировать арендатора', false);
    }

    public function test_review_resolve_financial_tenant_matches_existing_current_tenant_instead_of_creating_duplicate(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $matchedTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Ряднова Тамара Анатольевна',
            'is_active' => true,
        ]);

        $placeholderTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => 'placeholder-ryadnova-ip',
            'name' => 'Ряднова ИП (placeholder)',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $matchedTenant->id,
            'number' => 'ОС3',
            'display_name' => 'Остров',
            'code' => 'os3-ryadnova',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $placeholderTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2025-10-01',
            'source' => 'excel',
            'source_file' => '2025-10__import.csv',
            'source_row_hash' => sha1('financial-signal-match-existing-current-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_name' => 'Ряднова ИП',
                'space_number' => 'ОС3',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $row = array_values($rows)[0] ?? [];

        $this->assertSame('match_existing_tenant', data_get($row, 'diagnostics.financial_signal.resolution_action'));
        $this->assertSame((int) $matchedTenant->id, (int) data_get($row, 'diagnostics.financial_signal.existing_tenant_candidate_id'));
        $this->assertSame('Ряднова Тамара Анатольевна', data_get($row, 'diagnostics.financial_signal.existing_tenant_candidate_name'));

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-resolve-financial-tenant', [
            'market_space_id' => $space->id,
            'accrual_id' => $accrual->id,
            'preferred_tenant_id' => $matchedTenant->id,
            'tenant_name' => 'Ряднова ИП',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_resolved_existing')
            ->assertJsonPath('tenant.id', (int) $matchedTenant->id);

        $this->assertSame((int) $matchedTenant->id, (int) $accrual->refresh()->tenant_id);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);
        $this->assertSame([], $rows);
    }

    public function test_review_resolve_financial_tenant_endpoint_creates_new_tenant_and_then_allows_manual_switch(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $placeholderTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => 'placeholder-financial-create',
            'name' => 'Placeholder tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/5',
            'display_name' => 'Odex',
            'code' => 'p56-5-resolution',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $placeholderTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_row_hash' => sha1('financial-signal-create-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_external_id' => '1c-created-detyateva',
                'tenant_name' => 'Detyateva O.S. IP',
                'inn' => '5400000020',
                'kpp' => '540001020',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-resolve-financial-tenant', [
            'market_space_id' => $space->id,
            'accrual_id' => $accrual->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_created');

        $createdTenant = Tenant::query()
            ->where('market_id', $market->id)
            ->where('external_id', '1c-created-detyateva')
            ->firstOrFail();

        $this->assertTrue((bool) $createdTenant->is_active);
        $this->assertSame('Detyateva O.S. IP', $createdTenant->name);

        $accrual->refresh();
        $this->assertSame((int) $createdTenant->id, (int) $accrual->tenant_id);

        Livewire::test(MapReviewResults::class)
            ->assertSee('data-mrr-suggested-tenant-id="' . $createdTenant->id . '"', false);
    }

    public function test_review_resolve_financial_tenant_endpoint_returns_controlled_failure_without_identifiers(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $placeholderTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => 'placeholder-financial-fail',
            'name' => 'Placeholder tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P56/6',
            'display_name' => 'Odex',
            'code' => 'p56-6-resolution',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $placeholderTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-05-01',
            'source' => '1c',
            'source_row_hash' => sha1('financial-signal-fail-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_name' => 'Detyateva O.S. IP',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-resolve-financial-tenant', [
            'market_space_id' => $space->id,
            'accrual_id' => $accrual->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('mode', 'tenant_resolve_failed');
    }

    public function test_financial_signal_for_excel_inactive_existing_tenant_activates_without_trusting_test_external_id(): void
    {
        $market = $this->createMarket();
        $this->actingAsSuperAdmin((int) $market->id);
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);

        $currentTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Current Tenant',
            'is_active' => true,
        ]);

        $inactiveTenant = Tenant::query()->create([
            'market_id' => $market->id,
            'external_id' => 'TEST_33',
            'name' => 'Detyateva O.S. IP',
            'is_active' => false,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $currentTenant->id,
            'number' => 'P 56/2',
            'display_name' => 'Odex',
            'code' => 'p56-2-excel-existing-tenant',
            'status' => 'occupied',
            'is_active' => true,
        ]);

        $accrual = TenantAccrual::query()->create([
            'market_id' => $market->id,
            'tenant_id' => $inactiveTenant->id,
            'market_space_id' => $space->id,
            'tenant_contract_id' => null,
            'period' => '2026-01-01',
            'source' => 'excel',
            'source_file' => '2026-01__import.csv',
            'source_row_hash' => sha1('financial-signal-excel-inactive-existing-tenant'),
            'contract_link_status' => TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
            'payload' => json_encode([
                'tenant_name' => 'Detyateva O.S. IP',
                'space_number' => 'P 56/2',
                'space_name' => 'Odex',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $rows = app(MapReviewResultsService::class)->needsAttention((int) $market->id, 10);

        $this->assertTrue((bool) data_get($rows[0], 'diagnostics.financial_signal.requires_tenant_resolution'));
        $this->assertSame('activate_existing_tenant', data_get($rows[0], 'diagnostics.financial_signal.resolution_action'));
        $this->assertNull(data_get($rows[0], 'diagnostics.financial_signal.tenant_external_id'));

        $response = $this->withCsrfToken()->postJson('/admin/market-map/review-resolve-financial-tenant', [
            'market_space_id' => $space->id,
            'accrual_id' => $accrual->id,
            'tenant_external_id' => '',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'tenant_activated_existing')
            ->assertJsonPath('tenant.id', (int) $inactiveTenant->id)
            ->assertJsonPath('accruals_updated', 0);

        $inactiveTenant->refresh();

        $this->assertTrue((bool) $inactiveTenant->is_active);
        $this->assertSame((int) $inactiveTenant->id, (int) $accrual->refresh()->tenant_id);
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
