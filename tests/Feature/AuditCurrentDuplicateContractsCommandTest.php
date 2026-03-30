<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditCurrentDuplicateContractsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_current_duplicates_groups_by_place_token_and_document_date(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant LLC',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P/75',
            'status' => 'occupied',
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'uuid-a',
            'number' => "P/75 \u{043E}\u{0442} 01.04.2025",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'uuid-b',
            'number' => "P/75 \u{043E}\u{0442} 01.04.2025",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'uuid-old',
            'number' => "P/75 \u{043E}\u{0442} 01.05.2024",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        $this->artisan('contracts:audit-current-duplicates', [
            '--market' => $market->id,
            '--limit' => 10,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"duplicate_groups": 1')
            ->expectsOutputToContain('"groups_with_mixed_external_ids": 1')
            ->expectsOutputToContain('"document_date": "2025-04-01"')
            ->expectsOutputToContain('"external_ids": [')
            ->expectsOutputToContain('"uuid-a"')
            ->expectsOutputToContain('"uuid-b"');
    }

    public function test_audit_current_duplicates_counts_missing_external_id(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant LLC',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'P/71-72',
            'status' => 'occupied',
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => null,
            'number' => "P/71-72 \u{043E}\u{0442} 01.08.2025",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        TenantContract::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'external_id' => 'uuid-c',
            'number' => "P/71 - 72 \u{043E}\u{0442} 01.08.2025",
            'status' => 'active',
            'starts_at' => '2026-03-01',
            'is_active' => true,
        ]);

        $this->artisan('contracts:audit-current-duplicates', [
            '--market' => $market->id,
            '--limit' => 10,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('"duplicate_groups": 1')
            ->expectsOutputToContain('"groups_with_missing_external_ids": 1')
            ->expectsOutputToContain('"missing_external_id_count": 1');
    }
}
