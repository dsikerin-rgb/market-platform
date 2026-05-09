<?php

# tests/Feature/MarketMapGroupMembershipTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketMapGroupMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        Role::findOrCreate('super-admin');

        $user = User::factory()->create();
        $user->assignRole('super-admin');
        $this->actingAs($user, 'web');

        if (! config('auth.guards.filament')) {
            config()->set('auth.guards.filament', [
                'driver' => 'session',
                'provider' => 'users',
            ]);
        }
        $this->actingAs($user, 'filament');

        return $user;
    }

    private function selectMarketInSession(Market $market): void
    {
        $this->withSession([
            'filament.admin.selected_market_id' => (int) $market->id,
        ]);
    }

    private function createMarketWithMap(): Market
    {
        Storage::fake('local');
        Storage::disk('local')->put('market-maps/map.pdf', 'fake');

        return Market::create([
            'name' => 'Тестовый рынок',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
            'settings' => [
                'map_pdf_path' => 'market-maps/map.pdf',
            ],
        ]);
    }

    public function test_add_ordinary_space_to_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('action', 'add_to_group');
        $response->assertJsonPath('market_space_id', (int) $space->id);
        $response->assertJsonPath('old_parent_id', null);
        $response->assertJsonPath('new_parent_id', (int) $parent->id);
        $response->assertJsonPath('space_group_role', 'child');

        $space->refresh();
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, $space->space_group_role);
        $this->assertSame($parent->id, $space->space_group_parent_id);
        $this->assertSame('6', $space->space_group_slot);
    }

    public function test_move_child_to_another_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $oldParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $newParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $oldParent->id,
            'space_group_slot' => '6',
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'move_to_group',
            'target_parent_id' => $newParent->id,
            'target_slot' => '8',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('action', 'move_to_group');
        $response->assertJsonPath('market_space_id', (int) $child->id);
        $response->assertJsonPath('old_parent_id', (int) $oldParent->id);
        $response->assertJsonPath('new_parent_id', (int) $newParent->id);
        $response->assertJsonPath('space_group_role', 'child');

        $child->refresh();
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, $child->space_group_role);
        $this->assertSame($newParent->id, $child->space_group_parent_id);
        $this->assertSame('8', $child->space_group_slot);
    }

    public function test_remove_child_from_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '6',
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'remove_from_group',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('action', 'remove_from_group');
        $response->assertJsonPath('market_space_id', (int) $child->id);
        $response->assertJsonPath('old_parent_id', (int) $parent->id);
        $response->assertJsonPath('new_parent_id', null);
        $response->assertJsonPath('space_group_role', 'none');

        $child->refresh();
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $child->space_group_role);
        $this->assertNull($child->space_group_parent_id);
        $this->assertNull($child->space_group_slot);
    }

    public function test_cannot_add_to_group_from_different_market(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market1 = $this->createMarketWithMap();
        $market2 = $this->createMarketWithMap();
        $this->selectMarketInSession($market1);

        $parent = MarketSpace::create([
            'market_id' => $market2->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market1->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_cannot_add_space_to_itself_as_parent(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $space->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
    }

    public function test_cannot_use_child_as_target_parent(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $child->id,
            'target_slot' => '8',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_cannot_remove_from_group_for_ordinary_space(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'remove_from_group',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['market_space_id']);
    }

    public function test_cannot_move_to_group_for_ordinary_space(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'move_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_cannot_add_already_child_to_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent1 = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $parent2 = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent1->id,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent2->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_target_parent_id_required_for_add_to_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_target_slot_required_for_move_to_group_when_child_has_no_slot(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => true,
        ]);

        $newParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'move_to_group',
            'target_parent_id' => $newParent->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_slot']);
    }

    public function test_target_parent_id_required_for_move_to_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '6',
            'is_active' => true,
        ]);

        $newParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'move_to_group',
            'target_slot' => '8',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_tenant_id_not_changed_after_group_operation(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $tenant = \App\Models\Tenant::create([
            'market_id' => $market->id,
            'name' => 'Test Tenant',
            'short_name' => 'Test',
            'is_active' => true,
        ]);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertOk();

        $space->refresh();
        $this->assertSame($tenant->id, $space->tenant_id);
    }

    public function test_inactive_parent_group_rejected(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => false,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_parent_id']);
    }

    public function test_duplicate_slot_rejected(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7 6, 7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '6',
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_slot']);
    }

    public function test_add_to_group_normalizes_target_slot(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-01',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => ' 01 ',
        ]);

        $response->assertOk();

        $space->refresh();
        $this->assertSame('01', $space->space_group_slot);
    }

    public function test_add_to_group_writes_space_group_token(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'space_group_token' => 'OS7',
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertOk();

        $space->refresh();
        $this->assertSame('OS7', $space->space_group_token);
    }

    public function test_duplicate_slot_rejected_after_normalization(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'space_group_slot' => '6',
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => ' 6 ',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['target_slot']);
    }

    public function test_inactive_ordinary_space_cannot_be_added_to_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => false,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $space->id,
        ]), [
            'action' => 'add_to_group',
            'target_parent_id' => $parent->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['market_space_id']);
    }

    public function test_inactive_child_cannot_be_moved_to_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $newParent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => false,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'move_to_group',
            'target_parent_id' => $newParent->id,
            'target_slot' => '6',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['market_space_id']);
    }

    public function test_inactive_child_cannot_be_removed_from_group(): void
    {
        $user = $this->actingAsSuperAdmin();
        $market = $this->createMarketWithMap();
        $this->selectMarketInSession($market);

        $parent = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'OS7-6',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => $parent->id,
            'is_active' => false,
        ]);

        $response = $this->postJson(route('filament.admin.market-map.spaces.group-membership', [
            'marketSpace' => $child->id,
        ]), [
            'action' => 'remove_from_group',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['market_space_id']);
    }
}
