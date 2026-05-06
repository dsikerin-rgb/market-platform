<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\MarketSpaceResource\Pages\EditMarketSpace;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MarketSpaceChildIdentityEditTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        Role::findOrCreate('super-admin', 'web');

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
    }

    public function test_existing_child_can_fill_missing_number_and_display_name_without_review(): void
    {
        $this->actingAsSuperAdmin();

        $market = Market::query()->create([
            'name' => 'Test Market',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $parent = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => 'OS7 6, 7, 8',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
            'is_active' => true,
        ]);

        $child = MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => '',
            'display_name' => '',
            'code' => 'os7-child-empty',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '8',
            'status' => 'vacant',
            'is_active' => true,
        ]);

        Livewire::withQueryParams([
            'tab' => 'osnovnoe::data::tab',
        ])
            ->test(EditMarketSpace::class, ['record' => (string) $child->getRouteKey()])
            ->fillForm([
                'number' => 'OS7 8',
                'display_name' => 'OS7 8',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $child->refresh();

        $this->assertSame('OS7 8', $child->number);
        $this->assertSame('OS7 8', $child->display_name);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_CHILD, $child->space_group_role);
        $this->assertSame((int) $parent->id, (int) $child->space_group_parent_id);
        $this->assertSame('8', $child->space_group_slot);
    }
}
