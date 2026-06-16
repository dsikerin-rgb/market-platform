<?php

declare(strict_types=1);

namespace App\Support\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;

final class LooseSearch
{
    /**
     * @return list<string>
     */
    public static function splitTerms(string $search): array
    {
        return array_values(array_filter(
            preg_split('/\s+/u', trim(preg_replace('/\s+/u', ' ', $search) ?? $search)) ?: [],
            static fn (mixed $term): bool => is_string($term) && $term !== ''
        ));
    }

    public static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');

        if ($value === '') {
            return '';
        }

        $value = str_replace(['ё', '№', '#'], ['е', ' ', ' '], $value);
        $value = preg_replace('/[[:punct:]]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    public static function compact(string $value): string
    {
        return str_replace(' ', '', static::normalize($value));
    }

    /**
     * @return list<string>
     */
    public static function variants(string $value): array
    {
        $candidates = [
            $value,
            static::switchKeyboardLayout($value, 'ru'),
            static::switchKeyboardLayout($value, 'en'),
            static::mapLookalikesToCyrillic($value),
            static::mapLookalikesToLatin($value),
        ];

        $variants = [];

        foreach ($candidates as $candidate) {
            $normalized = static::normalize($candidate);
            if ($normalized !== '') {
                $variants[] = $normalized;
            }

            $compact = static::compact($candidate);
            if ($compact !== '') {
                $variants[] = $compact;
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @return list<array{normalized:list<string>,compact:list<string>}>
     */
    public static function termPatterns(string $search): array
    {
        $terms = static::splitTerms($search);
        $patterns = [];

        foreach ($terms as $term) {
            $variants = static::variants($term);
            $normalized = [];
            $compact = [];

            foreach ($variants as $variant) {
                $normalizedVariant = static::normalize($variant);
                if ($normalizedVariant !== '') {
                    $normalized[] = '%' . static::escapeLike($normalizedVariant) . '%';
                }

                $compactVariant = static::compact($variant);
                if ($compactVariant !== '') {
                    $compact[] = '%' . static::escapeLike($compactVariant) . '%';
                }
            }

            $normalized = array_values(array_unique($normalized));
            $compact = array_values(array_unique($compact));

            if ($normalized !== [] || $compact !== []) {
                $patterns[] = [
                    'normalized' => $normalized,
                    'compact' => $compact,
                ];
            }
        }

        return $patterns;
    }

    /**
     * @param  list<Closure(Builder, array{normalized:list<string>,compact:list<string>}):void>  $callbacks
     */
    public static function applySearch(Builder $query, string $search, array $callbacks): Builder
    {
        $patterns = static::termPatterns($search);

        foreach ($patterns as $termPatterns) {
            $query->where(function (Builder $termQuery) use ($callbacks, $termPatterns): void {
                foreach ($callbacks as $callback) {
                    $callback($termQuery, $termPatterns);
                }
            });
        }

        return $query;
    }

    /**
     * @param  list<string>  $columns
     */
    public static function applySearchToColumns(Builder $query, string $search, array $columns): Builder
    {
        return static::applySearch($query, $search, [
            static function (Builder $termQuery, array $termPatterns) use ($columns): void {
                static::orWhereMatchesColumns($termQuery, $columns, $termPatterns);
            },
        ]);
    }

    /**
     * @param  list<string>  $columns
     * @param  array{normalized:list<string>,compact:list<string>}  $termPatterns
     */
    public static function orWhereMatchesColumns(Builder $query, array $columns, array $termPatterns): void
    {
        foreach ($columns as $column) {
            static::orWhereMatchesColumn($query, $column, $termPatterns);
        }
    }

    /**
     * @param  array{normalized:list<string>,compact:list<string>}  $termPatterns
     */
    public static function orWhereMatchesColumn(Builder $query, string $column, array $termPatterns): void
    {
        $driver = $query->getConnection()->getDriverName();
        $normalizedSql = static::normalizedSql($column, $driver);
        $compactSql = static::compactSql($column, $driver);

        $query->orWhere(function (Builder $columnQuery) use ($normalizedSql, $compactSql, $termPatterns): void {
            foreach ($termPatterns['normalized'] as $pattern) {
                $columnQuery->orWhereRaw($normalizedSql . ' like ?', [$pattern]);
            }

            foreach ($termPatterns['compact'] as $pattern) {
                $columnQuery->orWhereRaw($compactSql . ' like ?', [$pattern]);
            }
        });
    }

    public static function matchesText(string $haystack, string $search): bool
    {
        $terms = static::splitTerms($search);

        if ($terms === []) {
            return true;
        }

        $normalizedHaystack = static::normalize($haystack);
        $compactHaystack = str_replace(' ', '', $normalizedHaystack);
        $haystackTokens = $normalizedHaystack === '' ? [] : explode(' ', $normalizedHaystack);

        foreach ($terms as $term) {
            $matched = false;

            foreach (static::variants($term) as $variant) {
                $normalizedVariant = static::normalize($variant);
                $compactVariant = static::compact($variant);

                if (
                    ($normalizedVariant !== '' && str_contains($normalizedHaystack, $normalizedVariant))
                    || ($compactVariant !== '' && str_contains($compactHaystack, $compactVariant))
                    || ($normalizedVariant !== '' && static::hasFuzzyTokenMatch($haystackTokens, $normalizedVariant))
                    || ($compactVariant !== '' && static::hasFuzzyTokenMatch($haystackTokens, $compactVariant))
                ) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                return false;
            }
        }

        return true;
    }

    private static function normalizedSql(string $column, string $driver): string
    {
        $valueSql = $driver === 'pgsql' ? "({$column})::text" : $column;
        $expr = "lower(coalesce({$valueSql}, ''))";

        if ($driver === 'pgsql') {
            return "trim(regexp_replace(regexp_replace({$expr}, '[[:punct:]]+', ' ', 'g'), '[[:space:]]+', ' ', 'g'))";
        }

        return "trim(regexp_replace(regexp_replace({$expr}, '[[:punct:]]+', ' '), '[[:space:]]+', ' '))";
    }

    private static function compactSql(string $column, string $driver): string
    {
        return "replace(" . static::normalizedSql($column, $driver) . ", ' ', '')";
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private static function switchKeyboardLayout(string $value, string $target): string
    {
        $value = mb_strtolower($value, 'UTF-8');

        $enToRu = [
            'q' => 'й', 'w' => 'ц', 'e' => 'у', 'r' => 'к', 't' => 'е', 'y' => 'н', 'u' => 'г', 'i' => 'ш', 'o' => 'щ',
            'p' => 'з', '[' => 'х', ']' => 'ъ', 'a' => 'ф', 's' => 'ы', 'd' => 'в', 'f' => 'а', 'g' => 'п', 'h' => 'р',
            'j' => 'о', 'k' => 'л', 'l' => 'д', ';' => 'ж', "'" => 'э', 'z' => 'я', 'x' => 'ч', 'c' => 'с', 'v' => 'м',
            'b' => 'и', 'n' => 'т', 'm' => 'ь', ',' => 'б', '.' => 'ю', '`' => 'ё',
        ];

        $ruToEn = array_flip($enToRu);

        return strtr($value, $target === 'ru' ? $enToRu : $ruToEn);
    }

    private static function mapLookalikesToCyrillic(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'a' => 'а',
            'b' => 'в',
            'c' => 'с',
            'e' => 'е',
            'h' => 'н',
            'k' => 'к',
            'm' => 'м',
            'o' => 'о',
            'p' => 'р',
            't' => 'т',
            'x' => 'х',
            'y' => 'у',
        ]);
    }

    private static function mapLookalikesToLatin(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), [
            'а' => 'a',
            'в' => 'b',
            'с' => 'c',
            'е' => 'e',
            'н' => 'h',
            'к' => 'k',
            'м' => 'm',
            'о' => 'o',
            'р' => 'p',
            'т' => 't',
            'х' => 'x',
            'у' => 'y',
        ]);
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function hasFuzzyTokenMatch(array $tokens, string $needle): bool
    {
        $needle = static::compact($needle);
        $needleLength = mb_strlen($needle, 'UTF-8');

        if ($needleLength < 5) {
            return false;
        }

        $allowedDistance = $needleLength >= 9 ? 2 : 1;

        foreach ($tokens as $token) {
            $token = static::compact($token);

            if ($token === '') {
                continue;
            }

            if (abs(mb_strlen($token, 'UTF-8') - $needleLength) > $allowedDistance) {
                continue;
            }

            if (static::mbLevenshtein($token, $needle) <= $allowedDistance) {
                return true;
            }
        }

        return false;
    }

    private static function mbLevenshtein(string $left, string $right): int
    {
        $leftChars = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightChars = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $leftLength = count($leftChars);
        $rightLength = count($rightChars);

        if ($leftLength === 0) {
            return $rightLength;
        }

        if ($rightLength === 0) {
            return $leftLength;
        }

        $previousRow = range(0, $rightLength);

        for ($i = 1; $i <= $leftLength; $i++) {
            $currentRow = [$i];

            for ($j = 1; $j <= $rightLength; $j++) {
                $insertions = $currentRow[$j - 1] + 1;
                $deletions = $previousRow[$j] + 1;
                $substitutions = $previousRow[$j - 1] + ($leftChars[$i - 1] === $rightChars[$j - 1] ? 0 : 1);
                $currentRow[$j] = min($insertions, $deletions, $substitutions);
            }

            $previousRow = $currentRow;
        }

        return $previousRow[$rightLength];
    }
}
