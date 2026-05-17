<?php
# app/Services/MarketMap/DuplicateSpaceResolutionService.php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Models\MarketSpace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class DuplicateSpaceResolutionService
{
    /**
     * @var list<array{table: string, column: string, label: string, blocking: bool}>
     */
    private const LINK_DEFINITIONS = [
        // Р‘Р»РѕРєРёСЂСѓСЋС‰РёРµ СЃРІСЏР·Рё вЂ” С‚СЂРµР±СѓСЋС‚ СЂСѓС‡РЅРѕРіРѕ СЂР°Р·СЂРµС€РµРЅРёСЏ
        ['table' => 'tenant_contracts', 'column' => 'market_space_id', 'label' => 'contracts', 'blocking' => true],
        ['table' => 'market_space_tenant_bindings', 'column' => 'market_space_id', 'label' => 'tenant_bindings', 'blocking' => true],
        ['table' => 'tenant_requests', 'column' => 'market_space_id', 'label' => 'requests', 'blocking' => true],
        ['table' => 'tickets', 'column' => 'market_space_id', 'label' => 'tickets', 'blocking' => true],
        ['table' => 'tenant_reviews', 'column' => 'market_space_id', 'label' => 'reviews', 'blocking' => true],
        ['table' => 'tenant_space_showcases', 'column' => 'market_space_id', 'label' => 'showcases', 'blocking' => true],
        ['table' => 'marketplace_chats', 'column' => 'market_space_id', 'label' => 'marketplace_chats', 'blocking' => true],

        // РќР°С‡РёСЃР»РµРЅРёСЏ вЂ” С‚СЂРµР±СѓСЋС‚ РєР»Р°СЃСЃРёС„РёРєР°С†РёРё (blocking vs historical tail)
        ['table' => 'tenant_accruals', 'column' => 'market_space_id', 'label' => 'accruals', 'blocking' => false],
    ];

    /**
     * @return array{
     *     duplicate_market_space_id: int,
     *     canonical_market_space_id: int,
     *     transfer_counts: array<string, int>,
     *     blocking_counts: array<string, int>,
     *     classification: string,
     *     accrual_classification: array{
     *         blocking_accruals: int,
     *         historical_tail_accruals: int,
     *         duplicate_latest_accrual_period: ?string,
     *         canonical_latest_accrual_period: ?string,
     *         has_linked_contract_accruals: bool
     *     },
     *     retained_financial_tail?: array{
     *         accruals_count: int,
     *         earliest_period: ?string,
     *         latest_period: ?string,
     *         unmatched_only: bool
     *     }
     * }
     */
    public function preview(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId): array
    {
        [$duplicate, $canonical] = $this->loadSpaces($marketId, $duplicateSpaceId, $canonicalSpaceId, false);
        $this->validatePair($duplicate, $canonical, $duplicateSpaceId, $canonicalSpaceId);
        $this->validateCanonicalAnchors($marketId, $duplicateSpaceId, $canonicalSpaceId);

        $linkClassification = $this->classifyLinks($marketId, $duplicateSpaceId, $canonicalSpaceId);
        $this->throwIfClassifiedAsBlocked($linkClassification);

        $classification = $this->classifyDuplicateCase($linkClassification);
        $this->throwIfClassificationIsAmbiguous($classification);

        $result = [
            'duplicate_market_space_id' => $duplicateSpaceId,
            'canonical_market_space_id' => $canonicalSpaceId,
            'transfer_counts' => $this->transferPreviewCounts($marketId, $duplicateSpaceId),
            'blocking_counts' => $linkClassification['blocking_counts'],
            'classification' => $classification,
            'accrual_classification' => $linkClassification['accrual_classification'],
        ];

        // Р”РѕР±Р°РІР»СЏРµРј retained_financial_tail РґР»СЏ historical tail case
        if ($classification === 'duplicate_with_historical_financial_tail') {
            $result['retained_financial_tail'] = $this->buildRetainedFinancialTailSummary($linkClassification);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, ?int $userId = null): array
    {
        return DB::transaction(function () use ($marketId, $duplicateSpaceId, $canonicalSpaceId, $userId): array {
            [$duplicate, $canonical] = $this->loadSpaces($marketId, $duplicateSpaceId, $canonicalSpaceId, true);
            $this->validatePair($duplicate, $canonical, $duplicateSpaceId, $canonicalSpaceId);
            $this->validateCanonicalAnchors($marketId, $duplicateSpaceId, $canonicalSpaceId);

            $linkClassification = $this->classifyLinks($marketId, $duplicateSpaceId, $canonicalSpaceId);
            $this->throwIfClassifiedAsBlocked($linkClassification);

            $classification = $this->classifyDuplicateCase($linkClassification);
            $this->throwIfClassificationIsAmbiguous($classification);

            $now = now();
            $shapeSummary = $this->transferMapShapes($marketId, $duplicateSpaceId, $canonicalSpaceId, $now);
            $cabinetSummary = $this->transferCabinetLinks($duplicateSpaceId, $canonicalSpaceId, $now);
            $productsMoved = $this->transferMarketplaceProducts($marketId, $duplicateSpaceId, $canonicalSpaceId, $now);

            $spaceUpdate = [
                'is_active' => false,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('market_spaces', 'map_review_status')) {
                $spaceUpdate['map_review_status'] = 'changed';
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_at')) {
                $spaceUpdate['map_reviewed_at'] = $now;
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_by')) {
                $spaceUpdate['map_reviewed_by'] = $userId;
            }

            DB::table('market_spaces')
                ->where('market_id', $marketId)
                ->where('id', $duplicateSpaceId)
                ->update($spaceUpdate);

            $classification = $this->classifyDuplicateCase($linkClassification);

            $result = [
                'duplicate_market_space_id' => $duplicateSpaceId,
                'canonical_market_space_id' => $canonicalSpaceId,
                'transferred' => [
                    'map_shapes' => $shapeSummary['moved'],
                    'detached_conflicting_shapes' => $shapeSummary['detached_conflicts'],
                    'cabinet_links' => $cabinetSummary['moved'],
                    'merged_cabinet_links' => $cabinetSummary['merged'],
                    'marketplace_products' => $productsMoved,
                ],
                'blocking_counts' => $linkClassification['blocking_counts'],
                'classification' => $classification,
                'accrual_classification' => $linkClassification['accrual_classification'],
            ];

            // Р”РѕР±Р°РІР»СЏРµРј retained_financial_tail РґР»СЏ historical tail case
            if ($classification === 'duplicate_with_historical_financial_tail') {
                $result['retained_financial_tail'] = $this->buildRetainedFinancialTailSummary($linkClassification);
            }

            return $result;
        });
    }

    /**
     * @return array{0: ?MarketSpace, 1: ?MarketSpace}
     */
    private function loadSpaces(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, bool $lock): array
    {
        $query = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereIn('id', [$duplicateSpaceId, $canonicalSpaceId]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $spaces = $query
            ->get(['id', 'market_id', 'tenant_id', 'is_active'])
            ->keyBy('id');

        return [
            $spaces->get($duplicateSpaceId),
            $spaces->get($canonicalSpaceId),
        ];
    }

    private function validatePair(?MarketSpace $duplicate, ?MarketSpace $canonical, int $duplicateSpaceId, int $canonicalSpaceId): void
    {
        if ($duplicateSpaceId === $canonicalSpaceId) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space must differ from duplicate space.',
            ]);
        }

        if (! $duplicate) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space was not found in the current market.',
            ]);
        }

        if (! $canonical) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space was not found in the current market.',
            ]);
        }

        if (! (bool) ($canonical->is_active ?? true)) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space must be active.',
            ]);
        }

        $duplicateTenantId = (int) ($duplicate->tenant_id ?? 0);
        $canonicalTenantId = (int) ($canonical->tenant_id ?? 0);

        if ($duplicateTenantId > 0 && $canonicalTenantId > 0 && $duplicateTenantId !== $canonicalTenantId) {
            throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Duplicate and canonical spaces belong to different tenants.',
            ]);
        }
    }

    private function validateCanonicalAnchors(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId): void
    {
        $duplicateAnchors = $this->canonicalAnchorCounts($marketId, $duplicateSpaceId);
        $canonicalAnchors = $this->canonicalAnchorCounts($marketId, $canonicalSpaceId);

        $duplicateHasHardAnchor = $duplicateAnchors['map_shapes'] > 0 || $duplicateAnchors['contracts'] > 0;
        $canonicalHasHardAnchor = $canonicalAnchors['map_shapes'] > 0 || $canonicalAnchors['contracts'] > 0;

        if (! $duplicateHasHardAnchor || $canonicalHasHardAnchor) {
            return;
        }

        throw ValidationException::withMessages([
            'candidate_market_space_id' => 'РћСЃРЅРѕРІРЅРѕРµ РјРµСЃС‚Рѕ РЅРµ РјРѕР¶РµС‚ Р±С‹С‚СЊ РІС‹Р±СЂР°РЅРѕ С‚РѕР»СЊРєРѕ РїРѕ С„РёРЅР°РЅСЃРѕРІС‹Рј СЃРІСЏР·СЏРј: Сѓ РЅРµРіРѕ РЅРµС‚ С„РёРіСѓСЂС‹ РЅР° РєР°СЂС‚Рµ Рё РґРѕРіРѕРІРѕСЂРѕРІ, Р° Сѓ С‚РµРєСѓС‰РµРіРѕ РјРµСЃС‚Р° РѕРЅРё РµСЃС‚СЊ.',
        ]);
    }

    /**
     * @return array{map_shapes: int, contracts: int}
     */
    private function canonicalAnchorCounts(int $marketId, int $spaceId): array
    {
        return [
            'map_shapes' => $this->countRows('market_space_map_shapes', 'market_space_id', $spaceId, $marketId),
            'contracts' => $this->countRows('tenant_contracts', 'market_space_id', $spaceId, $marketId),
        ];
    }

    /**
     * РљР»Р°СЃСЃРёС„РёС†РёСЂСѓРµС‚ СЃРІСЏР·Рё РЅР° РґСѓР±Р»Рµ: Р±Р»РѕРєРёСЂСѓСЋС‰РёРµ vs Р±РµР·РѕРїР°СЃРЅС‹Рµ vs historical tail
     *
     * @return array{
     *     blocking_counts: array<string, int>,
     *     transfer_counts: array<string, int>,
     *     accrual_classification: array{
     *         blocking_accruals: int,
     *         historical_tail_accruals: int,
     *         duplicate_latest_accrual_period: ?string,
     *         canonical_latest_accrual_period: ?string,
     *         has_linked_contract_accruals: bool
     *     }
     * }
     */
    private function classifyLinks(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId): array
    {
        $blockingCounts = [];

        foreach (self::LINK_DEFINITIONS as $link) {
            $count = $this->countRows($link['table'], $link['column'], $duplicateSpaceId, $marketId);

            if ($link['blocking']) {
                $blockingCounts[$link['label']] = $count;
            }
        }

        // РЎС‡РёС‚Р°РµРј Р±РµР·РѕРїР°СЃРЅС‹Рµ СЃРІСЏР·Рё РґР»СЏ РїРµСЂРµРЅРѕСЃР°
        $transferCounts = $this->transferPreviewCounts($marketId, $duplicateSpaceId);

        // РљР»Р°СЃСЃРёС„РёС†РёСЂСѓРµРј РЅР°С‡РёСЃР»РµРЅРёСЏ
        $accrualClassification = $this->classifyAccruals($marketId, $duplicateSpaceId, $canonicalSpaceId);

        return [
            'blocking_counts' => $blockingCounts,
            'transfer_counts' => $transferCounts,
            'accrual_classification' => $accrualClassification,
        ];
    }

    /**
     * РљР»Р°СЃСЃРёС„РёС†РёСЂСѓРµС‚ РЅР°С‡РёСЃР»РµРЅРёСЏ РЅР° РґСѓР±Р»Рµ: blocking vs historical tail
     *
     * @return array{
     *     blocking_accruals: int,
     *     historical_tail_accruals: int,
     *     duplicate_latest_accrual_period: ?string,
     *     canonical_latest_accrual_period: ?string,
     *     has_linked_contract_accruals: bool
     * }
     */
    private function classifyAccruals(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId): array
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'market_space_id')) {
            return [
                'blocking_accruals' => 0,
                'historical_tail_accruals' => 0,
                'duplicate_latest_accrual_period' => null,
                'canonical_latest_accrual_period' => null,
                'has_linked_contract_accruals' => false,
            ];
        }

        // РџРѕР»СѓС‡Р°РµРј latest accrual period canonical-РєР°СЂС‚РѕС‡РєРё
        $canonicalLatestPeriod = $this->getLatestAccrualPeriod($marketId, $canonicalSpaceId);

        // РџРѕР»СѓС‡Р°РµРј РІСЃРµ РЅР°С‡РёСЃР»РµРЅРёСЏ РґСѓР±Р»СЏ СЃ РёС… contract_id
        $duplicateAccrualsQuery = DB::table('tenant_accruals')
            ->select('period', 'tenant_contract_id')
            ->where('market_space_id', $duplicateSpaceId)
            ->where('market_id', $marketId);

        if (Schema::hasColumn('tenant_accruals', 'is_active')) {
            $duplicateAccrualsQuery->where('is_active', true);
        }

        $duplicateAccruals = $duplicateAccrualsQuery->get();

        if ($duplicateAccruals->isEmpty()) {
            return [
                'blocking_accruals' => 0,
                'historical_tail_accruals' => 0,
                'duplicate_latest_accrual_period' => null,
                'canonical_latest_accrual_period' => $canonicalLatestPeriod,
                'has_linked_contract_accruals' => false,
            ];
        }

        // Р‘Р»РѕРєРёСЂСѓРµРј СЃР»СѓС‡Р°Р№: duplicate РёРјРµРµС‚ accruals, РЅРѕ canonicalLatestPeriod === null
        // РўР°РєРёРµ accruals РЅРµ РјРѕРіСѓС‚ Р±С‹С‚СЊ historical tail
        if ($canonicalLatestPeriod === null) {
            return [
                'blocking_accruals' => $duplicateAccruals->count(),
                'historical_tail_accruals' => 0,
                'duplicate_latest_accrual_period' => $this->calculateLatestPeriod($duplicateAccruals),
                'canonical_latest_accrual_period' => null,
                'has_linked_contract_accruals' => $duplicateAccruals->some(fn($accrual) => (int) ($accrual->tenant_contract_id ?? 0) > 0),
            ];
        }

        $blockingCount = 0;
        $historicalTailCount = 0;
        $hasLinkedContract = false;
        $duplicateLatestPeriod = null;

        foreach ($duplicateAccruals as $accrual) {
            $accrualPeriod = $accrual->period;

            // РћР±РЅРѕРІР»СЏРµРј latest period
            if ($accrualPeriod !== null) {
                if ($duplicateLatestPeriod === null || $accrualPeriod > $duplicateLatestPeriod) {
                    $duplicateLatestPeriod = $accrualPeriod;
                }
            }

            // РџСЂРѕРІРµСЂРєР° РЅР° linked contract
            if ((int) ($accrual->tenant_contract_id ?? 0) > 0) {
                $hasLinkedContract = true;
                $blockingCount++;
                continue;
            }

            // РџСЂРѕРІРµСЂРєР° РЅР° fresh accrual (period >= canonical latest)
            if ($accrualPeriod !== null && $accrualPeriod >= $canonicalLatestPeriod) {
                $blockingCount++;
                continue;
            }

            // РСЃС‚РѕСЂРёС‡РµСЃРєРёР№ С…РІРѕСЃС‚: unmatched + СЃС‚Р°СЂРµРµ canonical latest
            $historicalTailCount++;
        }

        return [
            'blocking_accruals' => $blockingCount,
            'historical_tail_accruals' => $historicalTailCount,
            'duplicate_latest_accrual_period' => $duplicateLatestPeriod,
            'canonical_latest_accrual_period' => $canonicalLatestPeriod,
            'has_linked_contract_accruals' => $hasLinkedContract,
        ];
    }

    /**
     * Р’С‹С‡РёСЃР»СЏРµС‚ latest period РёР· РєРѕР»Р»РµРєС†РёРё accruals
     *
     * @param  \Illuminate\Support\Collection  $accruals
     */
    private function calculateLatestPeriod($accruals): ?string
    {
        $latest = null;
        foreach ($accruals as $accrual) {
            $period = $accrual->period;
            if ($period !== null) {
                if ($latest === null || $period > $latest) {
                    $latest = $period;
                }
            }
        }
        return $latest;
    }

    /**
     * РџРѕР»СѓС‡Р°РµС‚ latest accrual period РґР»СЏ РјРµСЃС‚Р°
     */
    private function getLatestAccrualPeriod(int $marketId, int $spaceId): ?string
    {
        if (! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'market_space_id')) {
            return null;
        }

        $query = DB::table('tenant_accruals')
            ->select('period')
            ->where('market_space_id', $spaceId)
            ->where('market_id', $marketId);

        if (Schema::hasColumn('tenant_accruals', 'is_active')) {
            $query->where('is_active', true);
        }

        $latest = $query->orderByDesc('period')->first();

        return $latest?->period ?? null;
    }

    /**
     * РћРїСЂРµРґРµР»СЏРµС‚ classification СЃР»СѓС‡Р°СЏ РґСѓР±Р»РёРєР°С‚Р°
     *
     * @param  array{blocking_counts: array<string, int>, accrual_classification: array{blocking_accruals: int, historical_tail_accruals: int, duplicate_latest_accrual_period: ?string, canonical_latest_accrual_period: ?string, has_linked_contract_accruals: bool}, transfer_counts: array<string, int>}  $linkClassification
     */
    private function classifyDuplicateCase(array $linkClassification): string
    {
        $blockingCounts = $linkClassification['blocking_counts'];
        $accrualClassification = $linkClassification['accrual_classification'];
        $transferCounts = $linkClassification['transfer_counts'];

        // РџСЂРѕРІРµСЂСЏРµРј Р±Р»РѕРєРёСЂСѓСЋС‰РёРµ СЃРІСЏР·Рё (РєСЂРѕРјРµ accruals)
        $blockingExceptAccruals = $blockingCounts;
        unset($blockingExceptAccruals['accruals']);
        $hasBlockingLinks = array_sum($blockingExceptAccruals) > 0;

        if ($hasBlockingLinks) {
            if (($blockingCounts['contracts'] ?? 0) > 0) {
                return 'duplicate_with_blocking_contracts';
            }
            return 'duplicate_with_blocking_contracts'; // Р”СЂСѓРіРёРµ blocking СЃРІСЏР·Рё С‚РѕР¶Рµ СЃС‡РёС‚Р°РµРј РєР°Рє contracts РґР»СЏ РїСЂРѕСЃС‚РѕС‚С‹
        }

        // РџСЂРѕРІРµСЂСЏРµРј blocking accruals
        if ($accrualClassification['blocking_accruals'] > 0) {
            if ($accrualClassification['has_linked_contract_accruals']) {
                return 'duplicate_with_blocking_accruals';
            }
            return 'duplicate_fresh_accruals_conflict';
        }

        // РџСЂРѕРІРµСЂСЏРµРј historical tail
        if ($accrualClassification['historical_tail_accruals'] > 0) {
            // Historical tail СЂР°Р·СЂРµС€С‘РЅ С‚РѕР»СЊРєРѕ РµСЃР»Рё РµСЃС‚СЊ Р±РµР·РѕРїР°СЃРЅС‹Рµ СЃРІСЏР·Рё РґР»СЏ РїРµСЂРµРЅРѕСЃР°
            $hasSafeLinks = array_sum($transferCounts) > 0;
            if ($hasSafeLinks) {
                return 'duplicate_with_historical_financial_tail';
            }
            // РќРµС‚ Р±РµР·РѕРїР°СЃРЅС‹С… СЃРІСЏР·РµР№ вЂ” ambiguous case
            return 'ambiguous_canonical_candidate';
        }

        // РќРµС‚ С„РёРЅР°РЅСЃРѕРІ РІРѕРѕР±С‰Рµ
        return 'safe_duplicate_no_financials';
    }

    /**
     * РЎС‚СЂРѕРёС‚ summary РґР»СЏ retained financial tail
     *
     * @param  array{accrual_classification: array{historical_tail_accruals: int, duplicate_latest_accrual_period: ?string}}  $linkClassification
     */
    private function buildRetainedFinancialTailSummary(array $linkClassification): array
    {
        $accrualClassification = $linkClassification['accrual_classification'];

        return [
            'accruals_count' => $accrualClassification['historical_tail_accruals'],
            'earliest_period' => null, // РњРѕР¶РЅРѕ РґРѕР±Р°РІРёС‚СЊ РµСЃР»Рё РЅСѓР¶РЅРѕ
            'latest_period' => $accrualClassification['duplicate_latest_accrual_period'],
            'unmatched_only' => true,
        ];
    }

    /**
     * @param  array{blocking_counts: array<string, int>, accrual_classification: array{blocking_accruals: int, has_linked_contract_accruals: bool, duplicate_latest_accrual_period: ?string, canonical_latest_accrual_period: ?string}}  $linkClassification
     */
    private function throwIfClassifiedAsBlocked(array $linkClassification): void
    {
        $blockingCounts = $linkClassification['blocking_counts'];
        $accrualClassification = $linkClassification['accrual_classification'];

        // РџСЂРѕРІРµСЂСЏРµРј blocking contracts
        if (($blockingCounts['contracts'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space has active contracts: ' . ($blockingCounts['contracts']) . '.',
            ]);
        }

        // РџСЂРѕРІРµСЂСЏРµРј РґСЂСѓРіРёРµ blocking СЃРІСЏР·Рё
        $blockingExceptAccruals = $blockingCounts;
        unset($blockingExceptAccruals['accruals']);
        $nonZeroBlocking = array_filter($blockingExceptAccruals, static fn (int $count): bool => $count > 0);

        if ($nonZeroBlocking !== []) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space has blocking business links: ' . implode(', ', array_keys($nonZeroBlocking)) . '.',
            ]);
        }

        // РџСЂРѕРІРµСЂСЏРµРј blocking accruals
        if ($accrualClassification['blocking_accruals'] > 0) {
            if ($accrualClassification['has_linked_contract_accruals']) {
                throw ValidationException::withMessages([
                    'market_space_id' => 'Duplicate space has accruals linked to contracts: ' . ($accrualClassification['blocking_accruals']) . '.',
                ]);
            }

            // Fresh accruals conflict
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space has fresh accruals that conflict with canonical: duplicate latest=' . ($accrualClassification['duplicate_latest_accrual_period'] ?? 'null') . ', canonical latest=' . ($accrualClassification['canonical_latest_accrual_period'] ?? 'null') . '.',
            ]);
        }
    }

    /**
     * Р‘Р»РѕРєРёСЂСѓРµС‚ ambiguous classification (РЅРµС‚ Р±РµР·РѕРїР°СЃРЅС‹С… СЃРІСЏР·РµР№ РґР»СЏ РїРµСЂРµРЅРѕСЃР°)
     */
    private function throwIfClassificationIsAmbiguous(string $classification): void
    {
        if ($classification === 'ambiguous_canonical_candidate') {
            throw ValidationException::withMessages([
                'market_space_id' => 'Cannot resolve duplicate: no safe transfer links found. The duplicate has historical financial tail but no map_shapes or other safe links to justify the merge.',
            ]);
        }
    }

    /**
     * @return array<string, int>
     */
    private function transferPreviewCounts(int $marketId, int $duplicateSpaceId): array
    {
        return [
            'map_shapes' => $this->countRows('market_space_map_shapes', 'market_space_id', $duplicateSpaceId, $marketId),
            'cabinet_links' => $this->countRows('tenant_user_market_spaces', 'market_space_id', $duplicateSpaceId, $marketId),
            'marketplace_products' => $this->countRows('marketplace_products', 'market_space_id', $duplicateSpaceId, $marketId),
        ];
    }

    private function countRows(string $table, string $column, int $spaceId, int $marketId): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $query = DB::table($table)->where($column, $spaceId);

        if (Schema::hasColumn($table, 'market_id')) {
            $query->where('market_id', $marketId);
        }

        return (int) $query->count();
    }

    /**
     * @return array{moved: int, detached_conflicts: int}
     */
    private function transferMapShapes(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, mixed $now): array
    {
        if (! Schema::hasTable('market_space_map_shapes')) {
            return ['moved' => 0, 'detached_conflicts' => 0];
        }

        $sourceShapes = DB::table('market_space_map_shapes')
            ->where('market_id', $marketId)
            ->where('market_space_id', $duplicateSpaceId)
            ->get(['id', 'page', 'version']);

        $moved = 0;
        $detachedConflicts = 0;

        foreach ($sourceShapes as $shape) {
            $conflictIds = DB::table('market_space_map_shapes')
                ->where('market_id', $marketId)
                ->where('page', (int) $shape->page)
                ->where('version', (int) $shape->version)
                ->where('market_space_id', $canonicalSpaceId)
                ->where('id', '!=', (int) $shape->id)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($conflictIds !== []) {
                $detachedConflicts += DB::table('market_space_map_shapes')
                    ->whereIn('id', $conflictIds)
                    ->update([
                        'market_space_id' => null,
                        'is_active' => false,
                        'updated_at' => $now,
                    ]);
            }

            $moved += DB::table('market_space_map_shapes')
                ->where('id', (int) $shape->id)
                ->update([
                    'market_space_id' => $canonicalSpaceId,
                    'updated_at' => $now,
                ]);
        }

        return ['moved' => $moved, 'detached_conflicts' => $detachedConflicts];
    }

    /**
     * @return array{moved: int, merged: int}
     */
    private function transferCabinetLinks(int $duplicateSpaceId, int $canonicalSpaceId, mixed $now): array
    {
        if (! Schema::hasTable('tenant_user_market_spaces')) {
            return ['moved' => 0, 'merged' => 0];
        }

        $rows = DB::table('tenant_user_market_spaces')
            ->where('market_space_id', $duplicateSpaceId)
            ->get(['id', 'user_id']);

        $moved = 0;
        $merged = 0;

        foreach ($rows as $row) {
            $alreadyLinked = DB::table('tenant_user_market_spaces')
                ->where('user_id', (int) $row->user_id)
                ->where('market_space_id', $canonicalSpaceId)
                ->exists();

            if ($alreadyLinked) {
                $merged += DB::table('tenant_user_market_spaces')
                    ->where('id', (int) $row->id)
                    ->delete();

                continue;
            }

            $moved += DB::table('tenant_user_market_spaces')
                ->where('id', (int) $row->id)
                ->update([
                    'market_space_id' => $canonicalSpaceId,
                    'updated_at' => $now,
                ]);
        }

        return ['moved' => $moved, 'merged' => $merged];
    }

    private function transferMarketplaceProducts(int $marketId, int $duplicateSpaceId, int $canonicalSpaceId, mixed $now): int
    {
        if (! Schema::hasTable('marketplace_products')) {
            return 0;
        }

        return DB::table('marketplace_products')
            ->where('market_id', $marketId)
            ->where('market_space_id', $duplicateSpaceId)
            ->update([
                'market_space_id' => $canonicalSpaceId,
                'updated_at' => $now,
            ]);
    }
}
