<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OpsDiagnostics;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OpsDiagnosticsSpaceAreaInspectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_sees_only_non_child_spaces_without_area_in_diagnostics(): void
    {
        $market = Market::query()->create([
            'name' => 'Area Inspection Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->makeSuperAdmin($market);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'A-01',
            'display_name' => 'Обычное место',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'area_sqm' => null,
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G-01',
            'display_name' => 'Родитель группы',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'area_sqm' => 0,
            'is_active' => true,
        ]);

        MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'G-01/1',
            'display_name' => 'Child группы',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'area_sqm' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(OpsDiagnostics::getUrl())
            ->assertOk()
            ->assertSee('Площади мест')
            ->assertSee('A-01')
            ->assertSee('G-01')
            ->assertDontSee('G-01/1');
    }

    public function test_super_admin_can_fill_missing_area_from_diagnostics(): void
    {
        $market = Market::query()->create([
            'name' => 'Area Save Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $user = $this->makeSuperAdmin($market);

        $space = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'B-12',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'area_sqm' => null,
            'is_active' => true,
        ]);

        $this->actingAs($user);

        Livewire::test(OpsDiagnostics::class)
            ->set("spaceAreaDrafts.{$space->id}", '12.5')
            ->call('saveSpaceArea', $space->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('market_spaces', [
            'id' => (int) $space->id,
            'area_sqm' => '12.50',
        ]);
    }

    private function makeSuperAdmin(Market $market): User
    {
        Role::findOrCreate('super-admin', 'web');

        $user = User::factory()->create([
            'market_id' => (int) $market->id,
            'email' => 'ops-area-super-admin@example.test',
        ]);

        $user->assignRole('super-admin');

        return $user;
    }
}
