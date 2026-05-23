<?php

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
}
