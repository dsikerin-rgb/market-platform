<?php
# tests/Feature/LooseSearchRelationConstraintTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Tenant;
use App\Support\Search\LooseSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LooseSearchRelationConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_relation_loose_search_does_not_leak_unmatched_parent_rows(): void
    {
        $market = Market::query()->create([
            'name' => 'Loose Search Market',
            'is_active' => true,
        ]);

        $matchedTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Нурманова Мархабо Сайфуллаевна',
            'short_name' => 'Нурманова М',
            'is_active' => true,
        ]);

        $otherTenant = Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => 'Попова Ирина Викторовна',
            'short_name' => 'Попова И',
            'is_active' => true,
        ]);

        $matched = $this->createSpace($market, $matchedTenant, 'Н-1');
        $this->createSpace($market, $otherTenant, 'П-1');

        $ids = LooseSearch::applySearch(
            MarketSpace::query()->where('market_id', (int) $market->id),
            'Нурман',
            [
                static function (Builder $searchQuery, array $termPatterns): void {
                    $searchQuery->orWhereHas('tenant', function (Builder $tenantQuery) use ($termPatterns): void {
                        LooseSearch::orWhereMatchesColumns($tenantQuery, [
                            'tenants.name',
                            'tenants.short_name',
                        ], $termPatterns);
                    });
                },
            ],
        )
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        self::assertSame([(int) $matched->id], $ids);
    }

    private function createSpace(Market $market, Tenant $tenant, string $number): MarketSpace
    {
        return MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => $number,
            'display_name' => 'Место ' . $number,
            'tenant_id' => (int) $tenant->id,
            'status' => 'occupied',
            'area_sqm' => 10,
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_NONE,
            'is_active' => true,
        ]);
    }
}
