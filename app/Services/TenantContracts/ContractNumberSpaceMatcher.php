<?php
# app/Services/TenantContracts/ContractNumberSpaceMatcher.php

declare(strict_types=1);

namespace App\Services\TenantContracts;

use Illuminate\Support\Collection;

class ContractNumberSpaceMatcher
{
    /**
     * @param iterable<int, object|array<string, mixed>> $spaces
     * @return array{
     *   state: 'ok'|'ambiguous'|'not_found',
     *   market_space_id: ?int,
     *   candidate_ids: list<int>,
     *   matched_keys: list<string>
     * }
     */
    public function match(string $contractNumber, iterable $spaces): array
    {
        $normalizedContract = $this->normalizeText($contractNumber);
        if ($normalizedContract === '') {
            return [
                'state' => 'not_found',
                'market_space_id' => null,
                'candidate_ids' => [],
                'matched_keys' => [],
            ];
        }

        /** @var array<int, array{id:int, keys:list<string>}> $matches */
        $matches = [];

        foreach ($this->normalizeSpaces($spaces) as $space) {
            $matchedKeys = [];

            foreach ($space['keys'] as $key) {
                if ($this->contractMentionsSpaceKey($normalizedContract, $key)) {
                    $matchedKeys[] = $key;
                }
            }

            if ($matchedKeys === []) {
                continue;
            }

            $matches[$space['id']] = [
                'id' => $space['id'],
                'keys' => array_values(array_unique($matchedKeys)),
            ];
        }

        if ($matches === []) {
            return [
                'state' => 'not_found',
                'market_space_id' => null,
                'candidate_ids' => [],
                'matched_keys' => [],
            ];
        }

        if (count($matches) > 1) {
            return [
                'state' => 'ambiguous',
                'market_space_id' => null,
                'candidate_ids' => array_values(array_map(
                    static fn (array $match): int => $match['id'],
                    $matches
                )),
                'matched_keys' => array_values(array_unique(array_merge(
                    [],
                    ...array_map(static fn (array $match): array => $match['keys'], $matches)
                ))),
            ];
        }

        $match = array_values($matches)[0];

        return [
            'state' => 'ok',
            'market_space_id' => $match['id'],
            'candidate_ids' => [$match['id']],
            'matched_keys' => $match['keys'],
        ];
    }

    /**
     * @param iterable<int, object|array<string, mixed>> $spaces
     * @return Collection<int, array{id:int, keys:list<string>}>
     */
    private function normalizeSpaces(iterable $spaces): Collection
    {
        return collect($spaces)
            ->map(function ($space): ?array {
                $id = (int) $this->readValue($space, 'id');
                if ($id <= 0) {
                    return null;
                }

                $keys = collect([
                    $this->readValue($space, 'number'),
                    $this->readValue($space, 'code'),
                ])
                    ->filter(static fn ($value): bool => is_string($value) && trim($value) !== '')
                    ->map(fn (string $value): string => $this->normalizeText($value))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($keys === []) {
                    return null;
                }

                return [
                    'id' => $id,
                    'keys' => $keys,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Boundary-aware exact match of a space key inside a contract number.
     * This intentionally rejects prefix matches like "П/3" inside "П/3/1".
     */
    private function contractMentionsSpaceKey(string $normalizedContract, string $normalizedKey): bool
    {
        $pattern = $this->buildKeyPattern($normalizedKey);

        return preg_match($pattern, $normalizedContract) === 1;
    }

    private function buildKeyPattern(string $normalizedKey): string
    {
        $parts = [];

        $length = mb_strlen($normalizedKey, 'UTF-8');
        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($normalizedKey, $i, 1, 'UTF-8');

            if ($char === ' ') {
                $parts[] = '\s*';
                continue;
            }

            if ($char === '/' || $char === '-') {
                $parts[] = '[-\/\s]*';
                continue;
            }

            $parts[] = preg_quote($char, '/');
        }

        return '/(?<![\p{L}\p{N}\/-])' . implode('', $parts) . '(?![\p{L}\p{N}\/-])/u';
    }

    private function normalizeText(string $raw): string
    {
        $value = trim($raw);
        if ($value === '') {
            return '';
        }

        $value = mb_strtoupper($value, 'UTF-8');
        $value = str_replace(['\\', '／'], '/', $value);
        $value = str_replace(['–', '—'], '-', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('#/+#', '/', $value) ?? $value;
        $value = preg_replace('/-+/u', '-', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param object|array<string, mixed> $source
     */
    private function readValue(object|array $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return $source->{$key} ?? null;
    }
}
