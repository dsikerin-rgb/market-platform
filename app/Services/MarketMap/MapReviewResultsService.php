<?php
# app/Services/MarketMap/MapReviewResultsService.php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Domain\Operations\SpaceReviewStateMachine;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\Tenant;
use App\Models\TenantContract;
use App\Models\User;
use App\Services\Debt\DebtStatusResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MapReviewResultsService
{
    /**
     * @return array<string, string>
     */
    public function reviewStatusLabels(): array
    {
        return [
            'matched' => 'Совпало',
            'changed' => 'Есть безопасное изменение',
            'changed_tenant' => 'Сменился арендатор',
            'conflict' => 'Конфликт',
            'not_found' => 'Не найдено на карте',
            'unconfirmed_link' => 'Связь с местом не подтверждена',
        ];
    }

    public function reviewStatusLabel(?string $status): ?string
    {
        if (! filled($status)) {
            return null;
        }

        return $this->reviewStatusLabels()[(string) $status] ?? (string) $status;
    }

    public function hasMapReviewColumns(): bool
    {
        return Schema::hasTable('market_spaces')
            && Schema::hasColumn('market_spaces', 'map_review_status')
            && Schema::hasColumn('market_spaces', 'map_reviewed_at')
            && Schema::hasColumn('market_spaces', 'map_reviewed_by');
    }

    /**
     * @param  Market|int  $market
     * @return array{
     *   total:int,
     *   reviewed:int,
     *   remaining:int,
     *   percent:int,
     *   counts:array<string,int>,
     *   labels:array<string,string>
     * }
     */
    public function buildProgress(Market|int $market): array
    {
        $marketId = $market instanceof Market ? (int) $market->id : (int) $market;

        $baseQuery = MarketSpace::query()->where('market_id', $marketId);

        if (Schema::hasColumn('market_spaces', 'is_active')) {
            $baseQuery->where('is_active', true);
        }

        $total = (int) (clone $baseQuery)->count();

        if (! $this->hasMapReviewColumns()) {
            return [
                'total' => $total,
                'reviewed' => 0,
                'remaining' => $total,
                'percent' => 0,
                'counts' => [],
                'labels' => [],
            ];
        }

        $counts = (clone $baseQuery)
            ->whereNotNull('map_review_status')
            ->selectRaw('map_review_status, count(*) as aggregate')
            ->groupBy('map_review_status')
            ->pluck('aggregate', 'map_review_status')
            ->map(fn ($count): int => (int) $count)
            ->all();

        $reviewed = array_sum($counts);
        $remaining = max($total - $reviewed, 0);

        return [
            'total' => $total,
            'reviewed' => $reviewed,
            'remaining' => $remaining,
            'percent' => $total > 0 ? (int) round(($reviewed / $total) * 100) : 0,
            'counts' => $counts,
            'labels' => collect(array_keys($counts))
                ->mapWithKeys(fn (string $status): array => [$status => $this->reviewStatusLabel($status) ?? $status])
                ->all(),
        ];
    }

    /**
     * @return list<array{
     *   space_id:int,
     *   number:?string,
     *   display_name:?string,
     *   current_tenant_name:?string,
     *   location_name:?string,
     *   created_at:?string,
     *   created_by_name:?string,
     *   review_status:?string,
     *   review_status_label:?string,
     *   reviewed_at:?string,
     *   reviewed_by_name:?string,
     *   decision:?string,
     *   decision_label:?string,
     *   reason:?string,
     *   review_operation_id:?int,
     *   review_created_by:?int,
     *   can_edit_reason:bool,
     *   tenant_change_details:?array{
     *     observed_tenant_name:?string,
     *     review_comment:?string,
     *     author_name:?string,
     *     recorded_at:?string
     *   },
     *   diagnostics:array<string,mixed>
     * }>
     */
    public function needsAttention(int $marketId, int $limit = 50): array
    {
        if ($marketId <= 0 || ! $this->hasMapReviewColumns()) {
            return [];
        }

        $financialSignals = $this->financialTenantSignals($marketId, $limit);
        $financialSignalSpaceIds = array_keys($financialSignals);

        $reviewSpaces = MarketSpace::query()
            ->with(['location:id,name', 'tenant:id,name'])
            ->where('market_id', $marketId)
            ->whereIn('map_review_status', SpaceReviewStateMachine::attentionReviewStatuses())
            ->when(
                Schema::hasColumn('market_spaces', 'is_active'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderByDesc('map_reviewed_at')
            ->orderByRaw('COALESCE(number, display_name, code) asc')
            ->limit($limit)
            ->get([
                'id',
                'location_id',
                'tenant_id',
                'number',
                'display_name',
                'code',
                'map_review_status',
                'map_reviewed_at',
                'map_reviewed_by',
            ]);

        $reviewSpaceIds = $reviewSpaces->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $missingFinancialSpaceIds = array_values(array_diff($financialSignalSpaceIds, $reviewSpaceIds));
        $financialSpaces = $missingFinancialSpaceIds !== []
            ? MarketSpace::query()
                ->with(['location:id,name', 'tenant:id,name'])
                ->where('market_id', $marketId)
                ->whereIn('id', $missingFinancialSpaceIds)
                ->when(
                    Schema::hasColumn('market_spaces', 'is_active'),
                    fn ($query) => $query->where('is_active', true)
                )
                ->orderByRaw('COALESCE(number, display_name, code) asc')
                ->get([
                    'id',
                    'location_id',
                    'tenant_id',
                    'number',
                    'display_name',
                    'code',
                    'map_review_status',
                    'map_reviewed_at',
                    'map_reviewed_by',
                ])
            : collect();

        $spaces = $reviewSpaces->merge($financialSpaces)->unique('id')->values();

        if ($spaces->isEmpty()) {
            return [];
        }

        $spaceIds = $spaces->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $latestOperations = $this->latestSpaceReviewOperationsBySpace($marketId, $spaceIds);
        $diagnostics = $this->buildSpaceDiagnostics($marketId, $spaces, $latestOperations);

        $reviewerIds = $spaces->pluck('map_reviewed_by')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->values();
        $creatorIds = $latestOperations->pluck('created_by')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->values();
        $userIds = $reviewerIds
            ->merge($creatorIds)
            ->unique()
            ->all();

        $reviewers = User::query()
            ->whereIn('id', $userIds)
            ->pluck('name', 'id');

        return $spaces->map(function (MarketSpace $space) use ($latestOperations, $reviewers, $diagnostics): array {
            $operation = $latestOperations->get((int) $space->id);
            $payload = is_array($operation?->payload) ? $operation->payload : [];
            $financialSignal = is_array($diagnostics[(int) $space->id]['financial_signal'] ?? null)
                ? $diagnostics[(int) $space->id]['financial_signal']
                : null;
            $hasOperationDecision = filled($payload['decision'] ?? null);
            $isFinancialOnly = SpaceReviewStateMachine::isFinancialOnlyConflict(
                $hasOperationDecision,
                $financialSignal !== null,
                (string) ($space->map_review_status ?? ''),
            );
            $decision = $hasOperationDecision
                ? (string) $payload['decision']
                : ($isFinancialOnly ? SpaceReviewDecision::TENANT_CHANGED_ON_SITE : null);
            $reviewedAt = $space->map_reviewed_at?->format('d.m.Y H:i');
            $reviewedByName = $space->map_reviewed_by ? (string) ($reviewers[(int) $space->map_reviewed_by] ?? '-') : null;
            $financialRecordedAt = trim((string) ($financialSignal['latest_imported_at_label'] ?? ''));
            $createdAt = $operation?->created_at?->format('d.m.Y H:i')
                ?? $reviewedAt
                ?? ($financialRecordedAt !== '' ? $financialRecordedAt : null);
            $createdByName = $operation?->created_by
                ? (string) ($reviewers[(int) $operation->created_by] ?? '-')
                : ($reviewedByName ?? ($isFinancialOnly ? 'Система' : null));
            $reason = filled($payload['reason'] ?? null)
                ? trim((string) $payload['reason'])
                : ($isFinancialOnly ? $this->financialSignalReason($space, $financialSignal) : null);

            $suggestedTargetTenantId = 0;
            $suggestedTargetTenantName = '';

            if (is_array($financialSignal)
                && (int) ($financialSignal['tenant_id'] ?? 0) > 0
            ) {
                $suggestedTargetTenantId = (int) $financialSignal['tenant_id'];
                $suggestedTargetTenantName = trim((string) ($financialSignal['tenant_name'] ?? ''));
            }

            $tenantChangeDetails = $financialSignal !== null
                ? $this->financialTenantChangeDetails($financialSignal, $createdAt)
                : $this->tenantChangeDetails($decision, $payload, $createdByName, $createdAt, $reason);
            $reviewerTenantName = trim((string) data_get($tenantChangeDetails, 'observed_tenant_name', ''));
            if ($reviewerTenantName === '') {
                $reviewerTenantName = $this->reviewerTenantNameFromReason((string) ($reason ?? ''));
            }

            return [
                'space_id' => (int) $space->id,
                'number' => $space->number,
                'display_name' => $space->display_name,
                'current_tenant_name' => $space->tenant?->name,
                'location_name' => $space->location?->name,
                'created_at' => $createdAt,
                'created_by_name' => $createdByName,
                'review_status' => $isFinancialOnly ? 'conflict' : $space->map_review_status,
                'review_status_label' => $this->reviewStatusLabel($isFinancialOnly ? 'conflict' : $space->map_review_status),
                'reviewed_at' => $reviewedAt,
                'reviewed_by_name' => $reviewedByName ?? ($isFinancialOnly ? 'Система' : null),
                'decision' => $decision,
                'decision_label' => $isFinancialOnly
                    ? 'Финконтур сообщает о новом арендаторе'
                    : ($decision ? (SpaceReviewDecision::labels()[$decision] ?? $decision) : null),
                'reason' => $reason,
                'review_operation_id' => $operation?->id ? (int) $operation->id : null,
                'review_created_by' => $operation?->created_by ? (int) $operation->created_by : null,
                'can_edit_reason' => $operation !== null && (int) ($operation->created_by ?? 0) === Auth::id(),
                'tenant_change_details' => $tenantChangeDetails,
                'reviewer_tenant_name' => $reviewerTenantName !== '' ? $reviewerTenantName : null,
                'diagnostics' => $diagnostics[(int) $space->id] ?? $this->emptyDiagnostics(),
                'suggested_target_tenant_id' => $suggestedTargetTenantId,
                'suggested_target_tenant_name' => $suggestedTargetTenantName,
            ];
        })
            ->sortByDesc(fn (array $row): int => (int) data_get($row, 'diagnostics.financial_signal.priority', 0))
            ->values()
            ->take($limit)
            ->all();
    }

    /**
     * @return list<array{
     *   space_id:int,
     *   number:?string,
     *   display_name:?string,
     *   current_tenant_name:?string,
     *   location_name:?string,
     *   created_at:?string,
     *   created_by_name:string,
     *   review_status:string,
     *   review_status_label:string,
     *   reviewed_at:null,
     *   reviewed_by_name:string,
     *   decision:null,
     *   decision_label:string,
     *   reason:string,
     *   diagnostics:array<string,mixed>
     * }>
     */
    public function unconfirmedLinks(int $marketId, int $limit = 50): array
    {
        if ($marketId <= 0 || ! $this->hasMapReviewColumns()) {
            return [];
        }

        $spaceIds = MarketSpaceMapShape::query()
            ->where('market_id', $marketId)
            ->where('is_active', true)
            ->whereNotNull('market_space_id')
            ->distinct()
            ->pluck('market_space_id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        if ($spaceIds->isEmpty()) {
            return [];
        }

        $spaces = MarketSpace::query()
            ->with(['location:id,name', 'tenant:id,name'])
            ->where('market_id', $marketId)
            ->whereIn('id', $spaceIds)
            ->whereNotNull('tenant_id')
            ->whereNull('map_review_status')
            ->when(
                Schema::hasColumn('market_spaces', 'is_active'),
                fn ($query) => $query->where('is_active', true)
            )
            ->orderByRaw('COALESCE(number, display_name, code) asc')
            ->limit($limit * 3)
            ->get([
                'id',
                'location_id',
                'tenant_id',
                'number',
                'display_name',
                'code',
                'map_review_status',
                'map_reviewed_at',
                'map_reviewed_by',
            ]);

        if ($spaces->isEmpty()) {
            return [];
        }

        $debtResolver = app(DebtStatusResolver::class);
        $spaces = $spaces
            ->filter(function (MarketSpace $space) use ($debtResolver, $marketId): bool {
                $resolved = $debtResolver->resolveForMarketSpace((int) $space->id, $marketId);

                return (string) data_get($resolved, 'extra.scope', 'none') === 'tenant_fallback';
            })
            ->take($limit)
            ->values();

        if ($spaces->isEmpty()) {
            return [];
        }

        $diagnostics = $this->buildSpaceDiagnostics($marketId, $spaces);

        return $spaces->map(function (MarketSpace $space) use ($diagnostics): array {
            return [
                'space_id' => (int) $space->id,
                'number' => $space->number,
                'display_name' => $space->display_name,
                'current_tenant_name' => $space->tenant?->name,
                'location_name' => $space->location?->name,
                'created_at' => null,
                'created_by_name' => 'Система',
                'review_status' => 'unconfirmed_link',
                'review_status_label' => $this->reviewStatusLabel('unconfirmed_link') ?? 'Связь с местом не подтверждена',
                'reviewed_at' => null,
                'reviewed_by_name' => 'Система',
                'decision' => null,
                'decision_label' => 'Системно найдено',
                'reason' => 'На карте используется статус арендатора, но точная связь с этим местом не подтверждена.',
                'diagnostics' => $diagnostics[(int) $space->id] ?? $this->emptyDiagnostics(),
            ];
        })->all();
    }

    /**
     * @param  Collection<int, MarketSpace>  $spaces
     * @return array<int, array<string, mixed>>
     */
    private function buildSpaceDiagnostics(int $marketId, Collection $spaces, ?Collection $latestOperations = null): array
    {
        $spaceIds = $spaces->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($spaceIds === []) {
            return [];
        }

        $latestOperations ??= collect();

        $currentCounts = $this->relationCountsForSpaces($spaceIds);
        $contractOverrides = $this->activeContractOverrideForSpaces($spaceIds, $marketId);
        $financialSignals = $this->financialTenantSignals($marketId, max(count($spaceIds), 50));

        $observedTenantIdsBySpace = $this->observedTenantIdsBySpace($marketId, $spaces, $latestOperations);

        $tenantIds = $spaces->pluck('tenant_id')
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->merge(collect($observedTenantIdsBySpace)->flatten())
            ->unique()
            ->values()
            ->all();

        $candidateSpaces = $tenantIds !== []
            ? MarketSpace::query()
                ->with(['tenant:id,name'])
                ->where('market_id', $marketId)
                ->whereIn('tenant_id', $tenantIds)
                ->when(
                    Schema::hasColumn('market_spaces', 'is_active'),
                    fn ($query) => $query->where('is_active', true)
                )
            ->orderByRaw('COALESCE(number, display_name, code) asc')
            ->get(array_merge(
                ['id', 'location_id', 'tenant_id', 'number', 'display_name', 'code'],
                Schema::hasColumn('market_spaces', 'space_group_role') ? ['space_group_role'] : []
            ))
            : collect();
        $nameCandidateSpaces = $this->nameDuplicateCandidateSpaces($marketId, $spaces);

        $candidateIds = $candidateSpaces->pluck('id')
            ->merge($nameCandidateSpaces->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $candidateCounts = $this->relationCountsForSpaces($candidateIds);
        $candidatesByTenant = $candidateSpaces->groupBy(fn (MarketSpace $space): int => (int) $space->tenant_id);

        $allDiagnosticSpaceIds = array_unique(array_merge($spaceIds, $candidateIds));

        $contractDetails = $this->contractDetailsForSpaces($allDiagnosticSpaceIds, $marketId);
        $accrualDetails = $this->accrualDetailsForSpaces($allDiagnosticSpaceIds, $marketId);

        return $spaces->mapWithKeys(function (MarketSpace $space) use ($currentCounts, $candidateCounts, $candidatesByTenant, $nameCandidateSpaces, $contractOverrides, $contractDetails, $accrualDetails, $financialSignals, $observedTenantIdsBySpace, $latestOperations): array {
            $spaceId = (int) $space->id;
            $counts = $currentCounts[$spaceId] ?? [];
            $tenantId = (int) ($space->tenant_id ?? 0);
            $contractOverride = $contractOverrides[$spaceId] ?? null;

            $currentScore = $this->relationStrengthScore($counts);
            $candidateTenantIds = $tenantId > 0
                ? [$tenantId]
                : array_values(array_unique(array_map('intval', $observedTenantIdsBySpace[$spaceId] ?? [])));

            $tenantCandidates = $candidateTenantIds !== []
                ? collect($candidateTenantIds)
                    ->flatMap(fn (int $candidateTenantId): Collection => $candidatesByTenant->get($candidateTenantId, collect()))
                    ->unique('id')
                    ->reject(fn (MarketSpace $candidate): bool => (int) $candidate->id === $spaceId)
                    ->sortByDesc(function (MarketSpace $candidate) use ($candidateCounts): int {
                        return $this->relationStrengthScore($candidateCounts[(int) $candidate->id] ?? []);
                    })
                    ->map(fn (MarketSpace $candidate): array => [
                        'space' => $candidate,
                        'match_source' => 'tenant',
                        'match_reason' => 'То же связанное юрлицо/арендатор',
                    ])
                : collect();

            $currentNameTokens = $this->spaceNameDuplicateTokens($space);
            $nameCandidates = $currentNameTokens !== []
                ? $nameCandidateSpaces
                    ->reject(fn (MarketSpace $candidate): bool => (int) $candidate->id === $spaceId)
                    ->filter(function (MarketSpace $candidate) use ($space, $currentNameTokens): bool {
                        $candidateLocationId = (int) ($candidate->location_id ?? 0);
                        $spaceLocationId = (int) ($space->location_id ?? 0);

                        if ($spaceLocationId > 0 && $candidateLocationId > 0 && $spaceLocationId !== $candidateLocationId) {
                            return false;
                        }

                        return array_intersect($currentNameTokens, $this->spaceNameDuplicateTokens($candidate)) !== [];
                    })
                    ->map(fn (MarketSpace $candidate): array => [
                        'space' => $candidate,
                        'match_source' => 'name',
                        'match_reason' => 'Похоже по названию/номеру в той же локации',
                    ])
                : collect();

            $latestOperationsForCandidateMap = $latestOperations;
            $candidates = $tenantCandidates
                ->merge($nameCandidates)
                ->groupBy(fn (array $item): int => (int) $item['space']->id)
                ->map(function (Collection $group): array {
                    $first = $group->first();
                    $sources = $group
                        ->pluck('match_source')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();
                    $reasons = $group
                        ->pluck('match_reason')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all();

                    return [
                        'space' => $first['space'],
                        'match_source' => in_array('tenant', $sources, true) ? 'tenant' : 'name',
                        'match_sources' => $sources,
                        'match_reason' => implode('; ', $reasons),
                    ];
                })
                ->sortByDesc(function (array $item) use ($candidateCounts): int {
                    return $this->relationStrengthScore($candidateCounts[(int) $item['space']->id] ?? []);
                })
                ->take(5)
                ->map(function (array $item) use ($space, $tenantId, $candidateCounts, $currentScore, $contractDetails, $accrualDetails, $observedTenantIdsBySpace, $latestOperationsForCandidateMap, $currentCounts): array {
                    /** @var MarketSpace $candidate */
                    $candidate = $item['space'];
                    $candidateId = (int) $candidate->id;
                    $candidateTenantId = (int) ($candidate->tenant_id ?? 0);
                    $counts = $candidateCounts[$candidateId] ?? [];
                    $candidateScore = $this->relationStrengthScore($counts);
                    $sameTenantContour = $tenantId <= 0 || $candidateTenantId <= 0 || $tenantId === $candidateTenantId;

                    // ─── Duplicate Resolution Apply Guard (П52у/2-3-like scenario) ─────────────────────────────────
                    // Блокировать destructive action "Разобрать дубль", если:
                    // 1) current space имеет tenant_id (current_tenant);
                    // 2) observed tenant отличается от current tenant;
                    // 3) candidate найден как более сильное место для current tenant (same tenant contour);
                    // 4) candidate stronger за счёт договоров/начислений/tenant bindings текущего системного арендатора.
                    //
                    // Наличие независимых anchor у current space (map_shapes/accruals/products/contracts) —
                    // это ДОПОЛНИТЕЛЬНЫЙ АРГУМЕНТ ЗА блокировку, а не условие для разрешения действия.
                    //
                    // Важно: НЕ блокировать, если:
                    // - current place пустое (tenant_id = null) — это кейс смены арендатора, не дубль
                    // - candidate найден по name, а не по tenant
                    // - current place имеет map_shapes/accruals, но нет observed_tenant (это нормальный дубль)

                    $currentHasTenant = $tenantId > 0;
                    $hasObservedDifferentTenant = ! empty($observedTenantIdsBySpace[(int) $space->id] ?? []);

                    // observed tenant должен отличаться от current tenant
                    $observedDiffersFromCurrent = false;
                    if ($currentHasTenant && $hasObservedDifferentTenant) {
                        $observedTenantIds = $observedTenantIdsBySpace[(int) $space->id] ?? [];
                        $observedDiffersFromCurrent = ! in_array($tenantId, array_map('intval', $observedTenantIds), true);
                    }

                    // Candidate stronger за счёт финансовых связей (contracts/accruals)
                    $candidateHasFinancials = ($counts['contracts'] ?? 0) > 0 || ($counts['accruals'] ?? 0) > 0;
                    $candidateStrongerByFinancials = $candidateScore > $currentScore && $candidateHasFinancials;

                    // Current place имеет собственные подтверждённые связи — это дополнительный аргумент ЗА блокировку
                    $currentSpaceCounts = $currentCounts[(int) $space->id] ?? [];
                    $currentHasIndependentAnchors = ((int) ($currentSpaceCounts['map_shapes'] ?? 0) > 0)
                        || ((int) ($currentSpaceCounts['contracts'] ?? 0) > 0)
                        || ((int) ($currentSpaceCounts['accruals'] ?? 0) > 0)
                        || ((int) ($currentSpaceCounts['products'] ?? 0) > 0);

                    $currentSpaceOperation = $latestOperationsForCandidateMap->get((int) $space->id);
                    $currentSpacePayload = is_array($currentSpaceOperation?->payload) ? $currentSpaceOperation->payload : [];
                    $currentReviewDecision = (string) ($currentSpacePayload['decision'] ?? '');
                    $isOccupancyConflict = $currentReviewDecision === SpaceReviewDecision::OCCUPANCY_CONFLICT;

                    // Блокируем, если current tenant отличается от observed tenant или наблюдается конфликт занятости,
                    // и кандидат найден по current tenant.
                    // НО: explicit duplicate/identity scenario (child без shape -> parent/group с shape)
                    // должен позволять duplicate resolution несмотря на P52u-like признаки.
                    $isP52uLikeScenario = $currentHasTenant
                        && ($observedDiffersFromCurrent || $isOccupancyConflict)
                        && $sameTenantContour
                        && $candidateStrongerByFinancials;

                    // Явный признак duplicate/identity сценария: child место без shape дублирует parent/group
                    $candidateIsGroupOrCanonical = in_array((string) ($candidate->space_group_role ?? ''), [
                        MarketSpace::SPACE_GROUP_ROLE_PARENT,
                    ], true);
                    $candidateHasUsableShape = ((int) ($counts['map_shapes'] ?? 0)) > 0;
                    $currentHasNoUsableShape = ((int) ($currentSpaceCounts['map_shapes'] ?? 0)) === 0;
                    $reasonText = (string) ($currentSpacePayload['reason'] ?? '') . ' ' . (string) ($currentSpaceOperation?->comment ?? '');
                    $reasonIndicatesDuplicate = preg_match('/дубль|дублируется|группа\s+мест|связь\s+с\s+местом/iu', $reasonText) === 1;
                    $isExplicitDuplicateScenario = $candidateIsGroupOrCanonical
                        && $candidateHasUsableShape
                        && $currentHasNoUsableShape
                        && $reasonIndicatesDuplicate;

                    $canApplyDuplicateResolution = ! $isP52uLikeScenario || $isExplicitDuplicateScenario;
                    $duplicateResolutionBlockReason = null;

                    if ($isP52uLikeScenario && ! $isExplicitDuplicateScenario) {
                        if ($isOccupancyConflict) {
                            if ($currentHasIndependentAnchors) {
                                $duplicateResolutionBlockReason = 'Кандидат найден по текущему арендатору, но на месте наблюдается конфликт занятости. На месте есть собственные связи (карта/договоры/начисления/товары). Сначала проверьте актуального арендатора места.';
                            } else {
                                $duplicateResolutionBlockReason = 'Кандидат найден по текущему арендатору, но на месте наблюдается конфликт занятости. Сначала проверьте актуального арендатора места.';
                            }
                        } elseif ($currentHasIndependentAnchors) {
                            $duplicateResolutionBlockReason = 'Кандидат найден по текущему арендатору, но на месте наблюдается другой арендатор. На месте есть собственные связи (карта/договоры/начисления/товары). Сначала проверьте актуального арендатора места.';
                        } else {
                            $duplicateResolutionBlockReason = 'Кандидат найден по текущему арендатору, но на месте наблюдается другой арендатор. Сначала проверьте актуального арендатора места.';
                        }
                    }

                    return [
                        'space_id' => $candidateId,
                        'label' => $this->spaceLabel($candidate),
                        'tenant_name' => $candidate->tenant?->name,
                        'match_source' => $item['match_source'],
                        'match_sources' => $item['match_sources'],
                        'match_reason' => $item['match_reason'],
                        'relation_counts' => $this->compactRelationCounts($counts),
                        'has_map' => (int) ($counts['map_shapes'] ?? 0) > 0,
                        'relation_score' => $candidateScore,
                        'is_stronger_than_current' => $candidateScore > $currentScore,
                        'can_apply_duplicate_resolution' => $canApplyDuplicateResolution,
                        'duplicate_resolution_block_reason' => $duplicateResolutionBlockReason,
                        'is_explicit_duplicate_scenario' => $isExplicitDuplicateScenario,
                        'resolution_decision' => $sameTenantContour
                            ? SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION
                            : SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL,
                        'resolution_reason' => $sameTenantContour
                            ? 'Можно разобрать как дубль с переносом безопасных связей.'
                            : 'Арендаторы отличаются: безопаснее упразднить дубль без переноса договоров и начислений.',
                        'contract_details' => $contractDetails[$candidateId] ?? [],
                        'accrual_details' => $accrualDetails[$candidateId] ?? [],
                    ];
                })
                ->values()
                ->all();

            $hasStrongerCandidate = collect($candidates)
                ->contains(fn (array $candidate): bool => (bool) ($candidate['is_stronger_than_current'] ?? false));

            $resolvedFinancialSignal = $financialSignals[$spaceId] ?? null;

            return [
                $spaceId => [
                    'relation_counts' => $this->displayRelationCounts($counts),
                    'relation_details' => $this->buildRelationDetails($counts),
                    'candidate_spaces' => $candidates,
                    'has_candidates' => $candidates !== [],
                    'relation_assessment' => $this->relationAssessment($counts, $candidates, $contractOverride),
                    'has_stronger_candidate' => $hasStrongerCandidate,
                    'current_place_confirmed_by_contract' => $contractOverride !== null,
                    'contract_override' => $contractOverride,
                    'contract_details' => $contractDetails[$spaceId] ?? [],
                    'accrual_details' => $accrualDetails[$spaceId] ?? [],
                    'financial_signal' => $resolvedFinancialSignal,
                ],
            ];
        })->all();
    }

    /**
     * @param  Collection<int, MarketSpace>  $spaces
     * @param  Collection<int, Operation>  $latestOperations
     * @return array<int, list<int>>
     */
    private function observedTenantIdsBySpace(int $marketId, Collection $spaces, Collection $latestOperations): array
    {
        if ($marketId <= 0 || $spaces->isEmpty() || $latestOperations->isEmpty() || ! Schema::hasTable('tenants')) {
            return [];
        }

        $hintsBySpace = [];

        foreach ($spaces as $space) {
            $spaceId = (int) $space->id;
            $operation = $latestOperations->get($spaceId);
            $payload = is_array($operation?->payload) ? $operation->payload : [];
            $hints = array_values(array_filter([
                $payload['observed_tenant_name'] ?? null,
                $payload['reason'] ?? null,
                $operation?->comment,
            ], fn ($value): bool => filled($value)));

            foreach ($hints as $hint) {
                $normalized = $this->normalizeTenantMatchText((string) $hint);

                if (mb_strlen($normalized, 'UTF-8') >= 4) {
                    $hintsBySpace[$spaceId][] = $normalized;
                }
            }
        }

        if ($hintsBySpace === []) {
            return [];
        }

        $columns = ['id', 'name'];
        if (Schema::hasColumn('tenants', 'short_name')) {
            $columns[] = 'short_name';
        }

        $tenants = Tenant::query()
            ->where('market_id', $marketId)
            ->when(
                Schema::hasColumn('tenants', 'is_active'),
                fn ($query) => $query->where('is_active', true)
            )
            ->get($columns)
            ->map(function (Tenant $tenant): array {
                $names = array_filter([
                    $tenant->name,
                    $tenant->short_name ?? null,
                ], fn ($value): bool => filled($value));

                return [
                    'id' => (int) $tenant->id,
                    'tokens' => array_map(fn ($name): string => $this->normalizeTenantMatchText((string) $name), $names),
                ];
            });

        $result = [];

        foreach ($hintsBySpace as $spaceId => $hints) {
            foreach ($tenants as $tenant) {
                $matchedTenant = false;

                foreach ($hints as $hint) {
                    foreach ($tenant['tokens'] as $token) {
                        if ($token === '') {
                            continue;
                        }

                        if (str_contains($hint, $token) || str_contains($token, $hint)) {
                            $result[(int) $spaceId][] = (int) $tenant['id'];
                            $matchedTenant = true;
                            break 2;
                        }
                    }
                }

                if ($matchedTenant) {
                    continue;
                }
            }
        }

        return array_map(
            fn (array $tenantIds): array => array_values(array_unique(array_map('intval', $tenantIds))),
            $result
        );
    }

    /**
     * @param  Collection<int, MarketSpace>  $spaces
     * @return Collection<int, MarketSpace>
     */
    private function nameDuplicateCandidateSpaces(int $marketId, Collection $spaces): Collection
    {
        if ($marketId <= 0 || $spaces->isEmpty()) {
            return collect();
        }

        $hasNameTokens = $spaces
            ->contains(fn (MarketSpace $space): bool => $this->spaceNameDuplicateTokens($space) !== []);

        if (! $hasNameTokens) {
            return collect();
        }

        $locationIds = $spaces
            ->pluck('location_id')
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return MarketSpace::query()
            ->with(['tenant:id,name'])
            ->where('market_id', $marketId)
            ->when(
                Schema::hasColumn('market_spaces', 'is_active'),
                fn ($query) => $query->where('is_active', true)
            )
            ->when($locationIds !== [], fn ($query) => $query->whereIn('location_id', $locationIds))
            ->orderByRaw('COALESCE(number, display_name, code) asc')
            ->get(array_merge(
                ['id', 'location_id', 'tenant_id', 'number', 'display_name', 'code'],
                Schema::hasColumn('market_spaces', 'space_group_role') ? ['space_group_role'] : []
            ));
    }

    /**
     * @return list<string>
     */
    private function spaceNameDuplicateTokens(MarketSpace $space): array
    {
        $tokens = [];

        foreach ([$space->number, $space->display_name, $space->code] as $value) {
            $normalized = $this->normalizeSpaceDuplicateName((string) $value);

            if ($normalized !== '' && $this->isUsableDuplicateNameToken($normalized)) {
                $tokens[] = $normalized;
            }
        }

        return array_values(array_unique($tokens));
    }

    private function normalizeSpaceDuplicateName(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = str_replace('ё', 'е', $normalized);
        $normalized = preg_replace('/\((?:[^)]*(?:просто\s+договор|договор|контракт|временн)[^)]*)\)/iu', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\b(?:просто\s+договор|договор|контракт|временн(?:ое|ый|ая)?)\b/iu', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        $parts = $normalized === '' ? [] : explode(' ', $normalized);
        $deduped = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === end($deduped)) {
                continue;
            }

            $deduped[] = $part;
        }

        return implode(' ', $deduped);
    }

    private function isUsableDuplicateNameToken(string $value): bool
    {
        if (mb_strlen($value, 'UTF-8') < 4) {
            return false;
        }

        if (preg_match('/\d/u', $value) !== 1) {
            return false;
        }

        return preg_match('/\p{L}/u', $value) === 1;
    }

    private function normalizeTenantMatchText(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/\b(ип|ооо|ао|пао|зао)\b/iu', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);

        return preg_replace('/(.)\1+/u', '$1', $normalized) ?? $normalized;
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int, array<string, int>>
     */
    private function relationCountsForSpaces(array $spaceIds): array
    {
        if ($spaceIds === []) {
            return [];
        }

        $definitions = [
            'map_shapes' => ['market_space_map_shapes', 'market_space_id'],
            'contracts' => ['tenant_contracts', 'market_space_id'],
            'accruals' => ['tenant_accruals', 'market_space_id'],
            'cabinet_users' => ['tenant_user_market_spaces', 'market_space_id'],
            'requests' => ['tenant_requests', 'market_space_id'],
            'tickets' => ['tickets', 'market_space_id'],
            'reviews' => ['tenant_reviews', 'market_space_id'],
            'showcases' => ['tenant_space_showcases', 'market_space_id'],
            'products' => ['marketplace_products', 'market_space_id'],
            'chats' => ['marketplace_chats', 'market_space_id'],
            'tenant_bindings' => ['market_space_tenant_bindings', 'market_space_id'],
        ];

        $result = [];

        foreach ($spaceIds as $spaceId) {
            $result[$spaceId] = array_fill_keys(array_keys($definitions), 0);
        }

        foreach ($definitions as $key => [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            DB::table($table)
                ->whereIn($column, $spaceIds)
                ->selectRaw($column . ' as space_id, count(*) as aggregate')
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
     * @param  array<string, int>  $counts
     * @return list<array{key:string,label:string,count:int,important:bool}>
     */
    private function displayRelationCounts(array $counts): array
    {
        $definitions = [
            'map_shapes' => ['label' => 'Карта', 'important' => true],
            'contracts' => ['label' => 'Договоры', 'important' => true],
            'accruals' => ['label' => 'Начисления', 'important' => true],
            'cabinet_users' => ['label' => 'Кабинет', 'important' => true],
            'requests' => ['label' => 'Заявки', 'important' => false],
            'tickets' => ['label' => 'Тикеты', 'important' => false],
            'reviews' => ['label' => 'Отзывы', 'important' => false],
            'showcases' => ['label' => 'Витрина', 'important' => false],
            'products' => ['label' => 'Товары', 'important' => false],
            'chats' => ['label' => 'Чаты', 'important' => false],
            'tenant_bindings' => ['label' => 'Связи', 'important' => false],
        ];

        $items = [];

        foreach ($definitions as $key => $meta) {
            $count = (int) ($counts[$key] ?? 0);

            if (! $meta['important'] && $count <= 0) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'count' => $count,
                'important' => (bool) $meta['important'],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private function compactRelationCounts(array $counts): array
    {
        return collect($this->displayRelationCounts($counts))
            ->filter(fn (array $item): bool => (bool) $item['important'] || (int) $item['count'] > 0)
            ->map(fn (array $item): string => $item['label'] . ': ' . (int) $item['count'])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key:string,label:string,count:int,description:string}>
     */
    private function buildRelationDetails(array $counts): array
    {
        $definitions = [
            'contracts' => [
                'label' => 'Договоры',
                'description' => 'По месту есть договорные связи. Это сильная бизнес-привязка.',
            ],
            'accruals' => [
                'label' => 'Начисления',
                'description' => 'По месту есть финансовый хвост в начислениях.',
            ],
            'map_shapes' => [
                'label' => 'Карта',
                'description' => 'У места есть фигуры и привязки на карте.',
            ],
            'cabinet_users' => [
                'label' => 'Кабинет',
                'description' => 'Есть пользовательские привязки в кабинете арендатора.',
            ],
            'tenant_bindings' => [
                'label' => 'Связи',
                'description' => 'Есть tenant bindings и исторические привязки места.',
            ],
            'requests' => [
                'label' => 'Заявки',
                'description' => 'По месту заведены заявки, которые могут зависеть от него.',
            ],
            'tickets' => [
                'label' => 'Тикеты',
                'description' => 'По месту есть тикеты или обращения.',
            ],
            'reviews' => [
                'label' => 'Отзывы',
                'description' => 'По месту привязаны отзывы арендаторов.',
            ],
            'showcases' => [
                'label' => 'Витрина',
                'description' => 'Есть публикации или витрины, связанные с местом.',
            ],
            'products' => [
                'label' => 'Товары',
                'description' => 'Есть товары маркетплейса, опубликованные с этого места.',
            ],
            'chats' => [
                'label' => 'Чаты',
                'description' => 'Есть чаты маркетплейса, связанные с местом.',
            ],
        ];

        $items = [];

        foreach ($definitions as $key => $meta) {
            $count = (int) ($counts[$key] ?? 0);

            if ($count <= 0) {
                continue;
            }

            $items[] = [
                'key' => $key,
                'label' => $meta['label'],
                'count' => $count,
                'description' => $meta['description'],
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function relationStrengthScore(array $counts): int
    {
        return ((int) ($counts['contracts'] ?? 0) * 5)
            + ((int) ($counts['accruals'] ?? 0) * 3)
            + ((int) ($counts['tenant_bindings'] ?? 0) * 3)
            + ((int) ($counts['map_shapes'] ?? 0) * 2)
            + ((int) ($counts['cabinet_users'] ?? 0) * 2)
            + ((int) ($counts['products'] ?? 0) > 0 ? 1 : 0);
    }

    /**
     * @param  array<string, int>  $currentCounts
     * @param  list<array<string, mixed>>  $candidates
     */
    private function relationAssessment(array $currentCounts, array $candidates, ?array $contractOverride = null): string
    {
        if ($contractOverride !== null) {
            $tenantName = trim((string) ($contractOverride['tenant_name'] ?? ''));
            $startDate = trim((string) ($contractOverride['starts_at_label'] ?? ''));
            $contractNumber = trim((string) ($contractOverride['contract_number'] ?? ''));

            $parts = [];
            $parts[] = $tenantName !== ''
                ? 'На текущем месте уже есть активный договор нового арендатора: ' . $tenantName . '.'
                : 'На текущем месте уже есть активный договор нового арендатора.';

            if ($contractNumber !== '') {
                $parts[] = 'Договор: ' . $contractNumber . '.';
            }

            $parts[] = 'Старые начисления и долги прежнего арендатора считаются финансовым хвостом и не отменяют смену текущего арендатора места.';

            return implode(' ', $parts);
        }

        if ($candidates === []) {
            return 'Других активных мест этого арендатора не найдено. Каноническое место не определяется автоматически.';
        }

        $currentScore = $this->relationStrengthScore($currentCounts);
        $bestCandidate = collect($candidates)
            ->sortByDesc(fn (array $candidate): int => (int) ($candidate['relation_score'] ?? 0))
            ->first();
        $bestScore = (int) ($bestCandidate['relation_score'] ?? 0);

        if ($bestScore > $currentScore) {
            return 'Есть кандидат с более сильными подтверждёнными связями. Его нужно проверить как возможное основное место.';
        }

        return 'Текущее место не слабее кандидатов по подтверждённым связям. Не выбирайте кандидата основным без дополнительной проверки.';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDiagnostics(): array
    {
        return [
            'relation_counts' => [],
            'candidate_spaces' => [],
            'has_candidates' => false,
            'has_stronger_candidate' => false,
            'current_place_confirmed_by_contract' => false,
            'contract_override' => null,
            'contract_details' => [],
            'accrual_details' => [],
            'financial_signal' => null,
            'can_apply_duplicate_resolution' => true,
            'duplicate_resolution_block_reason' => null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function financialTenantSignals(int $marketId, int $limit = 50): array
    {
        if ($marketId <= 0
            || ! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'tenant_id')
            || ! Schema::hasColumn('tenant_accruals', 'market_space_id')
            || ! Schema::hasColumn('tenant_accruals', 'tenant_contract_id')
            || ! Schema::hasTable('market_spaces')
            || ! Schema::hasTable('tenants')) {
            return [];
        }

        $latestMarketAccrualPeriod = Schema::hasColumn('tenant_accruals', 'period')
            ? $this->latestAccrualPeriodForMarket($marketId)
            : null;

        $select = [
            'ta.id as accrual_id',
            'ta.market_space_id as space_id',
            'ta.tenant_id as accrual_tenant_id',
            'ms.tenant_id as current_tenant_id',
            'at.name as accrual_tenant_name',
            'ct.name as current_tenant_name',
        ];

        if (Schema::hasColumn('tenants', 'external_id')) {
            $select[] = 'at.external_id as accrual_tenant_external_id';
        }

        if (Schema::hasColumn('tenants', 'inn')) {
            $select[] = 'at.inn as accrual_tenant_inn';
        }

        if (Schema::hasColumn('tenants', 'kpp')) {
            $select[] = 'at.kpp as accrual_tenant_kpp';
        }

        if (Schema::hasColumn('tenants', 'is_active')) {
            $select[] = 'at.is_active as accrual_tenant_is_active';
        }

        if (Schema::hasColumn('tenant_accruals', 'period')) {
            $select[] = 'ta.period as period';
        }

        if (Schema::hasColumn('tenant_accruals', 'contract_link_status')) {
            $select[] = 'ta.contract_link_status as contract_link_status';
        }

        if (Schema::hasColumn('tenant_accruals', 'contract_link_note')) {
            $select[] = 'ta.contract_link_note as contract_link_note';
        }

        if (Schema::hasColumn('tenant_accruals', 'source_file')) {
            $select[] = 'ta.source_file as source_file';
        }

        if (Schema::hasColumn('tenant_accruals', 'imported_at')) {
            $select[] = 'ta.imported_at as imported_at';
        }

        if (Schema::hasColumn('tenant_accruals', 'source')) {
            $select[] = 'ta.source as source';
        }

        if (Schema::hasColumn('tenant_accruals', 'payload')) {
            $select[] = 'ta.payload as payload';
        }

        $rows = DB::table('tenant_accruals as ta')
            ->select($select)
            ->join('market_spaces as ms', function ($join): void {
                $join->on('ms.id', '=', 'ta.market_space_id')
                    ->on('ms.market_id', '=', 'ta.market_id');
            })
            ->leftJoin('tenants as at', function ($join): void {
                $join->on('at.id', '=', 'ta.tenant_id')
                    ->on('at.market_id', '=', 'ta.market_id');
            })
            ->leftJoin('tenants as ct', function ($join): void {
                $join->on('ct.id', '=', 'ms.tenant_id')
                    ->on('ct.market_id', '=', 'ms.market_id');
            })
            ->where('ta.market_id', $marketId)
            ->whereNull('ta.tenant_contract_id')
            ->whereNotNull('ta.market_space_id')
            ->whereNotNull('ta.tenant_id')
            ->where(function ($query): void {
                $query
                    ->whereNull('ms.tenant_id')
                    ->orWhereColumn('ms.tenant_id', '<>', 'ta.tenant_id');
            })
            ->when(
                Schema::hasColumn('market_spaces', 'is_active'),
                fn ($query) => $query->where('ms.is_active', true)
            )
            ->when(
                $latestMarketAccrualPeriod !== null,
                fn ($query) => $query->whereDate('ta.period', '=', $latestMarketAccrualPeriod)
            )
            ->orderByDesc(Schema::hasColumn('tenant_accruals', 'period') ? 'ta.period' : 'ta.id')
            ->orderByDesc('ta.id')
            ->limit(max($limit * 10, 100))
            ->get();

        // Contract-contour guard: собрать активные контракты по найденным space_id
        $spaceIds = $rows->pluck('space_id')
            ->filter(fn ($id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $activeContracts = $this->activeContractsForAccrualSpaceIds($spaceIds, $marketId);

        $signals = [];

        foreach ($rows as $row) {
            $spaceId = (int) ($row->space_id ?? 0);

            if ($spaceId <= 0 || isset($signals[$spaceId])) {
                continue;
            }

            // Contract-contour guard: если есть активный контракт с другим tenant_id — пропускаем сигнал
            if (isset($activeContracts[$spaceId])) {
                $contractTenantId = (int) $activeContracts[$spaceId]['tenant_id'];
                $accrualTenantId = (int) ($row->accrual_tenant_id ?? 0);

                if ($contractTenantId > 0 && $contractTenantId !== $accrualTenantId) {
                    continue; // Пропускаем: активный контракт блокирует финансовый хвост
                }
            }

            $period = null;
            if (isset($row->period) && $row->period) {
                try {
                    $period = (new \DateTime((string) $row->period))->format('m.Y');
                } catch (\Throwable) {
                    $period = (string) $row->period;
                }
            }

            $importedAtLabel = null;
            if (isset($row->imported_at) && $row->imported_at) {
                try {
                    $importedAtLabel = (new \DateTime((string) $row->imported_at))->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $importedAtLabel = (string) $row->imported_at;
                }
            }

            $payload = $this->decodeJsonArray($row->payload ?? null);
            $source = isset($row->source) ? (string) $row->source : 'tenant_accruals';
            $tenantExternalId = trim((string) ($payload['tenant_external_id'] ?? ($row->accrual_tenant_external_id ?? '')));
            $tenantInn = trim((string) ($payload['inn'] ?? ($row->accrual_tenant_inn ?? '')));
            $tenantKpp = trim((string) ($payload['kpp'] ?? ($row->accrual_tenant_kpp ?? '')));
            $tenantName = trim((string) ($payload['tenant_name'] ?? ''));

            if ($tenantName === '') {
                $tenantName = trim((string) ($row->accrual_tenant_name ?? ''));
            }

            $tenantIsActive = isset($row->accrual_tenant_is_active)
                ? (bool) $row->accrual_tenant_is_active
                : (int) ($row->accrual_tenant_id ?? 0) > 0;
            $tenantId = (int) ($row->accrual_tenant_id ?? 0);
            $hasInactiveExistingTenant = $tenantId > 0 && ! $tenantIsActive;
            $isTrustedOneCExternalId = $source === '1c'
                && $tenantExternalId !== ''
                && preg_match('/^TEST_/i', $tenantExternalId) !== 1;

            // Low-trust tenant guard: TEST_* без inn/one_c_uid
            $isLowTrustTenant = preg_match('/^TEST_/i', $tenantExternalId) === 1
                && $tenantInn === ''
                && ! isset($payload['one_c_uid']);

            $existingTenantCandidate = $this->resolveFinancialSignalExistingTenantCandidate(
                $marketId,
                $tenantName,
                $tenantId,
                (int) ($row->current_tenant_id ?? 0),
                trim((string) ($row->current_tenant_name ?? ''))
            );
            $resolutionAction = $hasInactiveExistingTenant
                ? 'activate_existing_tenant'
                : 'create_or_match_tenant';

            if ($existingTenantCandidate !== null && (int) ($existingTenantCandidate['id'] ?? 0) !== $tenantId) {
                $resolutionAction = 'match_existing_tenant';
            }

            // Low-trust tenant не может быть сильным action-кандидатом
            if ($isLowTrustTenant && $resolutionAction === 'match_existing_tenant') {
                $resolutionAction = 'create_or_match_tenant';
            }

            $signals[$spaceId] = [
                'priority' => 100,
                'source' => $source,
                'label' => 'Финконтур сообщает о новом арендаторе',
                'accrual_id' => (int) ($row->accrual_id ?? 0),
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'tenant_external_id' => $isTrustedOneCExternalId ? $tenantExternalId : null,
                'tenant_inn' => $tenantInn !== '' ? $tenantInn : null,
                'tenant_kpp' => $tenantKpp !== '' ? $tenantKpp : null,
                'requires_tenant_resolution' => $tenantId <= 0 || ! $tenantIsActive,
                'resolution_action' => $resolutionAction,
                'existing_tenant_candidate_id' => (int) ($existingTenantCandidate['id'] ?? 0) ?: null,
                'existing_tenant_candidate_name' => $existingTenantCandidate['name'] ?? null,
                'current_tenant_id' => (int) ($row->current_tenant_id ?? 0),
                'current_tenant_name' => trim((string) ($row->current_tenant_name ?? '')),
                'latest_period_label' => $period,
                'latest_imported_at_label' => $importedAtLabel,
                'contract_link_status' => isset($row->contract_link_status) ? (string) $row->contract_link_status : null,
                'contract_link_note' => isset($row->contract_link_note) ? (string) $row->contract_link_note : null,
                'source_file' => isset($row->source_file) ? (string) $row->source_file : null,
            ];

            if (count($signals) >= $limit) {
                break;
            }
        }

        return $signals;
    }

    private function latestAccrualPeriodForMarket(int $marketId): ?string
    {
        $value = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->whereNotNull('period')
            ->max('period');

        return filled($value) ? (string) $value : null;
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int, array{tenant_id:int}>
     */
    private function activeContractsForAccrualSpaceIds(array $spaceIds, int $marketId): array
    {
        if ($spaceIds === [] || ! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        $query = DB::table('tenant_contracts as tc')
            ->select('tc.market_space_id as space_id', 'tc.tenant_id')
            ->join('market_spaces as ms', function ($join): void {
                $join->on('ms.id', '=', 'tc.market_space_id')
                    ->on('ms.market_id', '=', 'tc.market_id');
            })
            ->whereIn('tc.market_space_id', $spaceIds)
            ->where('tc.market_id', $marketId);

        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $query->where('tc.is_active', true);
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $query->whereNotIn('tc.status', ['terminated', 'archived']);
        }

        if (Schema::hasColumn('tenant_contracts', 'starts_at')) {
            $query->where(function ($inner): void {
                $inner->whereNull('tc.starts_at')
                    ->orWhere('tc.starts_at', '<=', now());
            });
        }

        if (Schema::hasColumn('tenant_contracts', 'ends_at')) {
            $query->where(function ($inner): void {
                $inner->whereNull('tc.ends_at')
                    ->orWhere('tc.ends_at', '>', now());
            });
        }

        $rows = $query->get();

        $result = [];
        foreach ($rows as $row) {
            $spaceId = (int) ($row->space_id ?? 0);
            if ($spaceId > 0 && ! isset($result[$spaceId])) {
                $result[$spaceId] = [
                    'tenant_id' => (int) ($row->tenant_id ?? 0),
                ];
            }
        }

        return $result;
    }

    private function financialSignalReason(MarketSpace $space, ?array $financialSignal): string
    {
        $tenantName = trim((string) ($financialSignal['tenant_name'] ?? ''));
        $currentTenantName = trim((string) ($financialSignal['current_tenant_name'] ?? ''));
        $spaceLabel = $this->spaceLabel($space);
        $period = trim((string) ($financialSignal['latest_period_label'] ?? ''));

        $reason = 'Финконтур сообщает о новом арендаторе';

        if ($tenantName !== '') {
            $reason .= ': ' . $tenantName;
        }

        $reason .= ' по месту ' . $spaceLabel;

        if ($period !== '') {
            $reason .= ' за ' . $period;
        }

        $reason .= '. Договор не найден';

        if ($currentTenantName !== '') {
            $reason .= ', карточка места связана с ' . $currentTenantName;
        }

        return $reason . '.';
    }

    /**
     * @return array{
     *   observed_tenant_name:?string,
     *   review_comment:?string,
     *   author_name:string,
     *   recorded_at:?string
     * }
     */
    private function financialTenantChangeDetails(array $financialSignal, ?string $createdAt): array
    {
        return [
            'observed_tenant_name' => trim((string) ($financialSignal['tenant_name'] ?? '')) ?: null,
            'observed_tenant_id' => (int) ($financialSignal['tenant_id'] ?? 0) ?: null,
            'observed_tenant_external_id' => trim((string) ($financialSignal['tenant_external_id'] ?? '')) ?: null,
            'observed_tenant_inn' => trim((string) ($financialSignal['tenant_inn'] ?? '')) ?: null,
            'observed_tenant_kpp' => trim((string) ($financialSignal['tenant_kpp'] ?? '')) ?: null,
            'requires_tenant_resolution' => (bool) ($financialSignal['requires_tenant_resolution'] ?? false),
            'resolution_action' => trim((string) ($financialSignal['resolution_action'] ?? '')) ?: null,
            'existing_tenant_candidate_id' => (int) ($financialSignal['existing_tenant_candidate_id'] ?? 0) ?: null,
            'existing_tenant_candidate_name' => trim((string) ($financialSignal['existing_tenant_candidate_name'] ?? '')) ?: null,
            'accrual_id' => (int) ($financialSignal['accrual_id'] ?? 0) ?: null,
            'review_comment' => 'Основание: начисление из финансового контура без найденного договора.',
            'author_name' => 'Система',
            'recorded_at' => $createdAt,
        ];
    }

    /**
     * @return array{id:int,name:string}|null
     */
    private function resolveFinancialSignalExistingTenantCandidate(
        int $marketId,
        string $observedTenantName,
        int $observedTenantId,
        int $currentTenantId,
        string $currentTenantName
    ): ?array {
        $observedTenantName = trim($observedTenantName);

        if ($marketId <= 0 || $observedTenantName === '') {
            return null;
        }

        if (
            $currentTenantId > 0
            && $currentTenantId !== $observedTenantId
            && $this->financialSignalTenantNamesLikelyMatch($observedTenantName, $currentTenantName)
        ) {
            return [
                'id' => $currentTenantId,
                'name' => $currentTenantName,
            ];
        }

        $candidateTenants = Tenant::query()
            ->where('market_id', $marketId)
            ->when(
                $observedTenantId > 0,
                fn ($query) => $query->whereKeyNot($observedTenantId)
            )
            ->get($this->tenantMatchColumns());

        $matches = $candidateTenants
            ->filter(function (Tenant $tenant) use ($observedTenantName): bool {
                foreach ($this->tenantMatchTexts($tenant) as $candidateName) {
                    if ($this->financialSignalTenantNamesLikelyMatch($observedTenantName, $candidateName)) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (Tenant $tenant): array => [
                'id' => (int) $tenant->id,
                'name' => $this->tenantMatchLabel($tenant),
            ])
            ->values();

        if ($matches->count() !== 1) {
            return null;
        }

        /** @var array{id:int,name:string} $match */
        $match = $matches->first();

        return $match;
    }

    private function financialSignalTenantNamesLikelyMatch(string $left, string $right): bool
    {
        $leftParts = $this->financialSignalTenantNameParts($left);
        $rightParts = $this->financialSignalTenantNameParts($right);

        if (($leftParts['surname'] ?? '') === '' || ($rightParts['surname'] ?? '') === '') {
            return false;
        }

        if ($leftParts['surname'] !== $rightParts['surname']) {
            return false;
        }

        $leftInitials = (string) ($leftParts['initials'] ?? '');
        $rightInitials = (string) ($rightParts['initials'] ?? '');

        if ($leftInitials !== '' && $rightInitials !== '') {
            return $leftInitials === $rightInitials;
        }

        return (bool) ($leftParts['has_only_surname'] ?? false)
            || (bool) ($rightParts['has_only_surname'] ?? false);
    }

    /**
     * @return array{surname:string,initials:string,has_only_surname:bool}
     */
    private function financialSignalTenantNameParts(string $value): array
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? $normalized;
        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stopwords = ['ип', 'ооо', 'зао', 'пао', 'ао', 'оао', 'нко', 'тд', 'тк', 'чп'];

        $filtered = array_values(array_filter($tokens, static function (string $token) use ($stopwords): bool {
            return $token !== '' && ! in_array($token, $stopwords, true);
        }));

        if ($filtered === []) {
            return [
                'surname' => '',
                'initials' => '',
                'has_only_surname' => false,
            ];
        }

        $surname = (string) ($filtered[0] ?? '');
        $rest = array_slice($filtered, 1);
        $initials = '';

        foreach ($rest as $token) {
            if (mb_strlen($token, 'UTF-8') <= 2) {
                $initials .= $token;

                continue;
            }

            $initials .= mb_substr($token, 0, 1, 'UTF-8');
        }

        return [
            'surname' => $surname,
            'initials' => $initials,
            'has_only_surname' => $rest === [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @param  list<int>  $spaceIds
     * @return array<int, array{
     *   tenant_id:int,
     *   tenant_name:string,
     *   contract_id:int,
     *   contract_number:?string,
     *   starts_at:?string,
     *   starts_at_label:?string,
     *   signed_at:?string,
     *   signed_at_label:?string
     * }>
     */
    private function activeContractOverrideForSpaces(array $spaceIds, int $marketId): array
    {
        if ($spaceIds === [] || ! Schema::hasTable('tenant_contracts')) {
            return [];
        }

        $query = TenantContract::query()
            ->from('tenant_contracts as tc')
            ->join('market_spaces as ms', function ($join): void {
                $join->on('ms.id', '=', 'tc.market_space_id')
                    ->on('ms.market_id', '=', 'tc.market_id');
            })
            ->leftJoin('tenants as t', function ($join): void {
                $join->on('t.id', '=', 'tc.tenant_id')
                    ->on('t.market_id', '=', 'tc.market_id');
            })
            ->where('tc.market_id', $marketId)
            ->whereIn('tc.market_space_id', $spaceIds)
            ->whereColumn('tc.tenant_id', '<>', 'ms.tenant_id');

        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $query->where('tc.is_active', true);
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $query->whereNotIn('tc.status', ['terminated', 'archived']);
        }

        if (Schema::hasColumn('tenant_contracts', 'starts_at')) {
            $query->where(function ($inner): void {
                $inner->whereNull('tc.starts_at')
                    ->orWhere('tc.starts_at', '<=', now());
            });
        }

        if (Schema::hasColumn('tenant_contracts', 'ends_at')) {
            $query->where(function ($inner): void {
                $inner->whereNull('tc.ends_at')
                    ->orWhere('tc.ends_at', '>', now());
            });
        }

        $rows = $query
            ->orderByDesc('tc.starts_at')
            ->orderByDesc('tc.id')
            ->get([
                'tc.id as contract_id',
                'tc.market_space_id as space_id',
                'tc.tenant_id',
                'tc.number as contract_number',
                'tc.starts_at',
                'tc.signed_at',
                't.name as tenant_name',
            ]);

        $result = [];

        foreach ($rows as $row) {
            $spaceId = (int) ($row->space_id ?? 0);

            if ($spaceId <= 0 || array_key_exists($spaceId, $result)) {
                continue;
            }

            $startsAtRaw = $row->starts_at;
            $startsAt = null;
            $startsAtLabel = null;

            if ($startsAtRaw instanceof \DateTimeInterface) {
                $startsAt = $startsAtRaw->format('Y-m-d');
                $startsAtLabel = $startsAtRaw->format('d.m.Y');
            } elseif (filled($startsAtRaw)) {
                $startsAt = (string) $startsAtRaw;

                try {
                    $startsAtLabel = (new \DateTimeImmutable($startsAt))->format('d.m.Y');
                } catch (\Throwable) {
                    $startsAtLabel = $startsAt;
                }
            }

            $signedAtRaw = $row->signed_at;
            $signedAt = null;
            $signedAtLabel = null;

            if ($signedAtRaw instanceof \DateTimeInterface) {
                $signedAt = $signedAtRaw->format('Y-m-d');
                $signedAtLabel = $signedAtRaw->format('d.m.Y');
            } elseif (filled($signedAtRaw)) {
                $signedAt = (string) $signedAtRaw;

                try {
                    $signedAtLabel = (new \DateTimeImmutable($signedAt))->format('d.m.Y');
                } catch (\Throwable) {
                    $signedAtLabel = $signedAt;
                }
            }

            $result[$spaceId] = [
                'tenant_id' => (int) ($row->tenant_id ?? 0),
                'tenant_name' => trim((string) ($row->tenant_name ?? '')),
                'contract_id' => (int) ($row->contract_id ?? 0),
                'contract_number' => filled($row->contract_number ?? null) ? (string) $row->contract_number : null,
                'starts_at' => $startsAt,
                'starts_at_label' => $startsAtLabel,
                'signed_at' => $signedAt,
                'signed_at_label' => $signedAtLabel,
            ];
        }

        return $result;
    }

    private function spaceLabel(MarketSpace $space): string
    {
        $number = trim((string) ($space->number ?? ''));
        $name = trim((string) ($space->display_name ?? ''));

        if ($number !== '' && $name !== '' && $number !== $name) {
            return $number . ' / ' . $name;
        }

        return $number !== '' ? $number : ($name !== '' ? $name : ('#' . (int) $space->id));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   observed_tenant_name:?string,
     *   review_comment:?string,
     *   author_name:?string,
     *   recorded_at:?string
     * }|null
     */
    private function reviewerTenantNameFromReason(string $reason): string
    {
        $source = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');

        if ($source === '') {
            return '';
        }

        if (preg_match('/арендатор\s+(?:стал|стала|сменился|сменился\s+на|теперь|новый)?\s*[:—-]?\s*(.+?)(?:\.|$)/iu', $source, $matches) !== 1) {
            return '';
        }

        $value = trim((string) ($matches[1] ?? ''));
        $value = preg_replace('/^(стал|стала)\s+/iu', '', $value) ?? $value;
        $value = preg_replace('/\s+(стоит|уже|договор|по\s+данным|после|старый|старые)\b.*$/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B.;,:—-");

        return mb_strlen($value, 'UTF-8') >= 3 ? $value : '';
    }

    private function tenantChangeDetails(
        ?string $decision,
        array $payload,
        ?string $createdByName,
        ?string $createdAt,
        ?string $reason
    ): ?array {
        if ($decision !== SpaceReviewDecision::TENANT_CHANGED_ON_SITE) {
            return null;
        }

        $observedTenantName = filled($payload['observed_tenant_name'] ?? null)
            ? trim((string) $payload['observed_tenant_name'])
            : null;
        $authorName = filled($createdByName) ? trim((string) $createdByName) : null;
        $recordedAt = filled($createdAt) ? trim((string) $createdAt) : null;

        if ($observedTenantName === null && $reason === null && $authorName === null && $recordedAt === null) {
            return null;
        }

        return [
            'observed_tenant_name' => $observedTenantName,
            'review_comment' => $reason,
            'author_name' => $authorName,
            'recorded_at' => $recordedAt,
        ];
    }

    /**
     * @return list<array{
     *   operation_id:int,
     *   space_id:int,
     *   number:?string,
     *   display_name:?string,
     *   location_name:?string,
     *   decision:string,
     *   decision_label:string,
     *   summary:string,
     *   effective_at:?string,
     *   created_by_name:?string,
     *   review_status:?string,
     *   review_status_label:?string
     * }>
     */
    public function appliedChanges(int $marketId, int $limit = 50): array
    {
        if ($marketId <= 0) {
            return [];
        }

        $operations = Operation::query()
            ->where('market_id', $marketId)
            ->whereIn('entity_type', ['market_space', MarketSpace::class])
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'entity_id',
                'effective_at',
                'created_at',
                'created_by',
                'payload',
            ]);

        if ($operations->isEmpty()) {
            return [];
        }

        $spaceIds = $operations->pluck('entity_id')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $candidateSpaceIds = $operations
            ->map(function (Operation $operation): int {
                $payload = is_array($operation->payload) ? $operation->payload : [];

                return (int) ($payload['candidate_market_space_id']
                    ?? data_get($payload, 'duplicate_resolution.candidate_market_space_id')
                    ?? 0);
            })
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $spaces = MarketSpace::query()
            ->with(['location:id,name'])
            ->whereIn('id', array_values(array_unique(array_merge($spaceIds, $candidateSpaceIds))))
            ->get([
                'id',
                'location_id',
                'number',
                'display_name',
                'code',
                'map_review_status',
            ])
            ->keyBy('id');

        $creatorIds = $operations->pluck('created_by')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $creators = User::query()
            ->whereIn('id', $creatorIds)
            ->pluck('name', 'id');

        return $operations->map(function (Operation $operation) use ($spaces, $creators, $marketId): array {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $spaceId = (int) ($operation->entity_id ?? 0);
            /** @var MarketSpace|null $space */
            $space = $spaces->get($spaceId);
            $decision = (string) ($payload['decision'] ?? '');
            $sourceReviewStatus = trim((string) ($payload['source_review_status'] ?? ''));

            $isAutoClosed = (bool) ($payload['auto_closed_by_reconciliation'] ?? false);
            $autoCloseAt = $payload['auto_close_at'] ?? null;
            $autoCloseBindingId = $payload['auto_close_binding_id'] ?? null;

            $linkedTenantSwitchOperationId = null;
            $canFixEffectiveDate = false;
            $effectiveDateFixBlockReason = null;
            $currentEffectiveAt = null;
            $linkedTenantSwitchEffectiveAt = null;
            $currentEffectiveDateLabel = null;

            if ($decision !== 'matched') {
                $canFixEffectiveDate = false;
                $linkedTenantSwitchOperationId = null;
                $effectiveDateFixBlockReason = 'Исправление даты доступно только для применённой смены арендатора.';
            } elseif ($decision === 'matched' && $operation->effective_at instanceof \DateTimeInterface) {
                $currentEffectiveAt = $operation->effective_at->toIso8601String();

                $tenantSwitch = Operation::query()
                    ->where('market_id', $marketId)
                    ->whereIn('entity_type', ['market_space', \App\Models\MarketSpace::class])
                    ->where('entity_id', $spaceId)
                    ->where('type', OperationType::TENANT_SWITCH)
                    ->where('status', 'applied')
                    ->whereDate('effective_at', $operation->effective_at->format('Y-m-d'))
                    ->where('created_by', $operation->created_by)
                    ->where('id', '<', $operation->id)
                    ->orderByDesc('id')
                    ->first(['id', 'effective_at']);

                if ($tenantSwitch) {
                    $linkedTenantSwitchOperationId = (int) $tenantSwitch->id;
                    $linkedTenantSwitchEffectiveAt = $tenantSwitch->effective_at?->toIso8601String();
                    $canFixEffectiveDate = true;
                    $effectiveDateFixBlockReason = null;
                    $currentEffectiveDateLabel = $tenantSwitch->effective_at?->format('d.m.Y');
                } else {
                    $effectiveDateFixBlockReason = 'Связанная операция смены арендатора не найдена.';
                }
            }

            return [
                'operation_id' => (int) $operation->id,
                'space_id' => $spaceId,
                'number' => $space?->number,
                'display_name' => $space?->display_name,
                'location_name' => $space?->location?->name,
                'decision' => $decision,
                'decision_label' => $decision === 'matched'
                    ? 'Подтверждено'
                    : (SpaceReviewDecision::labels()[$decision] ?? $decision),
                'summary' => $this->appliedDecisionSummary(
                    $decision,
                    $payload,
                    $space,
                    $spaces->get((int) ($payload['candidate_market_space_id']
                        ?? data_get($payload, 'duplicate_resolution.candidate_market_space_id')
                        ?? 0))
                ),
                'effective_at' => $operation->effective_at?->format('d.m.Y H:i'),
                'created_by_name' => $operation->created_by ? (string) ($creators[(int) $operation->created_by] ?? '—') : null,
                'review_status' => $sourceReviewStatus !== '' ? $sourceReviewStatus : $space?->map_review_status,
                'review_status_label' => $this->reviewStatusLabel($sourceReviewStatus !== '' ? $sourceReviewStatus : $space?->map_review_status),
                'is_auto_closed' => $isAutoClosed,
                'auto_close_at' => $autoCloseAt ? (new \DateTime($autoCloseAt))->format('d.m.Y H:i') : null,
                'auto_close_binding_id' => $autoCloseBindingId ? (int) $autoCloseBindingId : null,
                'linked_tenant_switch_operation_id' => $linkedTenantSwitchOperationId,
                'can_fix_effective_date' => $canFixEffectiveDate,
                'effective_date_fix_block_reason' => $effectiveDateFixBlockReason,
                'current_effective_at' => $currentEffectiveAt,
                'linked_tenant_switch_effective_at' => $linkedTenantSwitchEffectiveAt,
                'current_effective_date_label' => $currentEffectiveDateLabel,
            ];
        })->all();
    }

    /**
     * @param  list<int>  $spaceIds
     * @return Collection<int, Operation>
     */
    private function latestSpaceReviewOperationsBySpace(int $marketId, array $spaceIds): Collection
    {
        if ($spaceIds === []) {
            return collect();
        }

        return Operation::query()
            ->where('market_id', $marketId)
            ->whereIn('entity_type', ['market_space', MarketSpace::class])
            ->where('type', OperationType::SPACE_REVIEW)
            ->whereIn('entity_id', $spaceIds)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'entity_id',
                'effective_at',
                'created_at',
                'created_by',
                'status',
                'payload',
            ])
            ->unique(fn (Operation $operation): int => (int) ($operation->entity_id ?? 0))
            ->keyBy(fn (Operation $operation): int => (int) ($operation->entity_id ?? 0));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appliedDecisionSummary(
        string $decision,
        array $payload,
        ?MarketSpace $space = null,
        ?MarketSpace $candidate = null
    ): string
    {
        return match ($decision) {
            'matched' => $this->matchedReviewSummary($payload),
            SpaceReviewDecision::BIND_SHAPE_TO_SPACE => 'Фигура привязана к месту'
                . (filled($payload['shape_id'] ?? null) ? ' · shape #' . (int) $payload['shape_id'] : ''),
            SpaceReviewDecision::UNBIND_SHAPE_FROM_SPACE => 'Фигура отвязана от места'
                . (filled($payload['shape_id'] ?? null) ? ' · shape #' . (int) $payload['shape_id'] : ''),
            SpaceReviewDecision::MARK_SPACE_FREE => 'Место отмечено как свободное',
            SpaceReviewDecision::MARK_SPACE_SERVICE => 'Место отмечено как служебное',
            SpaceReviewDecision::FIX_SPACE_IDENTITY => $this->identityFixSummary($payload),
            SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION => $this->duplicateResolutionSummary(
                $payload,
                $space,
                $candidate
            ),
            default => '—',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function matchedReviewSummary(array $payload): string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));

        return $reason !== '' ? $reason : 'Ревизия подтверждена и закрыта.';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function duplicateResolutionSummary(array $payload, ?MarketSpace $space, ?MarketSpace $candidate): string
    {
        $transferCounts = data_get($payload, 'duplicate_resolution.transfer_counts', []);
        $blockingCounts = data_get($payload, 'duplicate_resolution.blocking_counts', []);
        $classification = data_get($payload, 'duplicate_resolution.classification', null);
        $retainedFinancialTail = data_get($payload, 'duplicate_resolution.retained_financial_tail', null);
        $candidateId = (int) ($payload['candidate_market_space_id']
            ?? data_get($payload, 'duplicate_resolution.candidate_market_space_id')
            ?? 0);

        // Verdict для classification
        if ($classification === 'duplicate_with_historical_financial_tail') {
            $parts = [];

            if ($candidateId > 0) {
                $candidateLabel = $candidate ? $this->spaceLabel($candidate) : ('#' . $candidateId);
                $parts[] = 'Рекомендация: оставить #' . $candidateId . ' основным (' . $candidateLabel . ')';
            }

            if ($space) {
                $parts[] = 'Дубль #'.(int) $space->id.' содержит исторический финансовый хвост';
            }

            if (is_array($retainedFinancialTail) && ($retainedFinancialTail['accruals_count'] ?? 0) > 0) {
                $accrualsCount = (int) $retainedFinancialTail['accruals_count'];
                $latestPeriod = $retainedFinancialTail['latest_period'] ?? null;
                $periodText = $latestPeriod
                    ? ' (последний период: ' . \Carbon\Carbon::parse($latestPeriod)->format('m.Y') . ')'
                    : '';
                $parts[] = $accrualsCount . ' несопоставленных начисл. останутся на дубле' . $periodText;
            } else {
                $parts[] = 'Начисления останутся на дубле как история';
            }

            if (is_array($transferCounts) && array_sum($transferCounts) > 0) {
                $parts[] = 'Переносимые связи: карта ' . (int) ($transferCounts['map_shapes'] ?? 0)
                    . ', кабинет ' . (int) ($transferCounts['cabinet_links'] ?? 0)
                    . ', товары ' . (int) ($transferCounts['marketplace_products'] ?? 0);
            } else {
                $parts[] = 'Безопасные связи перенесены согласно плану разбора';
            }

            $parts[] = 'Договоры, начисления и долги не переносились';

            return implode(' · ', $parts);
        }

        if ($classification === 'safe_duplicate_no_financials') {
            $parts = [];

            if ($candidateId > 0) {
                $candidateLabel = $candidate ? $this->spaceLabel($candidate) : ('#' . $candidateId);
                $parts[] = 'Основное: #' . $candidateId . ' · ' . $candidateLabel;
            }

            if ($space) {
                $parts[] = 'Дубль выведен из контура: #' . (int) $space->id . ' · ' . $this->spaceLabel($space);
            }

            if (is_array($transferCounts)) {
                $parts[] = 'Перенесено: карта ' . (int) ($transferCounts['map_shapes'] ?? 0)
                    . ', кабинет ' . (int) ($transferCounts['cabinet_links'] ?? 0)
                    . ', товары ' . (int) ($transferCounts['marketplace_products'] ?? 0);
            }

            if (is_array($blockingCounts)) {
                $parts[] = 'Блокирующие связи на дубле: договоры ' . (int) ($blockingCounts['contracts'] ?? 0)
                    . ', начисления ' . (int) ($blockingCounts['accruals'] ?? 0);
            }

            $parts[] = 'Финансовых связей на дубле не найдено';
            $parts[] = 'Безопасные связи перенесены';
            $parts[] = 'Договоры, начисления и долги не переносились';

            return implode(' · ', $parts);
        }

        // Blocking / ambiguous classifications
        if ($classification === 'ambiguous_canonical_candidate') {
            return 'Авторазбор невозможен: требуется ручная проверка (нет безопасных связей для переноса)';
        }

        if ($classification === 'duplicate_with_blocking_contracts') {
            return 'Разбор заблокирован: дубль имеет активные договоры';
        }

        if ($classification === 'duplicate_with_blocking_accruals' || $classification === 'duplicate_fresh_accruals_conflict') {
            return 'Разбор заблокирован: дубль имеет блокирующие начисления';
        }

        // Дефолтный текст (без classification или неизвестный)
        $parts = [];

        if ($candidateId > 0) {
            $candidateLabel = $candidate ? $this->spaceLabel($candidate) : ('#' . $candidateId);
            $parts[] = 'Основное: #' . $candidateId . ' · ' . $candidateLabel;
        }

        if ($space) {
            $parts[] = 'Дубль выведен из контура: #' . (int) $space->id . ' · ' . $this->spaceLabel($space);
        }

        if (is_array($transferCounts)) {
            $parts[] = 'Перенесено: карта ' . (int) ($transferCounts['map_shapes'] ?? 0)
                . ', кабинет ' . (int) ($transferCounts['cabinet_links'] ?? 0)
                . ', товары ' . (int) ($transferCounts['marketplace_products'] ?? 0);
        }

        if (is_array($blockingCounts)) {
            $parts[] = 'Блокирующие связи на дубле: договоры ' . (int) ($blockingCounts['contracts'] ?? 0)
                . ', начисления ' . (int) ($blockingCounts['accruals'] ?? 0);
        }

        $parts[] = 'Договоры, начисления и долги не переносились';

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function identityFixSummary(array $payload): string
    {
        $parts = [];

        if (array_key_exists('number', $payload)) {
            $value = trim((string) ($payload['number'] ?? ''));
            $parts[] = 'Номер: ' . ($value !== '' ? $value : '—');
        }

        if (array_key_exists('display_name', $payload)) {
            $value = trim((string) ($payload['display_name'] ?? ''));
            $parts[] = 'Название: ' . ($value !== '' ? $value : '—');
        }

        return $parts !== [] ? implode(' · ', $parts) : 'Уточнены номер и/или название';
    }

    /**
     * @param  array<int> $spaceIds
     * @param  int $marketId
     * @return array<int, array<array<string, mixed>>>
     */
    private function contractDetailsForSpaces(array $spaceIds, int $marketId): array
    {
        if (empty($spaceIds) || ! Schema::hasTable('tenant_contracts') || ! Schema::hasColumn('tenant_contracts', 'market_space_id')) {
            return [];
        }

        // Build select list dynamically to avoid selecting missing columns on different schemas.
        $select = ['tc.id as id', 'tc.market_space_id as market_space_id'];

        if (Schema::hasColumn('tenant_contracts', 'number')) {
            $select[] = 'tc.number as number';
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'name')) {
            $select[] = 't.name as tenant_name';
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $select[] = 'tc.status as status';
        }

        if (Schema::hasColumn('tenant_contracts', 'starts_at')) {
            $select[] = 'tc.starts_at as starts_at';
        }

        if (Schema::hasColumn('tenant_contracts', 'ends_at')) {
            $select[] = 'tc.ends_at as ends_at';
        }

        $query = DB::table('tenant_contracts as tc')
            ->select($select)
            ->leftJoin('tenants as t', function ($join): void {
                $join->on('t.id', '=', 'tc.tenant_id');
            })
            ->whereIn('tc.market_space_id', $spaceIds)
            ->orderBy('tc.market_space_id')
            ->orderByDesc(Schema::hasColumn('tenant_contracts', 'starts_at') ? 'tc.starts_at' : 'tc.id')
            ->orderByDesc('tc.id');

        $results = $query->get();

        $contractsBySpace = [];
        foreach ($results as $row) {
            $spaceId = (int) ($row->market_space_id ?? 0);
            if ($spaceId <= 0) {
                continue;
            }

            if (! isset($contractsBySpace[$spaceId])) {
                $contractsBySpace[$spaceId] = [];
            }

            if (count($contractsBySpace[$spaceId]) >= 10) {
                continue;
            }

            $startsAt = null;
            if (isset($row->starts_at) && $row->starts_at) {
                try {
                    $startsAt = (new \DateTime((string) $row->starts_at))->format('d.m.Y');
                } catch (\Throwable) {
                    $startsAt = (string) $row->starts_at;
                }
            }

            $endsAt = null;
            if (isset($row->ends_at) && $row->ends_at) {
                try {
                    $endsAt = (new \DateTime((string) $row->ends_at))->format('d.m.Y');
                } catch (\Throwable) {
                    $endsAt = (string) $row->ends_at;
                }
            }

            $contractsBySpace[$spaceId][] = [
                'id' => isset($row->id) ? (int) $row->id : null,
                'number' => isset($row->number) ? (string) $row->number : null,
                'tenant_name' => isset($row->tenant_name) ? (string) $row->tenant_name : null,
                'status' => isset($row->status) ? (string) $row->status : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
        }

        return $contractsBySpace;
    }

    /**
     * @param  array<int> $spaceIds
     * @param  int $marketId
     * @return array<int, array<array<string, mixed>>>
     */
    private function accrualDetailsForSpaces(array $spaceIds, int $marketId): array
    {
        if (empty($spaceIds) || ! Schema::hasTable('tenant_accruals') || ! Schema::hasColumn('tenant_accruals', 'market_space_id')) {
            return [];
        }

        $select = ['ta.id as id', 'ta.market_space_id as market_space_id'];
        $amountColumn = $this->firstExistingColumn('tenant_accruals', [
            'amount',
            'total_with_vat',
            'total_no_vat',
            'cash_amount',
        ]);

        if (Schema::hasColumn('tenant_accruals', 'period')) {
            $select[] = 'ta.period as period';
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'name')) {
            $select[] = 't.name as tenant_name';
        }

        if ($amountColumn !== null) {
            $select[] = 'ta.' . $amountColumn . ' as amount';
        }

        if (Schema::hasColumn('tenant_accruals', 'total_with_vat')) {
            $select[] = 'ta.total_with_vat as total_with_vat';
        }

        if (Schema::hasColumn('tenant_accruals', 'total_no_vat')) {
            $select[] = 'ta.total_no_vat as total_no_vat';
        }

        if (Schema::hasColumn('tenant_accruals', 'cash_amount')) {
            $select[] = 'ta.cash_amount as cash_amount';
        }

        if (Schema::hasColumn('tenant_accruals', 'source_row_hash')) {
            $select[] = 'ta.source_row_hash as source_row_hash';
        }

        if (Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
            $select[] = 'ta.tenant_contract_id as tenant_contract_id';
        }

        if (Schema::hasColumn('tenant_accruals', 'source_file')) {
            $select[] = 'ta.source_file as source_file';
        }

        if (Schema::hasColumn('tenant_accruals', 'source_place_name')) {
            $select[] = 'ta.source_place_name as source_place_name';
        }

        if (Schema::hasColumn('tenant_accruals', 'source_place_code')) {
            $select[] = 'ta.source_place_code as source_place_code';
        }

        // Include contract fields if tenant_contracts exists
        if (Schema::hasTable('tenant_contracts') && Schema::hasColumn('tenant_contracts', 'number')) {
            $select[] = 'tc.number as contract_number';
        }

        if (Schema::hasTable('tenant_contracts') && Schema::hasColumn('tenant_contracts', 'market_space_id')) {
            $select[] = 'tc.market_space_id as contract_market_space_id';
        }

        $query = DB::table('tenant_accruals as ta')
            ->select($select)
            ->leftJoin('tenants as t', function ($join): void {
                $join->on('t.id', '=', 'ta.tenant_id');
            });

        if (Schema::hasTable('tenant_contracts')) {
            $query->leftJoin('tenant_contracts as tc', function ($join): void {
                $join->on('tc.id', '=', 'ta.tenant_contract_id');
            });
        }

        $query->whereIn('ta.market_space_id', $spaceIds)
            ->orderBy('ta.market_space_id')
            ->orderByDesc(Schema::hasColumn('tenant_accruals', 'period') ? 'ta.period' : 'ta.id')
            ->orderByDesc('ta.id');

        $results = $query->get();

        $accrualsBySpace = [];
        foreach ($results as $row) {
            $spaceId = (int) ($row->market_space_id ?? 0);
            if ($spaceId <= 0) {
                continue;
            }

            if (! isset($accrualsBySpace[$spaceId])) {
                $accrualsBySpace[$spaceId] = [];
            }

            if (count($accrualsBySpace[$spaceId]) >= 10) {
                continue;
            }

            $period = null;
            if (isset($row->period) && $row->period) {
                try {
                    $period = (new \DateTime((string) $row->period))->format('m.Y');
                } catch (\Throwable) {
                    $period = (string) $row->period;
                }
            }

            $source = null;
            if (isset($row->source_row_hash) && $row->source_row_hash) {
                $source = 'Импорт';
            } elseif (isset($row->source_row_hash)) {
                $source = 'Ручной';
            }

            $contractMarketSpaceId = isset($row->contract_market_space_id) ? $row->contract_market_space_id : null;

            $accrualsBySpace[$spaceId][] = [
                'id' => isset($row->id) ? (int) $row->id : null,
                'period' => $period,
                'tenant_name' => isset($row->tenant_name) ? (string) $row->tenant_name : null,
                'amount' => isset($row->amount) ? $row->amount : null,
                'total_with_vat' => isset($row->total_with_vat) ? $row->total_with_vat : null,
                'total_no_vat' => isset($row->total_no_vat) ? $row->total_no_vat : null,
                'cash_amount' => isset($row->cash_amount) ? $row->cash_amount : null,
                'source' => $source,
                'source_file' => isset($row->source_file) ? (string) $row->source_file : null,
                'source_place_name' => isset($row->source_place_name) ? (string) $row->source_place_name : null,
                'source_place_code' => isset($row->source_place_code) ? (string) $row->source_place_code : null,
                'tenant_contract_id' => isset($row->tenant_contract_id) ? (int) $row->tenant_contract_id : null,
                'contract_number' => isset($row->contract_number) ? (string) $row->contract_number : null,
                'contract_market_space_id' => $contractMarketSpaceId,
                'contract_space_mismatch' => isset($row->market_space_id, $contractMarketSpaceId) ? ($row->market_space_id != $contractMarketSpaceId) : null,
            ];
        }

        return $accrualsBySpace;
    }

    /**
     * @return list<string>
     */
    private function tenantMatchColumns(): array
    {
        return $this->existingColumns('tenants', [
            'id',
            'name',
            'short_name',
            'display_name',
        ]);
    }

    /**
     * @return list<string>
     */
    private function tenantMatchTexts(Tenant $tenant): array
    {
        $values = [];

        foreach (['display_name', 'short_name', 'name'] as $column) {
            if (! Schema::hasColumn('tenants', $column)) {
                continue;
            }

            $value = trim((string) $tenant->getRawOriginal($column));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    private function tenantMatchLabel(Tenant $tenant): string
    {
        foreach ($this->tenantMatchTexts($tenant) as $value) {
            return $value;
        }

        return '';
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function existingColumns(string $table, array $columns): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table, $column)
        ));
    }

    /**
     * @param  list<string>  $columns
     */
    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }
}
