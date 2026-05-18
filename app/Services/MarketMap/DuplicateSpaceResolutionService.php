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
        // Blocking links require explicit manual resolution.
        ['table' => 'tenant_contracts', 'column' => 'market_space_id', 'label' => 'contracts', 'blocking' => true],
        ['table' => 'tenant_requests', 'column' => 'market_space_id', 'label' => 'requests', 'blocking' => true],
        ['table' => 'tickets', 'column' => 'market_space_id', 'label' => 'tickets', 'blocking' => true],
        ['table' => 'tenant_reviews', 'column' => 'market_space_id', 'label' => 'reviews', 'blocking' => true],
        ['table' => 'tenant_space_showcases', 'column' => 'market_space_id', 'label' => 'showcases', 'blocking' => true],
        ['table' => 'marketplace_chats', 'column' => 'market_space_id', 'label' => 'marketplace_chats', 'blocking' => true],

        // Accruals require classification: blocking vs historical tail.
        ['table' => 'tenant_accruals', 'column' => 'market_space_id', 'label' => 'accruals', 'blocking' => false],
    ];

    private const SNAPSHOT_BINDING_TYPE = 'space_snapshot';

    private const SNAPSHOT_BINDING_SOURCE = 'market_space_snapshot';

    private const SAFE_BINDING_RESOLUTION_REASON = 'duplicate_space_retired';

    /**
     * @return array{
     *     duplicate_market_space_id: int,
     *     canonical_market_space_id: int,
     *     transfer_counts: array<string, int>,
     *     blocking_counts: array<string, int>,
     *     tenant_binding_classification: array{
     *         blocking_tenant_bindings: int,
     *         safe_snapshot_tenant_bindings: int
     *     },
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
        $this->validateCanonicalAnchors($marketId, $duplicate, $canonical);

        $linkClassification = $this->classifyLinks($marketId, $duplicate, $canonical);
        $this->throwIfClassifiedAsBlocked($linkClassification);

        $classification = $this->classifyDuplicateCase($linkClassification);
        $this->throwIfClassificationIsAmbiguous($classification);

        $result = [
            'duplicate_market_space_id' => $duplicateSpaceId,
            'canonical_market_space_id' => $canonicalSpaceId,
            'transfer_counts' => $this->transferPreviewCounts($marketId, $duplicateSpaceId),
            'blocking_counts' => $linkClassification['blocking_counts'],
            'tenant_binding_classification' => $linkClassification['tenant_binding_classification'],
            'classification' => $classification,
            'accrual_classification' => $linkClassification['accrual_classification'],
        ];

        // Include retained_financial_tail for the historical tail case.
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
            $this->validateCanonicalAnchors($marketId, $duplicate, $canonical);

            $linkClassification = $this->classifyLinks($marketId, $duplicate, $canonical);
            $this->throwIfClassifiedAsBlocked($linkClassification);

            $classification = $this->classifyDuplicateCase($linkClassification);
            $this->throwIfClassificationIsAmbiguous($classification);

            $now = now();
            $shapeSummary = $this->transferMapShapes($marketId, $duplicateSpaceId, $canonicalSpaceId, $now);
            $cabinetSummary = $this->transferCabinetLinks($duplicateSpaceId, $canonicalSpaceId, $now);
            $productsMoved = $this->transferMarketplaceProducts($marketId, $duplicateSpaceId, $canonicalSpaceId, $now);
            $closedTenantBindings = $this->closeSafeSnapshotTenantBindings($marketId, $duplicate, $canonical, $now);

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

            $canonicalUpdate = [
                'updated_at' => $now,
            ];

            $hasCanonicalReviewUpdate = false;

            if (Schema::hasColumn('market_spaces', 'map_review_status')) {
                $canonicalUpdate['map_review_status'] = 'matched';
                $hasCanonicalReviewUpdate = true;
            }

            if (Schema::hasColumn('market_spaces', 'map_reviewed_at')) {
                $canonicalUpdate['map_reviewed_at'] = $now;
                $hasCanonicalReviewUpdate = true;
            }

            if ($userId !== null && Schema::hasColumn('market_spaces', 'map_reviewed_by')) {
                $canonicalUpdate['map_reviewed_by'] = $userId;
                $hasCanonicalReviewUpdate = true;
            }

            if ($hasCanonicalReviewUpdate) {
                DB::table('market_spaces')
                    ->where('market_id', $marketId)
                    ->where('id', $canonicalSpaceId)
                    ->update($canonicalUpdate);
            }

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
                'tenant_binding_classification' => $linkClassification['tenant_binding_classification'],
                'classification' => $classification,
                'accrual_classification' => $linkClassification['accrual_classification'],
                'closed_tenant_bindings' => $closedTenantBindings,
            ];

            // Include retained_financial_tail for the historical tail case.
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

    private function validateCanonicalAnchors(int $marketId, MarketSpace $duplicate, MarketSpace $canonical): void
    {
        $duplicateAnchors = $this->canonicalAnchorCounts($marketId, (int) $duplicate->id);
        $canonicalAnchors = $this->canonicalAnchorCounts($marketId, (int) $canonical->id);
        $duplicateBindingClassification = $this->classifyTenantBindings($marketId, $duplicate, $canonical);

        $duplicateHasHardAnchor = $duplicateAnchors['map_shapes'] > 0
            || $duplicateAnchors['contracts'] > 0
            || ($duplicateBindingClassification['safe_snapshot_tenant_bindings'] ?? 0) > 0
            || ($duplicateBindingClassification['blocking_tenant_bindings'] ?? 0) > 0;
        $canonicalHasHardAnchor = $canonicalAnchors['map_shapes'] > 0 || $this->hasActiveContractSupport($marketId, (int) $canonical->id);

        if (! $duplicateHasHardAnchor || $canonicalHasHardAnchor) {
            return;
        }

        throw ValidationException::withMessages([
                'candidate_market_space_id' => 'Canonical space cannot be selected based only on financial links: it has no map shape or contracts, while the current space does.',
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
     * @return array{
     *     blocking_tenant_bindings: int,
     *     safe_snapshot_tenant_bindings: int
     * }
     */
    private function classifyTenantBindings(int $marketId, MarketSpace $duplicate, MarketSpace $canonical): array
    {
        if (! Schema::hasTable('market_space_tenant_bindings') || ! Schema::hasColumn('market_space_tenant_bindings', 'market_space_id')) {
            return [
                'blocking_tenant_bindings' => 0,
                'safe_snapshot_tenant_bindings' => 0,
            ];
        }

        $activeBindingsQuery = $this->activeTenantBindingsQuery($marketId, (int) $duplicate->id);
        $activeBindingsCount = (int) (clone $activeBindingsQuery)->count();

        if ($activeBindingsCount === 0) {
            return [
                'blocking_tenant_bindings' => 0,
                'safe_snapshot_tenant_bindings' => 0,
            ];
        }

        if (! $this->canSafelyClassifyTenantBindings($marketId, $duplicate, $canonical)) {
            return [
                'blocking_tenant_bindings' => $activeBindingsCount,
                'safe_snapshot_tenant_bindings' => 0,
            ];
        }

        $safeSnapshotCount = (int) $this->safeSnapshotTenantBindingsQuery($marketId, $duplicate, $canonical)->count();

        return [
            'blocking_tenant_bindings' => max(0, $activeBindingsCount - $safeSnapshotCount),
            'safe_snapshot_tenant_bindings' => $safeSnapshotCount,
        ];
    }

    private function activeTenantBindingsQuery(int $marketId, int $spaceId)
    {
        $query = DB::table('market_space_tenant_bindings')
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId);

        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
            $query->whereNull('ended_at');
        }

        return $query;
    }

    private function activeTenantContractsQuery(int $marketId, int $spaceId)
    {
        $query = DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId);

        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $query->whereNotIn('status', ['terminated', 'archived']);
        }

        if (Schema::hasColumn('tenant_contracts', 'space_mapping_mode')) {
            $query->where(function ($subQuery): void {
                $subQuery->whereNull('space_mapping_mode')
                    ->orWhere('space_mapping_mode', '!=', 'excluded');
            });
        }

        return $query;
    }

    private function activeContractBindingsQuery(int $marketId, int $spaceId)
    {
        $query = DB::table('market_space_tenant_bindings')
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId);

        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
            $query->whereNull('ended_at');
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')) {
            $query->whereNotNull('tenant_contract_id');
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'binding_type')) {
            $query->whereIn('binding_type', ['exact', 'manual']);
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'confidence')) {
            $query->where('confidence', 'high');
        }

        return $query;
    }

    private function hasActiveContractSupport(int $marketId, int $spaceId): bool
    {
        if (! Schema::hasTable('tenant_contracts')) {
            return false;
        }

        return $this->activeTenantContractsQuery($marketId, $spaceId)->exists()
            || $this->activeContractBindingsQuery($marketId, $spaceId)->exists();
    }

    private function canSafelyClassifyTenantBindings(int $marketId, MarketSpace $duplicate, MarketSpace $canonical): bool
    {
        if (! Schema::hasTable('market_space_tenant_bindings') || ! Schema::hasColumn('market_space_tenant_bindings', 'market_space_id')) {
            return false;
        }

        if ($this->activeTenantContractsQuery($marketId, (int) $duplicate->id)->exists()) {
            return false;
        }

        if ($this->activeContractBindingsQuery($marketId, (int) $duplicate->id)->exists()) {
            return false;
        }

        if (! $this->hasActiveContractSupport($marketId, (int) $canonical->id)) {
            return false;
        }

        $duplicateTenantId = (int) ($duplicate->tenant_id ?? 0);
        $canonicalTenantId = (int) ($canonical->tenant_id ?? 0);

        if ($duplicateTenantId > 0 && $canonicalTenantId > 0 && $duplicateTenantId !== $canonicalTenantId) {
            return false;
        }

        return true;
    }

    private function safeSnapshotTenantBindingsQuery(int $marketId, MarketSpace $duplicate, MarketSpace $canonical)
    {
        $query = $this->activeTenantBindingsQuery($marketId, (int) $duplicate->id)
            ->whereNull('tenant_contract_id');

        if (Schema::hasColumn('market_space_tenant_bindings', 'binding_type')) {
            $query->where('binding_type', self::SNAPSHOT_BINDING_TYPE);
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'source')) {
            $query->where('source', self::SNAPSHOT_BINDING_SOURCE);
        }

        $duplicateTenantId = (int) ($duplicate->tenant_id ?? 0);
        $canonicalTenantId = (int) ($canonical->tenant_id ?? 0);

        if ($duplicateTenantId > 0) {
            $query->where('tenant_id', $duplicateTenantId);
        } else {
            $query->whereRaw('1 = 0');
        }

        if ($duplicateTenantId > 0 && $canonicalTenantId > 0 && $duplicateTenantId !== $canonicalTenantId) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function closeSafeSnapshotTenantBindings(int $marketId, MarketSpace $duplicate, MarketSpace $canonical, mixed $now): int
    {
        if (! Schema::hasTable('market_space_tenant_bindings')) {
            return 0;
        }

        $query = $this->safeSnapshotTenantBindingsQuery($marketId, $duplicate, $canonical);
        $update = ['updated_at' => $now];

        if (Schema::hasColumn('market_space_tenant_bindings', 'ended_at')) {
            $update['ended_at'] = $now;
        }

        if (Schema::hasColumn('market_space_tenant_bindings', 'resolution_reason')) {
            $update['resolution_reason'] = self::SAFE_BINDING_RESOLUTION_REASON;
        }

        return $query->update($update);
    }

    /**
     * Classify duplicate-space links as blocking, safe-to-transfer, or historical tail.
     *
     * @return array{
     *     blocking_counts: array<string, int>,
     *     transfer_counts: array<string, int>,
     *     tenant_binding_classification: array{
     *         blocking_tenant_bindings: int,
     *         safe_snapshot_tenant_bindings: int
     *     },
     *     accrual_classification: array{
     *         blocking_accruals: int,
     *         historical_tail_accruals: int,
     *         duplicate_latest_accrual_period: ?string,
     *         canonical_latest_accrual_period: ?string,
     *         has_linked_contract_accruals: bool
     *     }
     * }
     */
    private function classifyLinks(int $marketId, MarketSpace $duplicate, MarketSpace $canonical): array
    {
        $blockingCounts = [];

        foreach (self::LINK_DEFINITIONS as $link) {
            $count = $this->countRows($link['table'], $link['column'], (int) $duplicate->id, $marketId);

            if ($link['blocking']) {
                $blockingCounts[$link['label']] = $count;
            }
        }

        $tenantBindingClassification = $this->classifyTenantBindings($marketId, $duplicate, $canonical);
        $blockingCounts['tenant_bindings'] = $tenantBindingClassification['blocking_tenant_bindings'];

        // Count safe links that can be transferred.
        $transferCounts = $this->transferPreviewCounts($marketId, (int) $duplicate->id);

        // Classify accrual links separately.
        $accrualClassification = $this->classifyAccruals($marketId, (int) $duplicate->id, (int) $canonical->id);

        return [
            'blocking_counts' => $blockingCounts,
            'transfer_counts' => $transferCounts,
            'tenant_binding_classification' => $tenantBindingClassification,
            'accrual_classification' => $accrualClassification,
        ];
    }

    /**
     * Classify duplicate-space accruals as blocking or historical tail.
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

        // Read the latest accrual period from the canonical space.
        $canonicalLatestPeriod = $this->getLatestAccrualPeriod($marketId, $canonicalSpaceId);

        // Load duplicate-space accruals together with their contract links.
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

        // Duplicate accruals cannot be historical tail when canonical has no latest period.
        // Such accruals are blocking by definition.
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

            // Track the latest duplicate accrual period.
            if ($accrualPeriod !== null) {
                if ($duplicateLatestPeriod === null || $accrualPeriod > $duplicateLatestPeriod) {
                    $duplicateLatestPeriod = $accrualPeriod;
                }
            }

            // Contract-linked accruals are always blocking.
            if ((int) ($accrual->tenant_contract_id ?? 0) > 0) {
                $hasLinkedContract = true;
                $blockingCount++;
                continue;
            }

            // Fresh accruals conflict with the canonical latest period.
            if ($accrualPeriod !== null && $accrualPeriod >= $canonicalLatestPeriod) {
                $blockingCount++;
                continue;
            }

            // Older unmatched accruals remain as historical tail.
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
     * Calculate the latest period from an accrual collection.
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
     * Read the latest accrual period for a space.
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
     * Determine the duplicate-resolution classification.
     *
     * @param  array{blocking_counts: array<string, int>, accrual_classification: array{blocking_accruals: int, historical_tail_accruals: int, duplicate_latest_accrual_period: ?string, canonical_latest_accrual_period: ?string, has_linked_contract_accruals: bool}, transfer_counts: array<string, int>, tenant_binding_classification: array{blocking_tenant_bindings: int, safe_snapshot_tenant_bindings: int}}  $linkClassification
     */
    private function classifyDuplicateCase(array $linkClassification): string
    {
        $blockingCounts = $linkClassification['blocking_counts'];
        $accrualClassification = $linkClassification['accrual_classification'];
        $transferCounts = $linkClassification['transfer_counts'];
        $tenantBindingClassification = $linkClassification['tenant_binding_classification'];

        // Check blocking links other than accruals first.
        $blockingExceptAccruals = $blockingCounts;
        unset($blockingExceptAccruals['accruals'], $blockingExceptAccruals['tenant_bindings']);
        $hasBlockingLinks = array_sum($blockingExceptAccruals) > 0;

        if ($hasBlockingLinks) {
            if (($blockingCounts['contracts'] ?? 0) > 0) {
                return 'duplicate_with_blocking_contracts';
            }
            return 'duplicate_with_blocking_contracts'; // Treat other blocking links the same as contracts.
        }

        // Blocking accruals override all non-blocking cases.
        if ($accrualClassification['blocking_accruals'] > 0) {
            if ($accrualClassification['has_linked_contract_accruals']) {
                return 'duplicate_with_blocking_accruals';
            }
            return 'duplicate_fresh_accruals_conflict';
        }

        // Historical tail is allowed only when safe transfer links exist.
        if ($accrualClassification['historical_tail_accruals'] > 0) {
            // Safe links justify keeping the historical tail on the duplicate.
            $hasSafeLinks = array_sum($transferCounts) > 0;
            if ($hasSafeLinks) {
                return 'duplicate_with_historical_financial_tail';
            }
            // No safe links means the candidate is ambiguous.
            return 'ambiguous_canonical_candidate';
        }

        // No financial links means the duplicate is safe.
        return 'safe_duplicate_no_financials';
    }

    /**
     * Build a summary for the retained financial tail.
     *
     * @param  array{accrual_classification: array{historical_tail_accruals: int, duplicate_latest_accrual_period: ?string}}  $linkClassification
     */
    private function buildRetainedFinancialTailSummary(array $linkClassification): array
    {
        $accrualClassification = $linkClassification['accrual_classification'];

        return [
            'accruals_count' => $accrualClassification['historical_tail_accruals'],
            'earliest_period' => null, // Can be added later if needed.
            'latest_period' => $accrualClassification['duplicate_latest_accrual_period'],
            'unmatched_only' => true,
        ];
    }

    /**
     * @param  array{blocking_counts: array<string, int>, accrual_classification: array{blocking_accruals: int, has_linked_contract_accruals: bool, duplicate_latest_accrual_period: ?string, canonical_latest_accrual_period: ?string}, tenant_binding_classification: array{blocking_tenant_bindings: int, safe_snapshot_tenant_bindings: int}}  $linkClassification
     */
    private function throwIfClassifiedAsBlocked(array $linkClassification): void
    {
        $blockingCounts = $linkClassification['blocking_counts'];
        $tenantBindingClassification = $linkClassification['tenant_binding_classification'];
        $accrualClassification = $linkClassification['accrual_classification'];

        // Active contracts are always blocking.
        if (($blockingCounts['contracts'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space has active contracts: ' . ($blockingCounts['contracts']) . '.',
            ]);
        }

        if (($tenantBindingClassification['blocking_tenant_bindings'] ?? 0) > 0) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space has blocking tenant bindings: ' . ($tenantBindingClassification['blocking_tenant_bindings']) . '.',
            ]);
        }

        // Any other business links also block automatic resolution.
        $blockingExceptAccruals = $blockingCounts;
        unset($blockingExceptAccruals['accruals'], $blockingExceptAccruals['tenant_bindings']);
        $nonZeroBlocking = array_filter($blockingExceptAccruals, static fn (int $count): bool => $count > 0);

        if ($nonZeroBlocking !== []) {
            throw ValidationException::withMessages([
                'market_space_id' => 'Duplicate space has blocking business links: ' . implode(', ', array_keys($nonZeroBlocking)) . '.',
            ]);
        }

        // Blocking accruals fail validation with a dedicated message.
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
     * Reject ambiguous classifications with no safe links to transfer.
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
