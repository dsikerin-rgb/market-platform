<?php
# tests/Feature/MarketSpaceTableSearchTest.php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Market;
use App\Models\MarketLocation;
use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\MarketSpaceType;
use App\Models\Tenant;
use App\Support\MarketSpaces\MarketSpaceTableSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketSpaceTableSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_matches_direct_tenant_name(): void
    {
        $market = $this->createMarket();
        $tenant = $this->createTenant($market, 'Танцы ООО');
        $otherTenant = $this->createTenant($market, 'Аптека ООО');

        $matched = $this->createSpace($market, [
            'number' => 'Т-1',
            'display_name' => 'Студия',
            'tenant_id' => (int) $tenant->id,
            'status' => 'occupied',
        ]);

        $this->createSpace($market, [
            'number' => 'А-1',
            'display_name' => 'Аптека',
            'tenant_id' => (int) $otherTenant->id,
            'status' => 'occupied',
        ]);

        $ids = $this->searchIds($market, 'Танцы');

        self::assertSame([(int) $matched->id], $ids);
    }

    public function test_search_matches_parent_group_tenant_for_child_space(): void
    {
        $market = $this->createMarket();
        $tenant = $this->createTenant($market, 'Танцы в павильоне ИП');

        $parent = $this->createSpace($market, [
            'number' => 'Группа Танцы',
            'tenant_id' => (int) $tenant->id,
            'status' => 'occupied',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_PARENT,
        ]);

        $child = $this->createSpace($market, [
            'number' => 'ГТ-1',
            'tenant_id' => null,
            'status' => 'occupied',
            'space_group_role' => MarketSpace::SPACE_GROUP_ROLE_CHILD,
            'space_group_parent_id' => (int) $parent->id,
            'space_group_slot' => '1',
        ]);

        $this->createSpace($market, [
            'number' => 'П-1',
            'display_name' => 'Продукты',
            'tenant_id' => null,
            'status' => 'vacant',
        ]);

        $ids = $this->searchIds($market, 'павильоне');

        self::assertContains((int) $parent->id, $ids);
        self::assertContains((int) $child->id, $ids);
    }

    public function test_search_matches_space_type_label_and_shared_use_participant(): void
    {
        $market = $this->createMarket();
        $sharedTenant = $this->createTenant($market, 'Школа танцев ИП');

        MarketSpaceType::query()->create([
            'market_id' => (int) $market->id,
            'code' => 'dance-room',
            'name_ru' => 'Танцевальный зал',
            'unit' => 'sqm',
            'is_active' => true,
            'category' => 'commercial',
        ]);

        $typeMatched = $this->createSpace($market, [
            'number' => 'ТЗ-1',
            'display_name' => 'Зал',
            'type' => 'dance-room',
            'status' => 'vacant',
        ]);

        $sharedMatched = $this->createSpace($market, [
            'number' => 'СМ-1',
            'display_name' => 'Совместное место',
            'status' => 'occupied',
        ]);

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $market->id,
            'market_space_id' => (int) $sharedMatched->id,
            'tenant_id' => (int) $sharedTenant->id,
            'binding_type' => 'shared_use',
            'source' => 'test',
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $idsByType = $this->searchIds($market, 'танцевальный');
        $idsBySharedTenant = $this->searchIds($market, 'школа');

        self::assertSame([(int) $typeMatched->id], $idsByType);
        self::assertSame([(int) $sharedMatched->id], $idsBySharedTenant);
    }

    private function createMarket(): Market
    {
        return Market::query()->create([
            'name' => 'Search Market',
            'is_active' => true,
        ]);
    }

    private function createTenant(Market $market, string $name): Tenant
    {
        return Tenant::query()->create([
            'market_id' => (int) $market->id,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createSpace(Market $market, array $attributes): MarketSpace
    {
        return MarketSpace::query()->create([
            'market_id' => (int) $market->id,
            'number' => $attributes['number'] ?? 'П-1',
            'display_name' => $attributes['display_name'] ?? null,
            'tenant_id' => $attributes['tenant_id'] ?? null,
            'status' => $attributes['status'] ?? 'vacant',
            'area_sqm' => $attributes['area_sqm'] ?? 10,
            'type' => $attributes['type'] ?? null,
            'space_group_role' => $attributes['space_group_role'] ?? MarketSpace::SPACE_GROUP_ROLE_NONE,
            'space_group_parent_id' => $attributes['space_group_parent_id'] ?? null,
            'space_group_slot' => $attributes['space_group_slot'] ?? null,
            'is_active' => true,
        ]);
    }

    /**
     * @return list<int>
     */
    private function searchIds(Market $market, string $search): array
    {
        return MarketSpaceTableSearch::apply(
            MarketSpace::query()->where('market_id', (int) $market->id),
            $search,
        )
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
