<?php

declare(strict_types=1);

namespace App\Services\MarketMap;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Models\Market;
use App\Models\MarketSpace;
use App\Models\Operation;
use App\Models\User;
use Illuminate\Support\Collection;
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
     *   location_name:?string,
     *   review_status:?string,
     *   review_status_label:?string,
     *   reviewed_at:?string,
     *   reviewed_by_name:?string,
     *   decision:?string,
     *   decision_label:?string,
     *   reason:?string
     * }>
     */
    public function needsAttention(int $marketId, int $limit = 50): array
    {
        if ($marketId <= 0 || ! $this->hasMapReviewColumns()) {
            return [];
        }

        $spaces = MarketSpace::query()
            ->with(['location:id,name'])
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

        $reviewerIds = $spaces->pluck('map_reviewed_by')
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $reviewers = User::query()
            ->whereIn('id', $reviewerIds)
            ->pluck('name', 'id');

        return $spaces->map(function (MarketSpace $space) use ($latestOperations, $reviewers): array {
            $operation = $latestOperations->get((int) $space->id);
            $payload = is_array($operation?->payload) ? $operation->payload : [];
            $decision = filled($payload['decision'] ?? null) ? (string) $payload['decision'] : null;

            return [
                'space_id' => (int) $space->id,
                'number' => $space->number,
                'display_name' => $space->display_name,
                'location_name' => $space->location?->name,
                'review_status' => $space->map_review_status,
                'review_status_label' => $this->reviewStatusLabel($space->map_review_status),
                'reviewed_at' => $space->map_reviewed_at?->format('d.m.Y H:i'),
                'reviewed_by_name' => $space->map_reviewed_by ? (string) ($reviewers[(int) $space->map_reviewed_by] ?? '—') : null,
                'decision' => $decision,
                'decision_label' => $decision ? (SpaceReviewDecision::labels()[$decision] ?? $decision) : null,
                'reason' => filled($payload['reason'] ?? null) ? trim((string) $payload['reason']) : null,
            ];
        })->all();
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

        $spaces = MarketSpace::query()
            ->with(['location:id,name'])
            ->whereIn('id', $spaceIds)
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

            return [
                'operation_id' => (int) $operation->id,
                'space_id' => $spaceId,
                'number' => $space?->number,
                'display_name' => $space?->display_name,
                'location_name' => $space?->location?->name,
                'decision' => $decision,
                'decision_label' => SpaceReviewDecision::labels()[$decision] ?? $decision,
                'summary' => $this->appliedDecisionSummary($decision, $payload),
                'effective_at' => $operation->effective_at?->format('d.m.Y H:i'),
                'created_by_name' => $operation->created_by ? (string) ($creators[(int) $operation->created_by] ?? '—') : null,
                'review_status' => $space?->map_review_status,
                'review_status_label' => $this->reviewStatusLabel($space?->map_review_status),
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
                'status',
                'payload',
            ])
            ->unique(fn (Operation $operation): int => (int) ($operation->entity_id ?? 0))
            ->keyBy(fn (Operation $operation): int => (int) ($operation->entity_id ?? 0));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function appliedDecisionSummary(string $decision, array $payload): string
    {
        return match ($decision) {
            SpaceReviewDecision::BIND_SHAPE_TO_SPACE => 'Фигура привязана к месту'
                . (filled($payload['shape_id'] ?? null) ? ' · shape #' . (int) $payload['shape_id'] : ''),
            SpaceReviewDecision::UNBIND_SHAPE_FROM_SPACE => 'Фигура отвязана от места'
                . (filled($payload['shape_id'] ?? null) ? ' · shape #' . (int) $payload['shape_id'] : ''),
            SpaceReviewDecision::MARK_SPACE_FREE => 'Место отмечено как свободное',
            SpaceReviewDecision::MARK_SPACE_SERVICE => 'Место отмечено как служебное',
            SpaceReviewDecision::FIX_SPACE_IDENTITY => $this->identityFixSummary($payload),
            default => '—',
        };
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
}
