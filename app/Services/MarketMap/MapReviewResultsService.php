<?php
# app/Services/MarketMap/MapReviewResultsService.php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\MarketSpaceMapShape;
use App\Models\Operation;
use App\Models\TenantContract;
use App\Models\User;
use App\Services\Debt\DebtStatusResolver;
use Illuminate\Support\Collection;
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

        $spaces = MarketSpace::query()
            ->with(['location:id,name', 'tenant:id,name'])
            ->where('market_id', $marketId)
            ->whereIn('map_review_status', ['changed_tenant', 'conflict', 'not_found'])
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

        if ($spaces->isEmpty()) {
            return [];
        }

        $spaceIds = $spaces->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $latestOperations = $this->latestSpaceReviewOperationsBySpace($marketId, $spaceIds);
        $diagnostics = $this->buildSpaceDiagnostics($marketId, $spaces);

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
            $decision = filled($payload['decision'] ?? null) ? (string) $payload['decision'] : null;
            $reviewedAt = $space->map_reviewed_at?->format('d.m.Y H:i');
            $reviewedByName = $space->map_reviewed_by ? (string) ($reviewers[(int) $space->map_reviewed_by] ?? '-') : null;
            $createdAt = $operation?->created_at?->format('d.m.Y H:i') ?? $reviewedAt;
            $createdByName = $operation?->created_by
                ? (string) ($reviewers[(int) $operation->created_by] ?? '-')
                : $reviewedByName;
            $reason = filled($payload['reason'] ?? null) ? trim((string) $payload['reason']) : null;

            return [
                'space_id' => (int) $space->id,
                'number' => $space->number,
                'display_name' => $space->display_name,
                'current_tenant_name' => $space->tenant?->name,
                'location_name' => $space->location?->name,
                'created_at' => $createdAt,
                'created_by_name' => $createdByName,
                'review_status' => $space->map_review_status,
                'review_status_label' => $this->reviewStatusLabel($space->map_review_status),
                'reviewed_at' => $reviewedAt,
                'reviewed_by_name' => $reviewedByName,
                'decision' => $decision,
                'decision_label' => $decision ? (SpaceReviewDecision::labels()[$decision] ?? $decision) : null,
                'reason' => $reason,
                'tenant_change_details' => $this->tenantChangeDetails($decision, $payload, $createdByName, $createdAt, $reason),
                'diagnostics' => $diagnostics[(int) $space->id] ?? $this->emptyDiagnostics(),
            ];
        })->all();
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
    private function buildSpaceDiagnostics(int $marketId, Collection $spaces): array
    {
        $spaceIds = $spaces->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($spaceIds === []) {
            return [];
        }

        $currentCounts = $this->relationCountsForSpaces($spaceIds);
        $contractOverrides = $this->activeContractOverrideForSpaces($spaceIds, $marketId);

        $tenantIds = $spaces->pluck('tenant_id')
            ->filter(fn ($id): bool => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $candidateSpaces = $tenantIds !== []
            ? MarketSpace::query()
                ->where('market_id', $marketId)
                ->whereIn('tenant_id', $tenantIds)
                ->when(
                    Schema::hasColumn('market_spaces', 'is_active'),
                    fn ($query) => $query->where('is_active', true)
                )
            ->orderByRaw('COALESCE(number, display_name, code) asc')
            ->get(['id', 'tenant_id', 'number', 'display_name', 'code'])
            : collect();

        $candidateIds = $candidateSpaces->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $candidateCounts = $this->relationCountsForSpaces($candidateIds);
        $candidatesByTenant = $candidateSpaces->groupBy(fn (MarketSpace $space): int => (int) $space->tenant_id);

        $allDiagnosticSpaceIds = array_unique(array_merge($spaceIds, $candidateIds));

        $contractDetails = $this->contractDetailsForSpaces($allDiagnosticSpaceIds, $marketId);
        $accrualDetails = $this->accrualDetailsForSpaces($allDiagnosticSpaceIds, $marketId);

        return $spaces->mapWithKeys(function (MarketSpace $space) use ($currentCounts, $candidateCounts, $candidatesByTenant, $contractOverrides, $contractDetails, $accrualDetails): array {
            $spaceId = (int) $space->id;
            $counts = $currentCounts[$spaceId] ?? [];
            $tenantId = (int) ($space->tenant_id ?? 0);
            $contractOverride = $contractOverrides[$spaceId] ?? null;

            $currentScore = $this->relationStrengthScore($counts);

            $candidates = $tenantId > 0
                ? $candidatesByTenant->get($tenantId, collect())
                    ->reject(fn (MarketSpace $candidate): bool => (int) $candidate->id === $spaceId)
                    ->take(5)
                    ->map(function (MarketSpace $candidate) use ($candidateCounts, $currentScore, $contractDetails, $accrualDetails): array {
                        $candidateId = (int) $candidate->id;
                        $counts = $candidateCounts[$candidateId] ?? [];
                        $candidateScore = $this->relationStrengthScore($counts);

                        return [
                            'space_id' => $candidateId,
                            'label' => $this->spaceLabel($candidate),
                            'relation_counts' => $this->compactRelationCounts($counts),
                            'has_map' => (int) ($counts['map_shapes'] ?? 0) > 0,
                            'relation_score' => $candidateScore,
                            'is_stronger_than_current' => $candidateScore > $currentScore,
                            'contract_details' => $contractDetails[$candidateId] ?? [],
                            'accrual_details' => $accrualDetails[$candidateId] ?? [],
                        ];
                    })->values()->all()
                : [];

            $hasStrongerCandidate = collect($candidates)
                ->contains(fn (array $candidate): bool => (bool) ($candidate['is_stronger_than_current'] ?? false));

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
                ],
            ];
        })->all();
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
        ];
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
            ->where('entity_type', 'market_space')
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get([
                'id',
                'entity_id',
                'effective_at',
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

        return $operations->map(function (Operation $operation) use ($spaces, $creators): array {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $spaceId = (int) ($operation->entity_id ?? 0);
            /** @var MarketSpace|null $space */
            $space = $spaces->get($spaceId);
            $decision = (string) ($payload['decision'] ?? '');

            $isAutoClosed = (bool) ($payload['auto_closed_by_reconciliation'] ?? false);
            $autoCloseAt = $payload['auto_close_at'] ?? null;
            $autoCloseBindingId = $payload['auto_close_binding_id'] ?? null;

            return [
                'operation_id' => (int) $operation->id,
                'space_id' => $spaceId,
                'number' => $space?->number,
                'display_name' => $space?->display_name,
                'location_name' => $space?->location?->name,
                'decision' => $decision,
                'decision_label' => SpaceReviewDecision::labels()[$decision] ?? $decision,
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
                'review_status' => $space?->map_review_status,
                'review_status_label' => $this->reviewStatusLabel($space?->map_review_status),
                'is_auto_closed' => $isAutoClosed,
                'auto_close_at' => $autoCloseAt ? (new \DateTime($autoCloseAt))->format('d.m.Y H:i') : null,
                'auto_close_binding_id' => $autoCloseBindingId ? (int) $autoCloseBindingId : null,
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
            ->where('entity_type', 'market_space')
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
    private function duplicateResolutionSummary(array $payload, ?MarketSpace $space, ?MarketSpace $candidate): string
    {
        $transferCounts = data_get($payload, 'duplicate_resolution.transfer_counts', []);
        $blockingCounts = data_get($payload, 'duplicate_resolution.blocking_counts', []);
        $candidateId = (int) ($payload['candidate_market_space_id']
            ?? data_get($payload, 'duplicate_resolution.candidate_market_space_id')
            ?? 0);

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

        if (Schema::hasColumn('tenant_accruals', 'period')) {
            $select[] = 'ta.period as period';
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'name')) {
            $select[] = 't.name as tenant_name';
        }

        if (Schema::hasColumn('tenant_accruals', 'total_with_vat')) {
            $select[] = 'ta.total_with_vat as total_with_vat';
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
                'total_with_vat' => isset($row->total_with_vat) ? $row->total_with_vat : null,
                'cash_amount' => isset($row->cash_amount) ? $row->cash_amount : null,
                'source' => $source,
                'tenant_contract_id' => isset($row->tenant_contract_id) ? (int) $row->tenant_contract_id : null,
                'contract_number' => isset($row->contract_number) ? (string) $row->contract_number : null,
                'contract_market_space_id' => $contractMarketSpaceId,
                'contract_space_mismatch' => isset($row->market_space_id, $contractMarketSpaceId) ? ($row->market_space_id != $contractMarketSpaceId) : null,
            ];
        }

        return $accrualsBySpace;
    }
}
