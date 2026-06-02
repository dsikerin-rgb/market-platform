<?php

declare(strict_types=1);

namespace App\Services\MarketSpaces;

use App\Models\MarketSpace;

class MarketSpaceDuplicateSignalService
{
    private const DEFAULT_LIMIT = 12;

    /**
     * @return list<array<string, mixed>>
     */
    public function signalsForMarket(int $marketId, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($marketId <= 0 || $limit <= 0) {
            return [];
        }

        $spaces = MarketSpace::query()
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get([
                'id',
                'market_id',
                'tenant_id',
                'number',
                'display_name',
                'status',
                'is_active',
                'space_group_role',
                'space_group_parent_id',
            ]);

        if ($spaces->count() < 2) {
            return [];
        }

        $groups = $spaces
            ->map(function (MarketSpace $space): array {
                return [
                    'id' => (int) $space->id,
                    'number' => trim((string) ($space->number ?? '')),
                    'display_name' => trim((string) ($space->display_name ?? '')),
                    'tenant_id' => $space->tenant_id !== null ? (int) $space->tenant_id : null,
                    'status' => (string) ($space->status ?? ''),
                    'space_group_role' => (string) ($space->space_group_role ?? ''),
                    'space_group_parent_id' => $space->space_group_parent_id !== null ? (int) $space->space_group_parent_id : null,
                    'normalized_number' => $this->normalizeNumber((string) ($space->number ?? '')),
                ];
            })
            ->filter(fn (array $space): bool => $space['normalized_number'] !== '')
            ->groupBy('normalized_number')
            ->filter(fn ($group): bool => $group->count() > 1)
            ->map(function ($group, string $normalizedNumber): array {
                $spaces = $group->sortBy('id')->values()->all();

                return [
                    'type' => 'market_space_duplicate_number',
                    'title' => 'Возможные дубли торговых мест',
                    'severity' => $this->severityForGroup($spaces),
                    'normalized_number' => $normalizedNumber,
                    'count' => count($spaces),
                    'reasons' => $this->reasonsForGroup($spaces),
                    'spaces' => $spaces,
                    'recommendation' => $this->recommendationForGroup($spaces),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];

                return (($severityOrder[$left['severity']] ?? 9) <=> ($severityOrder[$right['severity']] ?? 9))
                    ?: ($right['count'] <=> $left['count'])
                    ?: ($left['normalized_number'] <=> $right['normalized_number']);
            })
            ->values()
            ->take($limit)
            ->all();

        return array_values($groups);
    }

    private function normalizeNumber(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $spaces
     * @return 'high'|'medium'|'low'
     */
    private function severityForGroup(array $spaces): string
    {
        $distinctTenantIds = collect($spaces)
            ->pluck('tenant_id')
            ->filter(fn ($tenantId): bool => $tenantId !== null)
            ->unique()
            ->values();

        if ($distinctTenantIds->count() > 1) {
            return 'high';
        }

        $roles = collect($spaces)
            ->pluck('space_group_role')
            ->filter(fn ($role): bool => trim((string) $role) !== '')
            ->unique()
            ->values();

        if ($roles->contains(MarketSpace::SPACE_GROUP_ROLE_CHILD) || $roles->contains(MarketSpace::SPACE_GROUP_ROLE_PARENT)) {
            return 'medium';
        }

        return 'medium';
    }

    /**
     * @param  list<array<string, mixed>>  $spaces
     * @return list<string>
     */
    private function reasonsForGroup(array $spaces): array
    {
        $reasons = ['Совпадает нормализованный номер места'];

        $distinctTenantIds = collect($spaces)
            ->pluck('tenant_id')
            ->filter(fn ($tenantId): bool => $tenantId !== null)
            ->unique()
            ->values();

        if ($distinctTenantIds->count() > 1) {
            $reasons[] = 'К одному номеру места привязаны разные арендаторы';
        }

        $childCount = collect($spaces)
            ->filter(fn (array $space): bool => ($space['space_group_role'] ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD)
            ->count();

        if ($childCount > 0) {
            $reasons[] = 'Как минимум один дубль всё ещё помечен как дочернее место';
        }

        return $reasons;
    }

    /**
     * @param  list<array<string, mixed>>  $spaces
     */
    private function recommendationForGroup(array $spaces): string
    {
        $hasChild = collect($spaces)
            ->contains(fn (array $space): bool => ($space['space_group_role'] ?? '') === MarketSpace::SPACE_GROUP_ROLE_CHILD);

        if ($hasChild) {
            return 'Проверьте, нужно ли разгруппировать дочернее место или вывести дубль из использования в пользу основного места.';
        }

        return 'Откройте обе карточки места, сравните арендаторов, договоры, начисления и привязки на карте, затем решите, нужно ли объединение или раздельное ведение.';
    }
}
