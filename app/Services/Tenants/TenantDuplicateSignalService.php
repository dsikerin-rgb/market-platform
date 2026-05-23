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
        $signals = [];

        $count = count($tenants);
        for ($leftIndex = 0; $leftIndex < $count; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $count; $rightIndex++) {
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

        return array_slice($signals, 0, $limit);
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
}
