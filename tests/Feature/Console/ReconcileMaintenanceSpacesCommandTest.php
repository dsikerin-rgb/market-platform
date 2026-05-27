<?php
# tests/Feature/Console/ReconcileMaintenanceSpacesCommandTest.php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileMaintenanceSpacesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_mutate_maintenance_space_anomalies(): void
    {
        [$space, $binding, $tenant] = $this->createMaintenanceSpaceWithBinding();

        $this->artisan('market:reconcile-maintenance-spaces --market=' . $space->market_id)
            ->assertExitCode(0);

        $space->refresh();
        $binding->refresh();

        $this->assertSame((int) $tenant->id, (int) $space->tenant_id);
        $this->assertNull($binding->ended_at);
        $this->assertSame('tenant_contract_manual', $binding->source);
        $this->assertSame('manual_override', $binding->resolution_reason);
    }

    public function test_apply_clears_tenant_id_and_closes_active_bindings_for_maintenance_spaces(): void
    {
        [$space, $binding] = $this->createMaintenanceSpaceWithBinding();

        $this->artisan('market:reconcile-maintenance-spaces --market=' . $space->market_id . ' --apply')
            ->assertExitCode(0);

        $space->refresh();
        $binding->refresh();

        $this->assertNull($space->tenant_id);
        $this->assertNotNull($binding->ended_at);
        $this->assertSame('maintenance_space_reconciled', $binding->resolution_reason);
    }

    /**
     * @return array{0: MarketSpace, 1: MarketSpaceTenantBinding, 2: Tenant}
     */
    private function createMaintenanceSpaceWithBinding(): array
    {
        $market = Market::create([
            'name' => 'Test Market',
            'slug' => 'test-maintenance-reconcile',
            'timezone' => 'Asia/Novosibirsk',
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'short_name' => 'Test Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'SVC-201',
            'display_name' => 'Service space',
            'status' => 'maintenance',
            'is_active' => true,
        ]);

        $binding = MarketSpaceTenantBinding::create([
            'market_id' => $market->id,
            'market_space_id' => $space->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => now()->subDay(),
            'ended_at' => null,
            'binding_type' => 'manual',
            'confidence' => 'high',
            'source' => 'tenant_contract_manual',
            'resolution_reason' => 'manual_override',
        ]);

        return [$space, $binding, $tenant];
    }
}
