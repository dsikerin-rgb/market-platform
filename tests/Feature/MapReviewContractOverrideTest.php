<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\MapReviewResults;
use App\Models\Market;
use App\Models\MarketSpace;
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
            ->assertSee('Текущее место подтверждено договором', false)
            ->assertSee('01.05.2026', false)
            ->assertSee('финансовым хвостом', false);
    }
}
