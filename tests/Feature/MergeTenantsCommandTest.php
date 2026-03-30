<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MergeTenantsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_keeps_canonical_active_when_source_is_active(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        $source = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Source tenant',
            'is_active' => true,
        ]);

        $target = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Target tenant',
            'is_active' => false,
        ]);

        $exitCode = Artisan::call('tenants:merge', [
            'from' => $source->id,
            'to' => $target->id,
            '--execute' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('tenants', [
            'id' => $source->id,
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $target->id,
            'is_active' => true,
        ]);
    }

    public function test_merge_keeps_canonical_inactive_when_both_tenants_are_inactive(): void
    {
        $market = Market::query()->create([
            'name' => 'Test market',
        ]);

        $source = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Inactive source',
            'is_active' => false,
        ]);

        $target = Tenant::query()->create([
            'market_id' => $market->id,
            'name' => 'Inactive target',
            'is_active' => false,
        ]);

        $exitCode = Artisan::call('tenants:merge', [
            'from' => $source->id,
            'to' => $target->id,
            '--execute' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseMissing('tenants', [
            'id' => $source->id,
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $target->id,
            'is_active' => false,
        ]);
    }
}
