<?php
# tests/Feature/SpaceGroupRoleTest.php

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class SpaceGroupRoleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_none_role_clears_token_and_slot(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
        ]);

        $this->assertNull($space->space_group_token);
        $this->assertNull($space->space_group_slot);
    }

    public function test_parent_role_keeps_token_and_clears_slot(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $this->assertEquals('OS8', $space->space_group_token);
        $this->assertNull($space->space_group_slot);
    }

    public function test_child_role_keeps_token_and_slot(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
        ]);

        $this->assertEquals('OS8', $space->space_group_token);
        $this->assertEquals('14', $space->space_group_slot);
    }

    public function test_changing_from_child_to_parent_clears_slot(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
        ]);

        $space->update(['space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT]);

        $this->assertEquals('OS8', $space->space_group_token);
        $this->assertNull($space->space_group_slot);
    }

    public function test_changing_from_parent_to_none_clears_token_and_slot(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $space->update(['space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE]);

        $this->assertNull($space->space_group_token);
        $this->assertNull($space->space_group_slot);
    }

    public function test_unknown_role_normalizes_to_none_and_clears_token_slot(): void
    {
        $market = Market::create(['name' => 'Test Market']);

        $space = MarketSpace::create([
            'market_id' => $market->id,
            'number' => 'A-1',
            'space_group_token' => 'OS8',
            'space_group_slot' => '14',
            'space_group_role' => 'invalid_role',
        ]);

        $this->assertNull($space->space_group_token);
        $this->assertNull($space->space_group_slot);
        $this->assertSame(MarketSpace::SPACE_GROUP_ROLE_NONE, $space->space_group_role);
    }
}