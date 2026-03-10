<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TenantContracts\ContractNumberSpaceMatcher;
use PHPUnit\Framework\TestCase;

class ContractNumberSpaceMatcherTest extends TestCase
{
    public function test_matches_exact_unique_place_from_contract_number(): void
    {
        $matcher = new ContractNumberSpaceMatcher();

        $result = $matcher->match('П/46 от 01.05.2024', [
            ['id' => 24, 'number' => 'П/46', 'code' => null],
            ['id' => 32, 'number' => 'П/54', 'code' => null],
        ]);

        $this->assertSame('ok', $result['state']);
        $this->assertSame(24, $result['market_space_id']);
    }

    public function test_does_not_match_shorter_prefix_when_contract_has_more_specific_place(): void
    {
        $matcher = new ContractNumberSpaceMatcher();

        $result = $matcher->match('П/3/1 от 01.05.2024', [
            ['id' => 230, 'number' => 'П/3', 'code' => '5815597.356141646'],
            ['id' => 5, 'number' => 'П/3/1', 'code' => null],
        ]);

        $this->assertSame('ok', $result['state']);
        $this->assertSame(5, $result['market_space_id']);
    }

    public function test_matches_when_separators_differ(): void
    {
        $matcher = new ContractNumberSpaceMatcher();

        $result = $matcher->match('П46 от 01.05.2024', [
            ['id' => 24, 'number' => 'П/46', 'code' => null],
        ]);

        $this->assertSame('ok', $result['state']);
        $this->assertSame(24, $result['market_space_id']);
    }

    public function test_marks_ambiguous_when_multiple_places_match_equally(): void
    {
        $matcher = new ContractNumberSpaceMatcher();

        $result = $matcher->match('П53 от 01.05.2024', [
            ['id' => 53, 'number' => 'П/53', 'code' => null],
            ['id' => 153, 'number' => 'П53', 'code' => null],
        ]);

        $this->assertSame('ambiguous', $result['state']);
        $this->assertSame([53, 153], $result['candidate_ids']);
    }
}
