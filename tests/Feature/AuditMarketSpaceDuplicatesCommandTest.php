<?php
# tests/Feature/AuditMarketSpaceDuplicatesCommandTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditMarketSpaceDuplicatesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_runs_and_does_not_mutate_data(): void
    {
        $market = Market::create(['name' => 'Audit Market']);

        $tenantA = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant A',
            'is_active' => true,
        ]);

        $tenantB = Tenant::create([
            'market_id' => $market->id,
            'name' => 'Tenant B',
            'is_active' => true,
        ]);

        $spaceA = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantA->id,
            'number' => 'OS1 13',
            'code' => 'os1-13-a',
            'display_name' => 'OS1 13 A',
            'status' => 'occupied',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_token' => 'OS1',
            'space_group_slot' => '13',
            'is_active' => true,
        ]);

        $spaceB = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenantB->id,
            'number' => 'OS1 13',
            'code' => 'os1-13-b',
            'display_name' => 'OS1 13 B',
            'status' => 'vacant',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_token' => 'OS1',
            'space_group_slot' => '5,13',
            'is_active' => true,
        ]);

        MarketSpaceMapShape::create([
            'market_id' => $market->id,
            'market_space_id' => $spaceA->id,
            'page' => 1,
            'version' => 1,
            'polygon' => [
                ['x' => 0, 'y' => 0],
                ['x' => 10, 'y' => 0],
                ['x' => 10, 'y' => 10],
            ],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenantA->id,
            'market_space_id' => $spaceA->id,
            'tenant_contract_id' => null,
            'period' => '2025-10-01',
            'source_place_code' => 'ОС№1 13',
            'source_place_name' => 'Остров',
            'activity_type' => 'food',
            'currency' => 'RUB',
            'rent_amount' => 1000,
            'management_fee' => 500,
            'utilities_amount' => 0,
            'electricity_amount' => 0,
            'total_no_vat' => 1500,
            'total_with_vat' => 1500,
            'status' => 'imported',
            'source' => 'excel',
            'source_file' => 'audit.csv',
            'source_row_number' => 1,
            'source_row_hash' => hash('sha256', 'audit-row-1'),
            'payload' => json_encode(['test' => true], JSON_UNESCAPED_UNICODE),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $beforeCounts = [
            'market_spaces' => DB::table('market_spaces')->count(),
            'market_space_map_shapes' => DB::table('market_space_map_shapes')->count(),
            'tenant_accruals' => DB::table('tenant_accruals')->count(),
            'tenant_contracts' => DB::table('tenant_contracts')->count(),
            'operations' => DB::table('operations')->count(),
        ];

        $this->artisan('ops:audit-market-space-duplicates', [
            'spaceA' => $spaceA->id,
            'spaceB' => $spaceB->id,
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('[environment]')
            ->expectsOutputToContain('"app_env"')
            ->expectsOutputToContain('[market_spaces_summary]')
            ->expectsOutputToContain('[merge_risk_summary]')
            ->expectsOutputToContain('[recommendation]')
            ->expectsOutputToContain('"canonical_candidate"');

        $afterCounts = [
            'market_spaces' => DB::table('market_spaces')->count(),
            'market_space_map_shapes' => DB::table('market_space_map_shapes')->count(),
            'tenant_accruals' => DB::table('tenant_accruals')->count(),
            'tenant_contracts' => DB::table('tenant_contracts')->count(),
            'operations' => DB::table('operations')->count(),
        ];

        $this->assertSame($beforeCounts, $afterCounts);
    }
}
