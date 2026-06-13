<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Operations\OperationType;
use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\OperationResource;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

        Role::findOrCreate('super-admin', 'web');

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

        $response->assertSee('A-1');
        $response->assertSee('Название (для отображения)', false);
        $response->assertSee('Изменить номер места', false);
        $response->assertDontSee('Переименовать место', false);
        $response->assertSee('title="Не заменяет сценарий &quot;Упразднить место&quot;"', false);
    }

    public function test_edit_page_renders_deactivate_precheck_modal(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-2',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $editUrl = MarketSpaceResource::getUrl('edit', ['record' => $space]);

        $response = $this
            ->actingAs($user)
            ->withSession(['filament.admin.selected_market_id' => $market->id])
            ->get($editUrl);

        $response->assertOk();
        $response->assertSee('Упразднить место');
        $response->assertDontSee('Живые связи');
        $response->assertDontSee('Переносимые');
        $response->assertDontSee('Блокирующие');
        $response->assertDontSee('Архивные');
        $response->assertDontSee('Следующие шаги');
    }

    public function test_edit_page_hides_irrelevant_quick_actions_for_vacant_and_shared_spaces(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $vacantSpace = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'V-1',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        $sharedSpace = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'S-1',
            'status' => 'occupied',
            'is_active' => true,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
        ]);

        $tenant = Tenant::create([
            'market_id' => $market->id,
            'name' => 'ООО Совместный участник',
        ]);

        DB::table('market_space_tenant_bindings')->insert([
            'market_id' => $market->id,
            'market_space_id' => $sharedSpace->id,
            'tenant_id' => $tenant->id,
            'tenant_contract_id' => null,
            'started_at' => '2026-01-01 00:00:00',
            'ended_at' => null,
            'area_sqm' => 2,
            'rent_rate' => 250,
            'share_note' => null,
            'binding_type' => 'shared_use',
            'confidence' => 'medium',
            'source' => 'test_shared_use',
            'created_by_user_id' => null,
            'resolution_reason' => 'test_shared_space_use',
            'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->withSession(['filament.admin.selected_market_id' => $market->id]);

        Livewire::withQueryParams([
            'tab' => 'osnovnoe::data::tab',
        ])
            ->actingAs($user)
            ->test(\App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace::class, [
                'record' => (string) $vacantSpace->getRouteKey(),
            ])
            ->assertActionHidden('mark_space_free')
            ->assertActionVisible('switch_tenant')
            ->assertActionVisible('start_shared_use')
            ->assertActionVisible('mark_service_status');

        Livewire::withQueryParams([
            'tab' => 'osnovnoe::data::tab',
        ])
            ->actingAs($user)
            ->test(\App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace::class, [
                'record' => (string) $sharedSpace->getRouteKey(),
            ])
            ->assertActionVisible('manage_shared_use')
            ->assertActionHidden('mark_space_free')
            ->assertActionHidden('mark_service_status')
            ->assertActionHidden('regroup_child');
    }

    public function test_edit_page_allows_inline_display_name_edit(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '286',
            'display_name' => 'Старое название',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->withSession(['filament.admin.selected_market_id' => $market->id]);

        Livewire::withQueryParams([
            'tab' => 'osnovnoe::data::tab',
        ])
            ->actingAs($user)
            ->test(\App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace::class, [
                'record' => (string) $space->getRouteKey(),
            ])
            ->fillForm([
                'display_name' => 'Новое видимое название',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $space->refresh();

        $this->assertSame('Новое видимое название', $space->display_name);
        $this->assertDatabaseCount('operations', 0);
    }

    public function test_edit_page_can_change_number_via_action_and_logs_operation(): void
    {
        $market = Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => '286',
            'display_name' => 'Старое название',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create();
        $user->assignRole('super-admin');

        $this->withSession(['filament.admin.selected_market_id' => $market->id]);

        Livewire::withQueryParams([
            'tab' => 'osnovnoe::data::tab',
        ])
            ->actingAs($user)
            ->test(\App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace::class, [
                'record' => (string) $space->getRouteKey(),
            ])
            ->call('changeNumber', [
                'number' => '286-A',
            ])
            ->assertHasNoFormErrors();

        $space->refresh();

        $this->assertSame('286-A', $space->number);

        $this->assertDatabaseHas('operations', [
            'market_id' => $market->id,
            'entity_type' => 'market_space',
            'entity_id' => $space->id,
            'type' => OperationType::SPACE_ATTRS_CHANGE,
            'status' => 'applied',
        ]);

        $operation = Operation::query()
            ->where('market_id', $market->id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $space->id)
            ->where('type', OperationType::SPACE_ATTRS_CHANGE)
            ->where('status', 'applied')
            ->latest('id')
            ->first();

        $this->assertNotNull($operation);
        $this->assertSame('286-A', data_get($operation?->payload, 'number'));
    }
}
