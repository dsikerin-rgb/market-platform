<?php
# app/Services/Tenants/TenantDuplicateSignalService.php

declare(strict_types=1);

namespace App\Services\Tenants;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantDuplicateSignalService
{
    private const DEFAULT_LIMIT = 12;
    private const MIN_SIGNAL_SCORE = 72;

    /**
     * @return list<array<string, mixed>>
     */
    public function signalsForMarket(int $marketId, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($marketId <= 0 || $limit <= 0) {
            return [];
        }

        $tenants = Tenant::query()
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'market_id', 'name', 'short_name', 'external_id', 'one_c_uid', 'inn', 'kpp', 'is_active'])
            ->map(fn (Tenant $tenant): array => $this->tenantSnapshot($tenant))
            ->all();

        if (count($tenants) < 2) {
            return [];
        }

        $aliases = $this->aliasesForMarket($marketId);
        $ignoredPairs = $this->ignoredPairKeysForMarket($marketId);
        $signals = [];

        $count = count($tenants);
        for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
                if (isset($ignoredPairs[$this->pairKey((int) $tenants[$leftIndex]['id'], (int) $tenants[$rightIndex]['id'])])) {
                    continue;
                }

                $signal = $this->buildSignal($tenants[$leftIndex], $tenants[$rightIndex], $aliases);

                if ($signal !== null) {
                    $signals[] = $signal;
                }
            }
        }

        usort($signals, static function (array $left, array $right): int {
            return ((int) ($right['score'] ?? 0) <=> (int) ($left['score'] ?? 0))
                ?: ((int) data_get($left, 'candidate_a.id', 0) <=> (int) data_get($right, 'candidate_a.id', 0))
                ?: ((int) data_get($left, 'candidate_b.id', 0) <=> (int) data_get($right, 'candidate_b.id', 0));
        });

        return array_map(
            fn (array $signal): array => $this->withCandidateBusinessSummaries($signal, $marketId),
            array_slice($signals, 0, $limit),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tenantSnapshot(Tenant $tenant): array
    {
        $name = trim((string) ($tenant->name ?? ''));
        $shortName = trim((string) ($tenant->short_name ?? ''));
        $tokens = $this->nameTokens($name . ' ' . $shortName);

        return [
            'id' => (int) $tenant->id,
            'market_id' => (int) $tenant->market_id,
            'name' => $name,
            'short_name' => $shortName,
            'external_id' => trim((string) ($tenant->external_id ?? '')),
            'one_c_uid' => trim((string) ($tenant->one_c_uid ?? '')),
            'inn' => preg_replace('/\D+/u', '', (string) ($tenant->inn ?? '')) ?? '',
            'kpp' => preg_replace('/\D+/u', '', (string) ($tenant->kpp ?? '')) ?? '',
            'tokens' => $tokens,
            'token_count' => count($tokens),
            'identity_rank' => $this->identityRank($tenant, $tokens),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<string, int>  $aliases
     * @return array<string, mixed>|null
     */
    private function buildSignal(array $left, array $right, array $aliases): ?array
    {
        $score = 0;
        $reasons = [];
        $technicalReasons = [];

        $leftInn = (string) ($left['inn'] ?? '');
        $rightInn = (string) ($right['inn'] ?? '');

        if ($leftInn !== '' && $leftInn === $rightInn) {
            $score += 96;
            $reasons[] = 'Совпадает ИНН';
            $technicalReasons[] = 'same_inn';
        }

        foreach ($this->identityValues($left) as $identity) {
            $canonicalTenantId = $aliases[$identity['key']] ?? 0;

            if ($canonicalTenantId > 0 && $canonicalTenantId === (int) $right['id']) {
                $score += 98;
                $reasons[] = 'Одна карточка уже была объединена с другой';
                $technicalReasons[] = 'left_identity_is_right_alias';
            }
        }

        foreach ($this->identityValues($right) as $identity) {
            $canonicalTenantId = $aliases[$identity['key']] ?? 0;

            if ($canonicalTenantId > 0 && $canonicalTenantId === (int) $left['id']) {
                $score += 98;
                $reasons[] = 'Одна карточка уже была объединена с другой';
                $technicalReasons[] = 'right_identity_is_left_alias';
            }
        }

        $nameScore = $this->nameSimilarityScore($left, $right);
        if ($nameScore > 0) {
            $score += $nameScore;
            $reasons[] = $this->nameSimilarityReason($left, $right, $nameScore);
            $technicalReasons[] = 'similar_normalized_name';
        }

        if ($score < self::MIN_SIGNAL_SCORE) {
            return null;
        }

        $ordered = $this->orderCandidates($left, $right);

        return [
            'type' => 'tenant_identity_resolution',
            'title' => 'Возможный дубль арендатора',
            'severity' => $score >= 90 ? 'high' : 'medium',
            'score' => min(100, $score),
            'reasons' => array_values(array_unique($reasons)),
            'technical_reasons' => array_values(array_unique($technicalReasons)),
            'candidate_a' => $this->publicTenantSummary($ordered[0]),
            'candidate_b' => $this->publicTenantSummary($ordered[1]),
            'recommendation' => 'Откройте обе карточки и проверьте ИНН, договоры, начисления и торговые места. Если это один арендатор — нажмите «Подготовить слияние».',
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function nameSimilarityScore(array $left, array $right): int
    {
        $leftTokens = (array) ($left['tokens'] ?? []);
        $rightTokens = (array) ($right['tokens'] ?? []);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0;
        }

        $intersection = array_values(array_intersect($leftTokens, $rightTokens));
        $intersectionCount = count($intersection);
        $minTokenCount = min(count($leftTokens), count($rightTokens));
        $maxTokenCount = max(count($leftTokens), count($rightTokens));

        if ($intersectionCount === 0 || $minTokenCount === 0) {
            return 0;
        }

        $containment = $intersectionCount / $minTokenCount;
        $jaccard = $intersectionCount / max(1, count(array_unique(array_merge($leftTokens, $rightTokens))));

        if ($containment >= 1.0 && $minTokenCount <= 2 && $maxTokenCount >= 2) {
            return 82;
        }

        if ($containment >= 0.75 && $jaccard >= 0.5) {
            return 76;
        }

        if ($containment >= 0.66 && $intersectionCount >= 2) {
            return 72;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function nameSimilarityReason(array $left, array $right, int $score): string
    {
        $minTokenCount = min((int) ($left['token_count'] ?? 0), (int) ($right['token_count'] ?? 0));

        if ($score >= 82 && $minTokenCount <= 2) {
            return 'Короткое название похоже на сокращение полного имени';
        }

        return 'Похожие названия';
    }

    /**
     * @param  array<string, mixed>  $tenant
     * @return list<array{key:string,type:string,value:string}>
     */
    private function identityValues(array $tenant): array
    {
        $values = [];

        foreach (['external_id', 'one_c_uid', 'inn'] as $type) {
            $value = trim((string) ($tenant[$type] ?? ''));

            if ($value === '') {
                continue;
            }

            $values[] = [
                'key' => $type . ':' . $value,
                'type' => $type,
                'value' => $value,
            ];
        }

        return $values;
    }

    /**
     * @return array<string, int>
     */
    private function aliasesForMarket(int $marketId): array
    {
        if (! Schema::hasTable('tenant_external_aliases')) {
            return [];
        }

        $rows = DB::table('tenant_external_aliases')
            ->where('market_id', $marketId)
            ->get(['alias_type', 'alias_value', 'canonical_tenant_id']);

        $aliases = [];
        foreach ($rows as $row) {
            $type = trim((string) ($row->alias_type ?? ''));
            $value = trim((string) ($row->alias_value ?? ''));
            $canonicalTenantId = (int) ($row->canonical_tenant_id ?? 0);

            if ($type === '' || $value === '' || $canonicalTenantId <= 0) {
                continue;
            }

            $aliases[$type . ':' . $value] = $canonicalTenantId;
        }

        return $aliases;
    }

    /**
     * @return array<string, true>
     */
    private function ignoredPairKeysForMarket(int $marketId): array
    {
        if (! Schema::hasTable('tenant_duplicate_ignores')) {
            return [];
        }

        $rows = DB::table('tenant_duplicate_ignores')
            ->where('market_id', $marketId)
            ->get(['tenant_left_id', 'tenant_right_id']);

        $ignored = [];
        foreach ($rows as $row) {
            $ignored[$this->pairKey((int) $row->tenant_left_id, (int) $row->tenant_right_id)] = true;
        }

        return $ignored;
    }

    private function pairKey(int $leftTenantId, int $rightTenantId): string
    {
        $left = min($leftTenantId, $rightTenantId);
        $right = max($leftTenantId, $rightTenantId);

        return $left . ':' . $right;
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $value): array
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;

        $legalNoise = [
            'ип',
            'ооо',
            'оао',
            'ао',
            'пао',
            'зао',
            'нао',
            'индивидуальный',
            'предприниматель',
            'общество',
            'ограниченной',
            'ответственностью',
            'самозанятый',
            'самозанятая',
        ];

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => mb_strlen($token, 'UTF-8') >= 3
                && ! in_array($token, $legalNoise, true)
        ));

        return array_values(array_unique($tokens));
    }

    /**
     * @param  list<string>  $tokens
     */
    private function identityRank(Tenant $tenant, array $tokens): int
    {
        $rank = count($tokens);

        if (filled($tenant->inn)) {
            $rank += 30;
        }

        if ($this->looksLikeUuid((string) ($tenant->external_id ?? '')) || $this->looksLikeUuid((string) ($tenant->one_c_uid ?? ''))) {
            $rank += 20;
        }

        $rank += min(20, (int) floor(mb_strlen((string) ($tenant->name ?? ''), 'UTF-8') / 8));

        return $rank;
    }

    private function looksLikeUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($value)) === 1;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function orderCandidates(array $left, array $right): array
    {
        if ((int) ($right['identity_rank'] ?? 0) > (int) ($left['identity_rank'] ?? 0)) {
            return [$right, $left];
        }

        return [$left, $right];
    }

    /**
     * @param  array<string, mixed>  $tenant
     * @return array<string, mixed>
     */
    private function publicTenantSummary(array $tenant): array
    {
        return [
            'id' => (int) ($tenant['id'] ?? 0),
            'name' => (string) ($tenant['name'] ?? ''),
            'short_name' => (string) ($tenant['short_name'] ?? ''),
            'external_id' => (string) ($tenant['external_id'] ?? ''),
            'one_c_uid' => (string) ($tenant['one_c_uid'] ?? ''),
            'inn' => (string) ($tenant['inn'] ?? ''),
            'kpp' => (string) ($tenant['kpp'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $signal
     * @return array<string, mixed>
     */
    private function withCandidateBusinessSummaries(array $signal, int $marketId): array
    {
        foreach (['candidate_a', 'candidate_b'] as $key) {
            $candidate = is_array($signal[$key] ?? null) ? $signal[$key] : [];
            $tenantId = (int) ($candidate['id'] ?? 0);
            $candidate['summary'] = $this->tenantBusinessSummary($marketId, $tenantId);
            $signal[$key] = $candidate;
        }

        return $signal;
    }

    /**
     * @return array{
     *     contracts: array{total:int,active:int,sample:list<string>},
     *     accruals: array{rows:int,latest_period:?string,total_with_vat:float},
     *     spaces: array{total:int,sample:list<string>},
     *     users: array{total:int}
     * }
     */
    private function tenantBusinessSummary(int $marketId, int $tenantId): array
    {
        return [
            'contracts' => $this->tenantContractsSummary($marketId, $tenantId),
            'accruals' => $this->tenantAccrualsSummary($marketId, $tenantId),
            'spaces' => $this->tenantSpacesSummary($marketId, $tenantId),
            'users' => $this->tenantUsersSummary($tenantId),
        ];
    }

    /**
     * @return array{total:int,active:int,sample:list<string>}
     */
    private function tenantContractsSummary(int $marketId, int $tenantId): array
    {
        if ($marketId <= 0 || $tenantId <= 0 || ! Schema::hasTable('tenant_contracts')) {
            return ['total' => 0, 'active' => 0, 'sample' => []];
        }

        $query = DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId);

        return [
            'total' => (int) (clone $query)->count(),
            'active' => (int) (clone $query)->where('is_active', true)->count(),
            'sample' => (clone $query)
                ->orderByDesc('is_active')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(2)
                ->pluck('number')
                ->map(fn (mixed $number): string => trim((string) $number))
                ->filter()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{rows:int,latest_period:?string,total_with_vat:float}
     */
    private function tenantAccrualsSummary(int $marketId, int $tenantId): array
    {
        if ($marketId <= 0 || $tenantId <= 0 || ! Schema::hasTable('tenant_accruals')) {
            return ['rows' => 0, 'latest_period' => null, 'total_with_vat' => 0.0];
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId);

        return [
            'rows' => (int) (clone $query)->count(),
            'latest_period' => (clone $query)->max('period'),
            'total_with_vat' => (float) (clone $query)->sum('total_with_vat'),
        ];
    }

    /**
     * @return array{total:int,sample:list<string>}
     */
    private function tenantSpacesSummary(int $marketId, int $tenantId): array
    {
        if ($marketId <= 0 || $tenantId <= 0 || ! Schema::hasTable('market_spaces')) {
            return ['total' => 0, 'sample' => []];
        }

        $spaceIds = [];

        foreach ($this->tenantSpaceIdsFromMarketSpaces($marketId, $tenantId) as $spaceId) {
            $spaceIds[$spaceId] = true;
        }

        foreach ($this->tenantSpaceIdsFromContracts($marketId, $tenantId) as $spaceId) {
            $spaceIds[$spaceId] = true;
        }

        foreach ($this->tenantSpaceIdsFromAccruals($marketId, $tenantId) as $spaceId) {
            $spaceIds[$spaceId] = true;
        }

        $ids = array_keys($spaceIds);

        if ($ids === []) {
            return ['total' => 0, 'sample' => []];
        }

        $sample = DB::table('market_spaces')
            ->where('market_id', $marketId)
            ->whereIn('id', $ids)
            ->orderBy('number')
            ->orderBy('code')
            ->orderBy('id')
            ->limit(3)
            ->get(['number', 'code', 'id'])
            ->map(function (object $row): string {
                $label = trim((string) ($row->number ?? ''));

                if ($label === '') {
                    $label = trim((string) ($row->code ?? ''));
                }

                return $label !== '' ? $label : '#' . (int) $row->id;
            })
            ->values()
            ->all();

        return [
            'total' => count($ids),
            'sample' => $sample,
        ];
    }

    /**
     * @return list<int>
     */
    private function tenantSpaceIdsFromMarketSpaces(int $marketId, int $tenantId): array
    {
        if (! Schema::hasColumn('market_spaces', 'tenant_id')) {
            return [];
        }

        return DB::table('market_spaces')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function tenantSpaceIdsFromContracts(int $marketId, int $tenantId): array
    {
        if (! Schema::hasTable('tenant_contracts') || ! Schema::hasColumn('tenant_contracts', 'market_space_id')) {
            return [];
        }

        return DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('market_space_id')
            ->pluck('market_space_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function tenantSpaceIdsFromAccruals(int $marketId, int $tenantId): array
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'market_space_id')) {
            return [];
        }

        return DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('market_space_id')
            ->pluck('market_space_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @return array{total:int}
     */
    private function tenantUsersSummary(int $tenantId): array
    {
        if ($tenantId <= 0 || ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'tenant_id')) {
            return ['total' => 0];
        }

        return [
            'total' => (int) DB::table('users')->where('tenant_id', $tenantId)->count(),
        ];
    }
}
