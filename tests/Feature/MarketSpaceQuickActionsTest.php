<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\OperationResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketSpaceQuickActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_edit_page_has_quick_actions_with_prefill_params(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Арендатор',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'number' => 'A-1',
            'status' => 'occupied',
        ]);

        DB::table('tenant_accruals')->insert([
            'market_id' => $market->id,
            'tenant_id' => $tenant->id,
            'market_space_id' => $space->id,
            'period' => '2025-12-01',
            'rent_rate' => 123.45,
        ]);

        Role::create(['name' => 'super-admin', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $editUrl = MarketSpaceResource::getUrl('edit', ['record' => $space]) . '?period=2025-12-01';

        $response = $this
            ->actingAs($user)
            ->withSession(['filament.admin.selected_market_id' => $market->id])
            ->get($editUrl);

        $response->assertOk();

        $tenantOpUrl = OperationResource::getUrl('create', [
            'type' => OperationType::TENANT_SWITCH,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'market_space_id' => $space->id,
            'market_id' => $market->id,
            'period' => '2025-12-01',
            'return_url' => MarketSpaceResource::getUrl('edit', ['record' => $space]),
            'from_tenant_id' => $tenant->id,
            'focus' => 'to_tenant_id',
        ]);

        $rentOpUrl = OperationResource::getUrl('create', [
            'type' => OperationType::RENT_RATE_CHANGE,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'market_space_id' => $space->id,
            'market_id' => $market->id,
            'period' => '2025-12-01',
            'return_url' => MarketSpaceResource::getUrl('edit', ['record' => $space]),
            'from_rent_rate' => 123.45,
            'focus' => 'to_rent_rate',
        ]);

        $response->assertSee($tenantOpUrl);
        $response->assertSee($rentOpUrl);
    }
}
