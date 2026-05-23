<?php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use App\Services\Tenants\OneCTenantResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OneCTenantResolverAliasTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_records_source_external_aliases_for_future_1c_imports(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        $source = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачев ИП',
            'external_id' => 'short-kosachev-1c-id',
            'inn' => '222405692915',
            'is_active' => true,
        ]);

        $target = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачёв Евгений Сергеевич ИП',
            'external_id' => 'canonical-kosachev-1c-id',
            'inn' => '222405692915',
            'is_active' => true,
        ]);

        $exitCode = Artisan::call('tenants:merge', [
            'from' => $source->id,
            'to' => $target->id,
            '--execute' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $this->assertDatabaseHas('tenant_external_aliases', [
            'market_id' => $market->id,
            'canonical_tenant_id' => $target->id,
            'source_tenant_id' => $source->id,
            'alias_type' => 'external_id',
            'alias_value' => 'short-kosachev-1c-id',
        ]);

        $this->assertDatabaseHas('tenant_external_aliases', [
            'market_id' => $market->id,
            'canonical_tenant_id' => $target->id,
            'source_tenant_id' => $source->id,
            'alias_type' => 'inn',
            'alias_value' => '222405692915',
        ]);
    }

    public function test_1c_resolver_uses_external_alias_without_recreating_merged_tenant(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        $canonical = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Косачёв Евгений Сергеевич ИП',
            'external_id' => 'canonical-kosachev-1c-id',
            'inn' => '222405692915',
            'is_active' => true,
        ]);

        DB::table('tenant_external_aliases')->insert([
            'market_id' => $market->id,
            'canonical_tenant_id' => $canonical->id,
            'source_tenant_id' => 999,
            'alias_type' => 'external_id',
            'alias_value' => 'short-kosachev-1c-id',
            'source' => 'tenants:merge',
            'payload' => json_encode(['source_name' => 'Косачев ИП'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(OneCTenantResolver::class)->resolve(
            (int) $market->id,
            'short-kosachev-1c-id',
            [
                'tenant_name' => 'Косачев ИП',
                'inn' => '222405692915',
            ],
            'accruals',
            now(),
        );

        $this->assertSame('matched_alias', $result['mode']);
        $this->assertSame((int) $canonical->id, (int) $result['tenant']?->id);
        $this->assertSame(1, Tenant::query()->where('market_id', $market->id)->count());
        $this->assertDatabaseHas('tenants', [
            'id' => $canonical->id,
            'external_id' => 'canonical-kosachev-1c-id',
        ]);
    }
}
