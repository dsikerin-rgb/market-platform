<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Domain\Operations\SpaceReviewDecision;
use App\Models\ContractDebt;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Services\Debt\DebtStatusResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only builder для AI context pack.
 *
 * Собирает минимальный, но достаточный контекст для одного
 * market_space_id, попавшего в "Нужно уточнить".
 *
 * НЕ делает:
 *  - write-действий
 *  - batch-анализа
 *  - вызовов к ИИ
 *  - изменений в revision-flow
 */
class AiContextPackBuilder
{
    public function __construct(
        private readonly DebtStatusResolver $debtResolver,
    ) {
    }

    /**
     * Собрать AI context pack для одного места.
     *
     * @return array{
     *     market_space_id: int,
     *     map_review_status: string,
     *     space_snapshot: array,
     *     tenant_context: array,
     *     accrual_context: array,
     *     debt_context: array,
     *     relation_context: array,
     *     review_history: array,
     *     reviewer_note: ?string,
     *     decision_options: array,
     *     meta: array,
     * }
     */
    public function build(int $marketSpaceId, int $marketId): array
    {
        $space = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereKey($marketSpaceId)
            ->with(['spaceType', 'tenant'])
            ->first();

        if (! $space) {
            return $this->errorPack($marketSpaceId, 'market_space_not_found');
        }

        $debtContext = $this->buildDebtContext($space, $marketId);
        $isUnconfirmedLink = (string) ($debtContext['debt_scope'] ?? 'none') === 'tenant_fallback';
        $reviewStatus = $isUnconfirmedLink && ! in_array($space->map_review_status, ['changed_tenant', 'conflict', 'not_found'], true)
            ? 'unconfirmed_link'
            : (string) $space->map_review_status;

        if (! in_array($reviewStatus, ['changed_tenant', 'conflict', 'not_found', 'unconfirmed_link'], true)) {
            return $this->errorPack($marketSpaceId, 'not_in_needs_clarification', $space->map_review_status);
        }

        $reviewHistory = $this->buildReviewHistory($space->id, $marketId);

        return [
            'market_space_id'   => $space->id,
            'map_review_status' => $reviewStatus,
            'space_snapshot'    => $this->buildSpaceSnapshot($space),
            'tenant_context'    => $this->buildTenantContext($space),
            'accrual_context'   => $this->buildAccrualContext($space),
            'debt_context'      => $debtContext,
            'relation_context'  => $this->buildRelationContext($space, $marketId),
            'review_history'    => $reviewHistory,
            'reviewer_note'     => $this->extractLatestReviewerNote($reviewHistory),
            'decision_options'  => $this->buildDecisionOptions($reviewStatus),
            'meta'              => $this->buildMeta($space, $marketId),
        ];
    }

    // ──────────────────────────────────────────────
    //  Space Snapshot
    // ──────────────────────────────────────────────

    private function buildSpaceSnapshot(MarketSpace $space): array
    {
        $shape = MarketSpaceMapShape::query()
            ->where('market_id', $space->market_id)
            ->where('market_space_id', $space->id)
            ->first();

        return [
            // обязательные
            'id'          => $space->id,
            'number'      => $space->number,
            'status'      => $space->status,
            'market_id'   => $space->market_id,

            // опциональные
            'display_name'        => $space->display_name,
            'type'                => $space->type,
            'type_label'          => $space->spaceType?->name_ru,
            'type_unit'           => $space->spaceType?->unit,
            'area_sqm'            => $space->area_sqm,
            'rent_rate_value'     => $space->rent_rate_value,
            'rent_rate_unit'      => $space->rent_rate_unit,
            'space_group_token'   => $space->space_group_token,
            'space_group_slot'    => $space->space_group_slot,
            'activity_type'       => $space->activity_type,
            'is_active'           => $space->is_active,
            'notes'               => $space->notes,

            // карта
            'has_map_shape'       => $shape !== null,
            'map_shape_id'        => $shape?->id,
            'map_shape_bbox'      => $shape ? [
                'x1' => $shape->bbox_x1,
                'y1' => $shape->bbox_y1,
                'x2' => $shape->bbox_x2,
                'y2' => $shape->bbox_y2,
            ] : null,
            // Координаты полигона НЕ включаем — ИИ не работает с геометрией
        ];
    }

    // ──────────────────────────────────────────────
    //  Tenant Context
    // ──────────────────────────────────────────────

    private function buildTenantContext(MarketSpace $space): array
    {
        if (! $space->tenant_id) {
            return [
                'has_tenant'         => false,
                'tenant'             => null,
                'contracts'          => [],
                'other_spaces_total' => 0,
                'other_spaces'       => [],
            ];
        }

        $tenant = Tenant::query()->find($space->tenant_id);

        $contracts = TenantContract::query()
            ->where('market_id', $space->market_id)
            ->where('market_space_id', $space->id)
            ->get([
                'id',
                'external_id',
                'number',
                'status',
                'starts_at',
                'ends_at',
                'tenant_id',
            ])
            ->map(fn ($c) => [
                'id'              => $c->id,
                'external_id'     => $c->external_id,
                'contract_number' => $c->number,
                'status'          => $c->status,
                'start_date'      => $c->starts_at?->toDateString(),
                'end_date'        => $c->ends_at?->toDateString(),
            ])
            ->toArray();

        $otherSpacesQuery = MarketSpace::query()
            ->where('market_id', $space->market_id)
            ->where('tenant_id', $space->tenant_id)
            ->where('id', '<>', $space->id);

        $otherSpacesTotal = (clone $otherSpacesQuery)->count();
        $otherSpaces = (clone $otherSpacesQuery)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->limit(5)
            ->get([
                'id',
                'number',
                'display_name',
                'status',
                'is_active',
            ]);

        $otherSpaceIds = $otherSpaces->pluck('id');
        $spacesWithShapes = $otherSpaceIds->isEmpty()
            ? collect()
            : MarketSpaceMapShape::query()
                ->where('market_id', $space->market_id)
                ->whereIn('market_space_id', $otherSpaceIds->all())
                ->pluck('market_space_id');

        $contractStats = $otherSpaceIds->isEmpty()
            ? collect()
            : TenantContract::query()
                ->where('market_id', $space->market_id)
                ->whereIn('market_space_id', $otherSpaceIds->all())
                ->selectRaw('market_space_id, COUNT(*) as contracts_count, SUM(CASE WHEN external_id IS NOT NULL THEN 1 ELSE 0 END) as exact_contracts_count')
                ->groupBy('market_space_id')
                ->get()
                ->keyBy('market_space_id');

        $accrualStats = $otherSpaceIds->isEmpty()
            || ! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'market_space_id')
            || ! Schema::hasColumn('tenant_accruals', 'period')
            ? collect()
            : DB::table('tenant_accruals')
                ->where('market_id', $space->market_id)
                ->whereIn('market_space_id', $otherSpaceIds->all())
                ->selectRaw('market_space_id, COUNT(*) as accruals_count, MAX(period) as latest_accrual_period')
                ->groupBy('market_space_id')
                ->get()
                ->keyBy('market_space_id');

        $otherSpacesPayload = $otherSpaces->map(function (MarketSpace $otherSpace) use ($spacesWithShapes, $contractStats, $accrualStats): array {
            $stats = $contractStats->get($otherSpace->id);
            $accrual = $accrualStats->get($otherSpace->id);

            return [
                'id'                      => (int) $otherSpace->id,
                'number'                  => $otherSpace->number,
                'display_name'            => $otherSpace->display_name,
                'status'                  => $otherSpace->status,
                'is_active'               => (bool) $otherSpace->is_active,
                'has_map_shape'           => $spacesWithShapes->contains($otherSpace->id),
                'contracts_count'         => $stats ? (int) $stats->contracts_count : 0,
                'has_exact_contract_link' => $stats ? (int) $stats->exact_contracts_count > 0 : false,
                'accruals_count'          => $accrual ? (int) $accrual->accruals_count : 0,
                'latest_accrual_period'   => $accrual->latest_accrual_period ?? null,
            ];
        })->values()->all();

        return [
            'has_tenant'         => true,
            'tenant'             => $tenant ? [
                'id'          => $tenant->id,
                'name'        => $tenant->name,
                'short_name'  => $tenant->short_name,
                'display_name'=> $tenant->display_name,
                'inn'         => $tenant->inn,
                'kpp'         => $tenant->kpp,
                'ogrn'        => $tenant->ogrn,
                'external_id' => $tenant->external_id,
                'one_c_uid'   => $tenant->one_c_uid,
                'type'        => $tenant->type,
                'is_active'   => $tenant->is_active,
            ] : null,
            'contracts'          => $contracts,
            'other_spaces_total' => $otherSpacesTotal,
            'other_spaces'       => $otherSpacesPayload,
        ];
    }

    // ──────────────────────────────────────────────
    //  Accrual Context
    // ──────────────────────────────────────────────

    private function buildAccrualContext(MarketSpace $space): array
    {
        if (! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'market_space_id')
            || ! Schema::hasColumn('tenant_accruals', 'period')) {
            return [
                'count'                 => 0,
                'latest_period'         => null,
                'latest_total_with_vat' => null,
                'latest_source'         => null,
            ];
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $space->market_id)
            ->where('market_space_id', $space->id);

        $latest = (clone $query)
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->first(['period', 'total_with_vat', 'source']);

        return [
            'count'                 => (int) (clone $query)->count(),
            'latest_period'         => $latest->period ?? null,
            'latest_total_with_vat' => isset($latest->total_with_vat) ? (float) $latest->total_with_vat : null,
            'latest_source'         => $latest->source ?? null,
        ];
    }

    // ──────────────────────────────────────────────
    //  Relation Context
    // ──────────────────────────────────────────────

    private function buildRelationContext(MarketSpace $space, int $marketId): array
    {
        $candidateSpaces = $space->tenant_id
            ? MarketSpace::query()
                ->where('market_id', $marketId)
                ->where('tenant_id', $space->tenant_id)
                ->where('id', '<>', $space->id)
                ->orderByDesc('is_active')
                ->orderBy('id')
                ->limit(5)
                ->get(['id', 'number', 'display_name', 'status', 'is_active'])
            : collect();

        $spaceIds = collect([(int) $space->id])
            ->merge($candidateSpaces->pluck('id')->map(fn ($id): int => (int) $id))
            ->unique()
            ->values()
            ->all();

        $counts = $this->relationCountsForSpaces($spaceIds, $marketId);
        $debtTotals = $this->debtTotalsForSpaces($spaceIds, $marketId);
        $currentCounts = $counts[(int) $space->id] ?? $this->emptyRelationCounts();
        $currentCounts['debt_total'] = $debtTotals[(int) $space->id] ?? null;

        $candidates = $candidateSpaces
            ->map(function (MarketSpace $candidate) use ($counts, $debtTotals): array {
                $candidateId = (int) $candidate->id;
                $candidateCounts = $counts[$candidateId] ?? $this->emptyRelationCounts();
                $candidateCounts['debt_total'] = $debtTotals[$candidateId] ?? null;

                return [
                    'id' => $candidateId,
                    'number' => $candidate->number,
                    'display_name' => $candidate->display_name,
                    'status' => $candidate->status,
                    'is_active' => (bool) $candidate->is_active,
                    'relation_counts' => $candidateCounts,
                    'canonical_score' => $this->canonicalScore($candidateCounts),
                ];
            })
            ->sortByDesc('canonical_score')
            ->values()
            ->all();

        $bestCandidate = $candidates[0] ?? null;
        $currentScore = $this->canonicalScore($currentCounts);

        return [
            'current_space' => [
                'id' => (int) $space->id,
                'number' => $space->number,
                'display_name' => $space->display_name,
                'status' => $space->status,
                'is_active' => (bool) $space->is_active,
                'relation_counts' => $currentCounts,
                'canonical_score' => $currentScore,
            ],
            'same_tenant_candidates' => $candidates,
            'likely_canonical_candidate_id' => $bestCandidate && (int) $bestCandidate['canonical_score'] > $currentScore
                ? (int) $bestCandidate['id']
                : null,
            'duplicate_review_hint' => $bestCandidate && (int) $bestCandidate['canonical_score'] > $currentScore
                ? 'У другого места того же арендатора больше подтверждённых связей. Текущее место нельзя подтверждать без выбора канонического места.'
                : 'Каноническое место не определяется автоматически. Нужна ручная проверка связей.',
        ];
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int, array<string, int|float|null>>
     */
    private function relationCountsForSpaces(array $spaceIds, int $marketId): array
    {
        if ($spaceIds === []) {
            return [];
        }

        $definitions = [
            'map_shapes' => ['market_space_map_shapes', 'market_space_id'],
            'contracts' => ['tenant_contracts', 'market_space_id'],
            'accruals' => ['tenant_accruals', 'market_space_id'],
            'cabinet_links' => ['tenant_user_market_spaces', 'market_space_id'],
            'tenant_bindings' => ['market_space_tenant_bindings', 'market_space_id'],
            'products' => ['marketplace_products', 'market_space_id'],
        ];

        $result = [];
        foreach ($spaceIds as $spaceId) {
            $result[(int) $spaceId] = $this->emptyRelationCounts();
        }

        foreach ($definitions as $key => [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $query = DB::table($table)->whereIn($column, $spaceIds);

            if (Schema::hasColumn($table, 'market_id')) {
                $query->where('market_id', $marketId);
            }

            $query
                ->selectRaw($column . ' as space_id, COUNT(*) as aggregate')
                ->groupBy($column)
                ->get()
                ->each(function ($row) use (&$result, $key): void {
                    $spaceId = (int) ($row->space_id ?? 0);

                    if ($spaceId > 0 && array_key_exists($spaceId, $result)) {
                        $result[$spaceId][$key] = (int) ($row->aggregate ?? 0);
                    }
                });
        }

        return $result;
    }

    /**
     * @return array<string, int|float|null>
     */
    private function emptyRelationCounts(): array
    {
        return [
            'map_shapes' => 0,
            'contracts' => 0,
            'accruals' => 0,
            'cabinet_links' => 0,
            'tenant_bindings' => 0,
            'products' => 0,
            'debt_total' => null,
        ];
    }

    /**
     * @param  array<string, int|float|null>  $counts
     */
    private function canonicalScore(array $counts): int
    {
        return ((int) ($counts['contracts'] ?? 0) * 5)
            + ((int) ($counts['accruals'] ?? 0) * 3)
            + ((int) ($counts['tenant_bindings'] ?? 0) * 3)
            + ((int) ($counts['map_shapes'] ?? 0) * 2)
            + ((int) ($counts['cabinet_links'] ?? 0) * 2)
            + ((int) ($counts['products'] ?? 0) > 0 ? 1 : 0)
            + ((float) ($counts['debt_total'] ?? 0) > 0 ? 2 : 0);
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int, float>
     */
    private function debtTotalsForSpaces(array $spaceIds, int $marketId): array
    {
        if ($spaceIds === []
            || ! Schema::hasTable('tenant_contracts')
            || ! Schema::hasColumn('tenant_contracts', 'market_space_id')
            || ! Schema::hasColumn('tenant_contracts', 'external_id')
            || ! Schema::hasTable('contract_debts')
            || ! Schema::hasColumn('contract_debts', 'contract_external_id')
            || ! Schema::hasColumn('contract_debts', 'debt_amount')) {
            return [];
        }

        $contractRows = DB::table('tenant_contracts')
            ->where('market_id', $marketId)
            ->whereIn('market_space_id', $spaceIds)
            ->whereNotNull('external_id')
            ->get(['market_space_id', 'external_id']);

        if ($contractRows->isEmpty()) {
            return [];
        }

        $spaceByExternalId = $contractRows
            ->mapWithKeys(fn ($row): array => [(string) $row->external_id => (int) $row->market_space_id])
            ->all();

        $debtRows = ContractDebt::currentStateQuery($marketId)
            ->whereIn('cd.contract_external_id', array_keys($spaceByExternalId))
            ->get(['cd.contract_external_id', 'cd.debt_amount']);

        $totals = [];
        foreach ($debtRows as $row) {
            $spaceId = $spaceByExternalId[(string) $row->contract_external_id] ?? null;
            if (! $spaceId) {
                continue;
            }

            $totals[$spaceId] = (float) ($totals[$spaceId] ?? 0.0) + (float) ($row->debt_amount ?? 0);
        }

        return $totals;
    }

    // ──────────────────────────────────────────────
    //  Debt Context (нормализованный блок)
    // ──────────────────────────────────────────────

    private function buildDebtContext(MarketSpace $space, int $marketId): array
    {
        $resolved = $this->debtResolver->resolveForMarketSpace($space->id, $marketId);

        $extra = $resolved['extra'] ?? [];
        $scope = $extra['scope'] ?? 'none';
        $overdueDays = $extra['overdue_days'] ?? null;

        // Считаем total_debt из contract_debts, если есть контракты с external_id
        $totalDebt = $this->calculateTotalDebt($space, $marketId);

        return [
            'debt_status'    => $resolved['status'] ?? null,       // green|pending|orange|red|gray|null
            'debt_label'     => $resolved['label'] ?? null,        // человекочитаемая метка
            'debt_scope'     => $scope,                            // space|tenant_fallback|none
            'total_debt'     => $totalDebt,                        // float|null
            'overdue_days'   => $overdueDays,                      // int|null
            'severity'       => $resolved['severity'] ?? 0,        // 0-3
            'source_marker'  => $this->resolveSourceMarker($scope, $resolved),
            'mode'           => $resolved['mode'] ?? 'auto',       // manual|auto
        ];
    }

    private function calculateTotalDebt(MarketSpace $space, int $marketId): ?float
    {
        if (! Schema::hasTable('contract_debts')) {
            return null;
        }

        $contractExternalIds = DB::table('tenant_contracts')
            ->where('market_space_id', $space->id)
            ->where('market_id', $marketId)
            ->whereNotNull('external_id')
            ->pluck('external_id');

        if ($contractExternalIds->isEmpty()) {
            return null;
        }

        if (! Schema::hasColumn('contract_debts', 'debt_amount')) {
            return null;
        }

        $total = ContractDebt::currentStateQuery($marketId)
            ->whereIn('cd.contract_external_id', $contractExternalIds->all())
            ->sum('cd.debt_amount');

        return $total > 0 ? (float) $total : 0.0;
    }

    private function resolveSourceMarker(string $scope, array $resolved): string
    {
        return match ($scope) {
            'space'           => 'contract_debts (per-space)',
            'tenant_fallback' => 'tenant-level debt_status',
            default           => 'none',
        };
    }

    // ──────────────────────────────────────────────
    //  Review History
    // ──────────────────────────────────────────────

    private function buildReviewHistory(int $spaceId, int $marketId): array
    {
        $operations = Operation::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', 'space_review')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'status', 'payload', 'comment', 'effective_at', 'created_by']);

        return $operations->map(fn ($op) => [
            'operation_id'  => $op->id,
            'decision'      => $op->payload['decision'] ?? null,
            'reason'        => $op->payload['reason'] ?? null,
            'status'        => $op->status,
            'comment'       => $op->comment,
            'effective_at'  => $op->effective_at?->toDateTimeString(),
            'created_by'    => $op->created_by,
        ])->toArray();
    }

    /**
     * @param  list<array<string, mixed>>  $reviewHistory
     */
    private function extractLatestReviewerNote(array $reviewHistory): ?string
    {
        foreach ($reviewHistory as $historyItem) {
            $reason = trim((string) ($historyItem['reason'] ?? ''));
            if ($reason !== '') {
                return $reason;
            }

            $comment = trim((string) ($historyItem['comment'] ?? ''));
            if ($comment !== '') {
                return $comment;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Decision Options
    // ──────────────────────────────────────────────

    private function buildDecisionOptions(string $mapReviewStatus): array
    {
        $allDecisions = SpaceReviewDecision::values();
        $labels = SpaceReviewDecision::labels();

        // Маппинг: какой map_review_status → какие решения релевантны
        $relevantDecisions = match ($mapReviewStatus) {
            'changed_tenant' => [
                SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                SpaceReviewDecision::OCCUPANCY_CONFLICT,
                SpaceReviewDecision::FIX_SPACE_IDENTITY,
            ],
            'conflict' => [
                SpaceReviewDecision::OCCUPANCY_CONFLICT,
                SpaceReviewDecision::TENANT_CHANGED_ON_SITE,
                SpaceReviewDecision::MARK_SPACE_FREE,
            ],
            'not_found' => [
                SpaceReviewDecision::SHAPE_NOT_FOUND,
                SpaceReviewDecision::FIX_SPACE_IDENTITY,
            ],
            'unconfirmed_link' => [
                SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION,
                SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION,
                SpaceReviewDecision::OCCUPANCY_CONFLICT,
            ],
            default => $allDecisions,
        };

        return [
            'map_review_status' => $mapReviewStatus,
            'relevant_decisions' => array_map(
                fn ($d) => [
                    'decision'  => $d,
                    'label'     => $labels[$d] ?? $d,
                    'is_applied'=> in_array($d, SpaceReviewDecision::appliedValues(), true),
                    'is_observed'=> in_array($d, SpaceReviewDecision::observedValues(), true),
                    'requires_shape_id' => SpaceReviewDecision::requiresShapeId($d),
                    'requires_reason'   => SpaceReviewDecision::requiresReason($d),
                    'requires_observed_tenant_name' => SpaceReviewDecision::requiresObservedTenantName($d),
                ],
                $relevantDecisions
            ),
            'all_possible_decisions' => array_map(
                fn ($d) => ['decision' => $d, 'label' => $labels[$d] ?? $d],
                $allDecisions
            ),
        ];
    }

    // ──────────────────────────────────────────────
    //  Meta
    // ──────────────────────────────────────────────

    private function buildMeta(MarketSpace $space, int $marketId): array
    {
        return [
            'built_at'        => now()->toDateTimeString(),
            'timezone'        => config('app.timezone'),
            'market_id'       => $marketId,
            'market_space_id' => $space->id,
            'context_pack_version' => '1.1.0',
            'read_only'       => true,
            'ai_call'         => false,
        ];
    }

    // ──────────────────────────────────────────────
    //  Error
    // ──────────────────────────────────────────────

    private function errorPack(int $spaceId, string $reason, mixed $detail = null): array
    {
        return [
            'error'   => true,
            'reason'  => $reason,
            'detail'  => $detail,
            'market_space_id' => $spaceId,
            'meta'    => [
                'built_at' => now()->toDateTimeString(),
                'timezone' => config('app.timezone'),
                'context_pack_version' => '1.1.0',
                'read_only' => true,
                'ai_call' => false,
            ],
        ];
    }
}
