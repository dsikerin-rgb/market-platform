<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MarketSpaces\SpaceGroupResolver;
use PHPUnit\Framework\TestCase;

class SpaceGroupResolverTest extends TestCase
{
    public function test_detects_group_and_slot_from_market_space_number(): void
    {
        $resolver = new SpaceGroupResolver();

        $result = $resolver->forMarketSpaceNumber('ОС8 13, 14');

        $this->assertSame('ОС8', $result['group_token']);
        $this->assertSame('13,14', $result['group_segments']);
    }

    public function test_detects_group_and_slot_from_market_space_number_with_slash(): void
    {
        $resolver = new SpaceGroupResolver();

        $result = $resolver->forMarketSpaceNumber('ОС22/1');

        $this->assertSame('ОС22', $result['group_token']);
        $this->assertSame('1', $result['group_segments']);
    }

    public function test_detects_os_group_and_segments_from_composite_place_token(): void
    {
        $resolver = new SpaceGroupResolver();

        $result = $resolver->forContractClassification([
            'place_token' => 'ОС-8/14-15',
        ]);

        $this->assertTrue($result['is_composite']);
        $this->assertSame('ОС8', $result['group_token']);
        $this->assertSame('14-15', $result['group_segments']);
    }

    public function test_detects_os_group_and_segments_with_space_separator(): void
    {
        $resolver = new SpaceGroupResolver();

        $result = $resolver->forContractClassification([
            'place_token' => 'ОС1 14, 15',
        ]);

        $this->assertTrue($result['is_composite']);
        $this->assertSame('ОС1', $result['group_token']);
        $this->assertSame('14,15', $result['group_segments']);
    }

    public function test_returns_empty_group_meta_for_regular_place_token(): void
    {
        $resolver = new SpaceGroupResolver();

        $result = $resolver->forContractClassification([
            'place_token' => 'П/32/1',
        ]);

        $this->assertFalse($result['is_composite']);
        $this->assertNull($result['group_token']);
        $this->assertNull($result['group_segments']);
    }

    public function test_returns_empty_group_meta_for_regular_market_space_number(): void
    {
        $resolver = new SpaceGroupResolver();

        $result = $resolver->forMarketSpaceNumber('П32/1');

        $this->assertNull($result['group_token']);
        $this->assertNull($result['group_segments']);
    }
}
