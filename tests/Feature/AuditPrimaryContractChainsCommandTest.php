<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditPrimaryContractChainsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_primary_chains_picks_candidate_by_document_date_and_ignores_service_docs(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant LLC',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P/53',
            'status' => 'occupied',
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => "\u{0414}\u{043E}\u{0433}\u{043E}\u{0432}\u{043E}\u{0440} \u{0430}\u{0440}\u{0435}\u{043D}\u{0434}\u{044B} \u{2116} P/53 \u{043E}\u{0442} 01.06.2020",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        $expected = TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => "P/53 \u{043E}\u{0442} 01.05.2024",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => "\u{041E}\u{041F} P/53 \u{043E}\u{0442} 01.05.2024",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        $this->artisan('contracts:audit-primary-chains', [
            '--market' => $market->id,
            '--limit' => 10,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"group_count": 1')
            ->expectsOutputToContain('"candidate_contract_id": '.$expected->id)
            ->expectsOutputToContain('"candidate_document_date": "2024-05-01"');
    }

    public function test_audit_primary_chains_excludes_synthetic_contract_numbers_by_default(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant LLC',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P/1',
            'status' => 'occupied',
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => 'TEST-001',
            'status' => 'active',
            'starts_at' => '2026-02-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'number' => "P/1 \u{043E}\u{0442} 01.03.2024",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        $this->artisan('contracts:audit-primary-chains', [
            '--market' => $market->id,
            '--limit' => 10,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"group_count": 0');

        $this->artisan('contracts:audit-primary-chains', [
            '--market' => $market->id,
            '--limit' => 10,
            '--include-test' => true,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"group_count": 1')
            ->expectsOutputToContain('"has_test_noise": true');
    }
}
