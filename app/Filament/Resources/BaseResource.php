<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Support\Search\LooseSearch;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

abstract class BaseResource extends Resource
{
    /**
     * Filament uses lower() for pgsql by default. In current staging locale/collation
     * this can break Cyrillic matching, so we run explicit UTF-8 case variants.
     */
    protected static ?bool $isGlobalSearchForcedCaseInsensitive = false;

    protected static function applyGlobalSearchAttributeConstraints(Builder $query, string $search): void
    {
        $search = trim($search);

        if ($search === '') {
            return;
        }

        $attributesList = static::getGloballySearchableAttributes();

        if ($attributesList === []) {
            return;
        }

        $terms = static::shouldSplitGlobalSearchTerms()
            ? array_filter(
                str_getcsv(
                    preg_replace('/(\s|\x{3164}|\x{1160})+/u', ' ', $search) ?: '',
                    separator: ' ',
                    escape: '\\',
                ),
                static fn ($word): bool => filled($word),
            )
            : [$search];

        foreach ($terms as $term) {
            $termPatterns = LooseSearch::termPatterns($term)[0] ?? null;

            if (! is_array($termPatterns)) {
                continue;
            }

            $query->where(function (Builder $termQuery) use ($attributesList, $termPatterns): void {
                foreach ($attributesList as $attributes) {
                    foreach (Arr::wrap($attributes) as $attribute) {
                        if (str_contains($attribute, '.')) {
                            $relation = (string) str($attribute)->beforeLast('.');
                            $column = (string) str($attribute)->afterLast('.');

                            $termQuery->orWhereHas($relation, function (Builder $relationQuery) use ($column, $termPatterns): void {
                                LooseSearch::orWhereMatchesColumn(
                                    $relationQuery,
                                    $relationQuery->qualifyColumn($column),
                                    $termPatterns,
                                );
                            });

                            continue;
                        }

                        LooseSearch::orWhereMatchesColumn(
                            $termQuery,
                            $termQuery->qualifyColumn($attribute),
                            $termPatterns,
                        );
                    }
                }
            });
        }
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, string>
     */
    protected static function compactGlobalSearchDetails(array $details, int $limit = 4): array
    {
        $result = [];

        foreach ($details as $label => $value) {
            if (! is_string($label) || trim($label) === '') {
                continue;
            }

            $stringValue = trim((string) $value);

            if ($stringValue === '') {
                continue;
            }

            $result[$label] = $stringValue;

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    protected static function compactGlobalSearchTitle(
        ?string $primary,
        ?string $secondary = null,
        ?string $fallback = null,
    ): string {
        $parts = [];

        foreach ([$primary, $secondary] as $value) {
            $value = trim((string) $value);

            if ($value === '' || in_array($value, $parts, true)) {
                continue;
            }

            $parts[] = $value;
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        $fallback = trim((string) $fallback);

        if ($fallback !== '') {
            return $fallback;
        }

        return 'Запись';
    }
}
