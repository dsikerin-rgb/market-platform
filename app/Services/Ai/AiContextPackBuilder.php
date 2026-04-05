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
     *     debt_context: array,
     *     review_history: array,
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

        if (! in_array($space->map_review_status, ['changed_tenant', 'conflict', 'not_found'], true)) {
            return $this->errorPack($marketSpaceId, 'not_in_needs_clarification', $space->map_review_status);
        }

        return [
            'market_space_id'   => $space->id,
            'map_review_status' => $space->map_review_status,
            'space_snapshot'    => $this->buildSpaceSnapshot($space),
            'tenant_context'    => $this->buildTenantContext($space),
            'debt_context'      => $this->buildDebtContext($space, $marketId),
            'review_history'    => $this->buildReviewHistory($space->id, $marketId),
            'decision_options'  => $this->buildDecisionOptions($space->map_review_status),
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
                'has_tenant' => false,
                'tenant'     => null,
                'contracts'  => [],
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

        return [
            'has_tenant' => true,
            'tenant'     => $tenant ? [
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
            'contracts'  => $contracts,
        ];
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
            'context_pack_version' => '1.0.0',
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
                'context_pack_version' => '1.0.0',
                'read_only' => true,
                'ai_call' => false,
            ],
        ];
    }
}
