<?php

declare(strict_types=1);

namespace App\Services\MarketSpaces;

final class SpaceGroupResolver
{
    /**
     * @param  array<string, mixed>  $classification
     * @return array{
     *   is_composite: bool,
     *   group_token: ?string,
     *   group_segments: ?string
     * }
     */
    public function forContractClassification(array $classification): array
    {
        $parentToken = $this->normalizeGroupToken($classification['parent_place_token'] ?? null);
        $segments = $this->normalizeGroupSlot($classification['place_segments'] ?? null);

        if ($parentToken !== null && $segments !== null) {
            return [
                'is_composite' => true,
                'group_token' => $parentToken,
                'group_segments' => $segments,
            ];
        }

        $placeToken = trim((string) ($classification['place_token'] ?? ''));
        if ($placeToken === '') {
            return [
                'is_composite' => false,
                'group_token' => null,
                'group_segments' => null,
            ];
        }

        if (preg_match('/^(ОС)\s*[-\/]?\s*(\d+)[\/ ](\d{1,2}(?:\s*[-,]\s*\d{1,2})+)$/u', $placeToken, $matches) === 1) {
            return [
                'is_composite' => true,
                'group_token' => $this->normalizeGroupToken($matches[1] . $matches[2]),
                'group_segments' => $this->normalizeGroupSlot($matches[3]),
            ];
        }

        return [
            'is_composite' => false,
            'group_token' => null,
            'group_segments' => null,
        ];
    }

    public function normalizeGroupToken(mixed $value): ?string
    {
        $token = trim((string) $value);
        if ($token === '') {
            return null;
        }

        $token = mb_strtoupper($token, 'UTF-8');
        $token = preg_replace('/\s+/u', '', $token) ?? $token;
        $token = str_replace(['-', '/'], '', $token);

        return $token !== '' ? $token : null;
    }

    public function normalizeGroupSlot(mixed $value): ?string
    {
        $slot = trim((string) $value);
        if ($slot === '') {
            return null;
        }

        $slot = preg_replace('/\s*([,-])\s*/u', '$1', $slot) ?? $slot;
        $slot = preg_replace('/\s+/u', ' ', $slot) ?? $slot;

        return $slot !== '' ? $slot : null;
    }
}
