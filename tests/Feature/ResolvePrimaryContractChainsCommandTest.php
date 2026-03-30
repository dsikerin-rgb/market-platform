<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use App\Models\TenantContract;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolvePrimaryContractChainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_reports_only_auto_resolvable_groups(): void
    {
        $market = Market::create(['name' => 'Test market']);
        $tenant = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant']);
        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P-1',
            'status' => 'occupied',
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'П/1 от 01.05.2024',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'А П/1 от 01.03.2023',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $this->artisan('contracts:resolve-primary-chains', ['--market' => $market->id])
            ->assertSuccessful()
            ->expectsOutputToContain('"mode": "preview"')
            ->expectsOutputToContain('"total_groups": 1')
            ->expectsOutputToContain('"historical_contract_ids"');
    }

    public function test_apply_excludes_historical_primary_contracts_and_keeps_candidate_binding_active(): void
    {
        Carbon::setTestNow('2025-03-20 15:30:00');

        $market = Market::create(['name' => 'Test market']);
        $tenant = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant']);
        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P-1',
            'status' => 'occupied',
        ]);

        $historical = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'А П/1 от 01.03.2023',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $candidate = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'П/1 от 01.05.2024',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'tenant_contract_id' => $historical->id,
            'ended_at' => null,
        ]);
        $this->assertDatabaseHas('market_space_tenant_bindings', [
            'tenant_contract_id' => $candidate->id,
            'ended_at' => null,
        ]);

        $this->artisan('contracts:resolve-primary-chains', [
            '--market' => $market->id,
            '--apply' => true,
        ])->assertSuccessful();

        $historical->refresh();
        $candidate->refresh();

        $this->assertSame(TenantContract::SPACE_MAPPING_MODE_EXCLUDED, $historical->space_mapping_mode);
        $this->assertSame('auto', $candidate->effectiveSpaceMappingMode());

        $historicalBinding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $historical->id)
            ->latest('id')
            ->first();

        $candidateBinding = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $candidate->id)
            ->whereNull('ended_at')
            ->first();

        $this->assertNotNull($historicalBinding?->ended_at);
        $this->assertSame('contract_excluded_from_mapping', $historicalBinding->resolution_reason);
        $this->assertNotNull($candidateBinding);
    }

    public function test_apply_skips_ambiguous_groups(): void
    {
        $market = Market::create(['name' => 'Test market']);
        $tenant = Tenant::create(['market_id' => $market->id, 'name' => 'Tenant']);
        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P-1',
            'status' => 'occupied',
        ]);

        $first = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'П/1 от 01.05.2024',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $second = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'П/1 от 01.05.2024',
            'status' => 'active',
            'starts_at' => '2025-03-01',
            'is_active' => true,
        ]);

        $this->artisan('contracts:resolve-primary-chains', [
            '--market' => $market->id,
            '--apply' => true,
        ])->assertSuccessful();

        $first->refresh();
        $second->refresh();

        $this->assertSame('auto', $first->effectiveSpaceMappingMode());
        $this->assertSame('auto', $second->effectiveSpaceMappingMode());
    }
}
