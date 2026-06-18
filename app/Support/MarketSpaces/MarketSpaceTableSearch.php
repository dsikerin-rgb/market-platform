<?php
# app/Support/MarketSpaces/MarketSpaceTableSearch.php

declare(strict_types=1);

namespace App\Support\MarketSpaces;

use App\Support\Search\LooseSearch;
use Illuminate\Database\Eloquent\Builder;

final class MarketSpaceTableSearch
{
    public static function apply(Builder $query, string $search): Builder
    {
        return LooseSearch::applySearch($query, $search, [
            static function (Builder $searchQuery, array $termPatterns): void {
                LooseSearch::orWhereMatchesColumns($searchQuery, [
                    'market_spaces.number',
                    'market_spaces.code',
                    'market_spaces.display_name',
                    'market_spaces.activity_type',
                    'market_spaces.type',
                    'market_spaces.space_group_token',
                    'market_spaces.space_group_slot',
                ], $termPatterns);
            },
            static function (Builder $searchQuery, array $termPatterns): void {
                $searchQuery->orWhereHas('location', function (Builder $locationQuery) use ($termPatterns): void {
                    LooseSearch::orWhereMatchesColumn($locationQuery, 'name', $termPatterns);
                });
            },
            static function (Builder $searchQuery, array $termPatterns): void {
                $searchQuery->orWhereHas('tenant', function (Builder $tenantQuery) use ($termPatterns): void {
                    self::orWhereTenantMatches($tenantQuery, $termPatterns);
                });
            },
            static function (Builder $searchQuery, array $termPatterns): void {
                $searchQuery->orWhereHas('spaceGroupParent.tenant', function (Builder $tenantQuery) use ($termPatterns): void {
                    self::orWhereTenantMatches($tenantQuery, $termPatterns);
                });
            },
            static function (Builder $searchQuery, array $termPatterns): void {
                $searchQuery->orWhereHas('spaceType', function (Builder $typeQuery) use ($termPatterns): void {
                    $typeQuery->whereColumn('market_space_types.market_id', 'market_spaces.market_id');

                    LooseSearch::orWhereMatchesColumns($typeQuery, [
                        'market_space_types.name_ru',
                        'market_space_types.code',
                    ], $termPatterns);
                });
            },
            static function (Builder $searchQuery, array $termPatterns): void {
                $searchQuery->orWhereHas('tenantBindings', function (Builder $bindingQuery) use ($termPatterns): void {
                    $bindingQuery
                        ->where('binding_type', 'shared_use')
                        ->whereNull('ended_at')
                        ->whereHas('tenant', function (Builder $tenantQuery) use ($termPatterns): void {
                            self::orWhereTenantMatches($tenantQuery, $termPatterns);
                        });
                });
            },
        ]);
    }

    /**
     * @param  array{normalized:list<string>,compact:list<string>}  $termPatterns
     */
    private static function orWhereTenantMatches(Builder $tenantQuery, array $termPatterns): void
    {
        LooseSearch::orWhereMatchesColumns($tenantQuery, [
            'tenants.name',
            'tenants.short_name',
            'tenants.inn',
            'tenants.external_id',
            'tenants.one_c_uid',
        ], $termPatterns);
    }
}
