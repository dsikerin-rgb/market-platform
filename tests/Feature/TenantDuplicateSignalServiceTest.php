<?php
# tests/Feature/TenantDuplicateSignalServiceTest.php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Services\Tenants\TenantDuplicateSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TenantDuplicateSignalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_flags_short_1c_name_as_possible_duplicate_without_writing_data(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачёв Евгений Сергеевич ИП',
            'external_id' => 'b53540e7-b637-11ef-bab4-047c16b428be',
            'inn' => '222405692915',
            'is_active' => true,
        ]);

        Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачев ИП',
            'external_id' => 'TEST_206',
            'is_active' => true,
        ]);

        $before = Tenant::query()->count();

        $signals = app(TenantDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertCount(1, $signals);
        $this->assertSame('tenant_identity_resolution', $signals[0]['type']);
        $this->assertSame('Возможный дубль арендатора', $signals[0]['title']);
        $this->assertContains('Короткое название похоже на сокращение полного имени', $signals[0]['reasons']);
        $this->assertSame($before, Tenant::query()->count());
    }

    public function test_it_flags_existing_alias_pair(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        $canonical = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачёв Евгений Сергеевич ИП',
            'external_id' => 'b53540e7-b637-11ef-bab4-047c16b428be',
            'inn' => '222405692915',
            'is_active' => true,
        ]);

        Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачев ИП',
            'external_id' => 'TEST_206',
            'is_active' => true,
        ]);

        DB::table('tenant_external_aliases')->insert([
            'market_id' => $market->id,
            'canonical_tenant_id' => $canonical->id,
            'source_tenant_id' => 206,
            'alias_type' => 'external_id',
            'alias_value' => 'TEST_206',
            'source' => 'tenants:merge',
            'payload' => json_encode(['source_name' => 'Косачев ИП'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signals = app(TenantDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertCount(1, $signals);
        $this->assertSame('high', $signals[0]['severity']);
        $this->assertContains('Одна карточка уже была объединена с другой', $signals[0]['reasons']);
    }

    public function test_it_hides_manually_ignored_duplicate_pair(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        [$tenantA, $tenantB] = $this->createMdnDuplicatePair((int) $market->id);

        DB::table('tenant_duplicate_ignores')->insert([
            'market_id' => $market->id,
            'tenant_left_id' => min($tenantA->id, $tenantB->id),
            'tenant_right_id' => max($tenantA->id, $tenantB->id),
            'reason' => 'different_tenants',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signals = app(TenantDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertSame([], $signals);
    }

    public function test_it_shows_restored_duplicate_pair_again(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        [$tenantA, $tenantB] = $this->createMdnDuplicatePair((int) $market->id);

        DB::table('tenant_duplicate_ignores')->insert([
            'market_id' => $market->id,
            'tenant_left_id' => min($tenantA->id, $tenantB->id),
            'tenant_right_id' => max($tenantA->id, $tenantB->id),
            'reason' => 'different_tenants',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tenant_duplicate_ignores')
            ->where('market_id', $market->id)
            ->where('tenant_left_id', min($tenantA->id, $tenantB->id))
            ->where('tenant_right_id', max($tenantA->id, $tenantB->id))
            ->delete();

        $signals = app(TenantDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertCount(1, $signals);
        $this->assertSame('Возможный дубль арендатора', $signals[0]['title']);
    }

    public function test_it_ignores_unrelated_tenants(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'ООО Ромашка',
            'external_id' => 'tenant-a',
            'inn' => '2222222222',
            'is_active' => true,
        ]);

        Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'ИП Петров Петр Петрович',
            'external_id' => 'tenant-b',
            'inn' => '333333333333',
            'is_active' => true,
        ]);

        $signals = app(TenantDuplicateSignalService::class)->signalsForMarket((int) $market->id);

        $this->assertSame([], $signals);
    }

    /**
     * @return array{0:Tenant,1:Tenant}
     */
    private function createMdnDuplicatePair(int $marketId): array
    {
        $tenantA = Tenant::query()->create([
            'market_id' => $marketId,
            'name' => 'МДН ООО',
            'external_id' => 'tenant-a',
            'inn' => '2222904674',
            'is_active' => true,
        ]);

        $tenantB = Tenant::query()->create([
            'market_id' => $marketId,
            'name' => 'МДН Инжиниринг ООО',
            'external_id' => 'tenant-b',
            'is_active' => true,
        ]);

        return [$tenantA, $tenantB];
    }
}
