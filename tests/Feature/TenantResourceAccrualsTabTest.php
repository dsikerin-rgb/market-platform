<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TenantResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantResourceAccrualsTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_accruals_tab_shows_contract_details_and_missing_space_warning_for_one_c_only(): void
    {
        $fixture = $this->createFixture(includeMissingOneC: true, includeExcelMissing: true);

        $this->actingAs($fixture['user']);

        $response = $this->get(TenantResource::getUrl('edit', ['record' => $fixture['tenant']]));

        $response
            ->assertOk()
            ->assertSeeText('Открыть полный отчёт по начислениям')
            ->assertSee('tenantId=' . $tenant->id, false)
            ->assertSeeText('АД/ДДА/БНЛ/2380 от 29.04.2025')
            ->assertSeeText('Полный документ: АД/ДДА/БНЛ/2380 от 29.04.2025')
            ->assertSeeText('Статус: active')
            ->assertSeeText('Дата начала: 29.04.2025')
            ->assertSeeText('Дата окончания: 29.04.2026')
            ->assertSeeText('Старый базар')
            ->assertSeeText('Вывеска: Самокат')
            ->assertDontSeeText('Excel/CSV не участвуют')
            ->assertDontSeeText('excel-missing-space.csv')
            ->assertSeeText('Без места по договору')
            ->assertDontSeeText('Без места по договору: 2');
        $this->assertContractCellUsesDetails($response->getContent(), 'Полный документ: АД/ДДА/БНЛ/2380 от 29.04.2025');

        self::assertSame(2, $this->countTenantAccrualRows($fixture['tenant'], '1c'));
        self::assertSame(1, $this->countTenantAccrualRows($fixture['tenant'], 'excel'));
        self::assertSame(1, $this->countMissingSpaceRows($fixture['tenant'], '1c'));
        self::assertSame(2, $this->countMissingSpaceRows($fixture['tenant']));
        self::assertSame(
            (int) $fixture['contract']->id,
            (int) DB::table('tenant_accruals')
                ->where('market_id', (int) $fixture['market']->id)
                ->where('tenant_id', (int) $fixture['tenant']->id)
                ->where('source', '1c')
                ->value('tenant_contract_id')
        );
    }

    public function test_accruals_tab_hides_missing_space_card_when_contract_has_space(): void
    {
        $fixture = $this->createFixture(includeMissingOneC: false, includeExcelMissing: false);

        $this->actingAs($fixture['user']);

        $response = $this->get(TenantResource::getUrl('edit', ['record' => $fixture['tenant']]));

        $response
            ->assertOk()
            ->assertSeeText('АД/ДДА/БНЛ/2380')
            ->assertSeeText('Полный документ: АД/ДДА/БНЛ/2380 от 29.04.2025')
            ->assertSeeText('Старый базар')
            ->assertSeeText('Вывеска: Самокат')
            ->assertDontSeeText('Excel/CSV не участвуют')
            ->assertDontSeeText('Без места по договору')
            ->assertDontSeeText('Без места по договору (последний период)');
        $this->assertContractCellUsesDetails($response->getContent(), 'Полный документ: АД/ДДА/БНЛ/2380 от 29.04.2025');

        self::assertSame(1, $this->countTenantAccrualRows($fixture['tenant'], '1c'));
        self::assertSame(0, $this->countMissingSpaceRows($fixture['tenant'], '1c'));
        self::assertSame(0, $this->countMissingSpaceRows($fixture['tenant']));
    }

    private function createFixture(bool $includeMissingOneC, bool $includeExcelMissing): array
    {
        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $tenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Tenant',
            'is_active' => true,
        ]);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'number' => 'Старый базар',
            'code' => 'SB-1',
            'display_name' => 'Самокат',
            'is_active' => true,
        ]);

        $contract = TenantContract::query()->create([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'market_space_id' => (int) $space->id,
            'external_id' => 'contract-101',
            'number' => 'АД/ДДА/БНЛ/2380 от 29.04.2025',
            'status' => 'active',
            'starts_at' => '2025-04-29',
            'ends_at' => '2026-04-29',
            'is_active' => true,
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => (int) $market->id,
            'tenant_id' => (int) $tenant->id,
            'tenant_contract_id' => (int) $contract->id,
            'market_space_id' => null,
            'period' => '2026-03-01',
            'source' => '1c',
            'status' => 'imported',
            'currency' => 'RUB',
            'total_with_vat' => 1500,
            'source_row_hash' => hash('sha256', '1c-row-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($includeMissingOneC) {
            DB::table('tenant_accruals')->insert([
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_contract_id' => null,
                'market_space_id' => null,
                'period' => '2026-03-01',
                'source' => '1c',
                'status' => 'imported',
                'currency' => 'RUB',
                'total_with_vat' => 500,
                'source_file' => 'onec-missing-space.csv',
                'source_row_hash' => hash('sha256', '1c-row-2'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($includeExcelMissing) {
            DB::table('tenant_accruals')->insert([
                'market_id' => (int) $market->id,
                'tenant_id' => (int) $tenant->id,
                'tenant_contract_id' => null,
                'market_space_id' => null,
                'period' => '2026-03-01',
                'source' => 'excel',
                'status' => 'imported',
                'currency' => 'RUB',
                'total_with_vat' => 9999,
                'source_file' => 'excel-missing-space.csv',
                'source_row_hash' => hash('sha256', 'excel-row-1'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Role::findOrCreate('market-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'market-admin-tenant-accruals@example.test',
        ]);
        $user->assignRole('market-admin');

        return compact('market', 'tenant', 'space', 'contract', 'user');
    }

    private function countTenantAccrualRows(Tenant $tenant, string $source): int
    {
        return DB::table('tenant_accruals')
            ->where('market_id', (int) $tenant->market_id)
            ->where('tenant_id', (int) $tenant->id)
            ->where('source', $source)
            ->count();
    }

    private function countMissingSpaceRows(Tenant $tenant, ?string $source = null): int
    {
        $query = DB::table('tenant_accruals as ta')
            ->leftJoin('tenant_contracts as tc', 'tc.id', '=', 'ta.tenant_contract_id')
            ->where('ta.market_id', (int) $tenant->market_id)
            ->where('ta.tenant_id', (int) $tenant->id)
            ->whereNull('ta.market_space_id')
            ->whereNull('tc.market_space_id');

        if ($source !== null) {
            $query->where('ta.source', $source);
        }

        return $query->count();
    }

    private function assertContractCellUsesDetails(string $html, string $marker): void
    {
        $position = strpos($html, $marker);
        self::assertIsInt($position);

        $snippet = substr($html, max(0, $position - 250), 800);

        self::assertStringContainsString('<details class="tenant-accruals__contract-details">', $html);
        self::assertStringContainsString('tenant-accruals__contract-summary', $html);
        self::assertStringContainsString('tenant-accruals__contract-summary--wrap', $html);
        self::assertStringContainsString('АД/ДДА/БНЛ/2380 от 29.04.2025', $snippet);
        self::assertStringNotContainsString('#tenant-contract-', $snippet);
    }
}
