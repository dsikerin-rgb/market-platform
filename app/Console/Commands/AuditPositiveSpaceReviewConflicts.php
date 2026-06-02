<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Domain\Operations\SpaceReviewStateMachine;
use App\Models\MarketSpace;
use App\Models\Operation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AuditPositiveSpaceReviewConflicts extends Command
{
    protected $signature = 'space-review:audit-positive-conflicts
        {--market= : Market ID filter}
        {--limit=100 : Maximum number of observed operations to inspect}
        {--json : Output machine-readable JSON}
        {--apply : Create explicit matched review operations for candidates}
        {--max-auto-closes=10 : Maximum number of candidates to close when --apply is used}';

    protected $description = 'Find observed occupancy conflicts whose reason says the review was actually positive.';

    public function handle(): int
    {
        $marketId = $this->option('market') ? (int) $this->option('market') : null;
        $limit = max(1, (int) ($this->option('limit') ?? 100));
        $apply = (bool) $this->option('apply');
        $maxAutoCloses = max(1, (int) ($this->option('max-auto-closes') ?? 10));

        $candidates = $this->candidates($marketId, $limit);
        $applied = [];

        if ($apply) {
            foreach ($candidates->take($maxAutoCloses) as $candidate) {
                $applied[] = $this->closeCandidate($candidate);
            }
        }

        $payload = [
            'summary' => [
                'candidates' => $candidates->count(),
                'applied' => count($applied),
            ],
            'candidates' => $candidates->map(fn (array $candidate): array => $this->candidateOutput($candidate))->values()->all(),
            'applied' => $applied,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Positive conflict candidates: ' . $candidates->count());
        if ($apply) {
            $this->info('Applied closures: ' . count($applied));
        }

        if ($candidates->isNotEmpty()) {
            $this->table(
                ['Operation', 'Space', 'Reason', 'Current review status'],
                $candidates->map(fn (array $candidate): array => [
                    $candidate['operation']->id,
                    $candidate['space']->number ?? $candidate['space']->id,
                    $candidate['reason'],
                    $candidate['space']->map_review_status,
                ])->all()
            );
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{operation: Operation, space: MarketSpace, reason: string}>
     */
    private function candidates(?int $marketId, int $limit): Collection
    {
        $query = Operation::query()
            ->where('entity_type', 'market_space')
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'observed')
            ->where('payload->decision', SpaceReviewDecision::OCCUPANCY_CONFLICT)
            ->orderByDesc('id')
            ->limit($limit);

        if ($marketId !== null) {
            $query->where('market_id', $marketId);
        }

        return $query->get()
            ->map(function (Operation $operation): ?array {
                $payload = is_array($operation->payload) ? $operation->payload : [];
                $reason = trim((string) ($payload['reason'] ?? ''));

                if (! $this->isPositiveClosureReason($reason)) {
                    return null;
                }

                $space = MarketSpace::query()
                    ->where('market_id', (int) $operation->market_id)
                    ->whereKey((int) $operation->entity_id)
                    ->first();

                if (! $space instanceof MarketSpace) {
                    return null;
                }

                if (! SpaceReviewStateMachine::isAttentionReviewStatus($space->map_review_status)) {
                    return null;
                }

                if ($this->latestSpaceReviewOperationId($space) !== (int) $operation->id) {
                    return null;
                }

                return [
                    'operation' => $operation,
                    'space' => $space,
                    'reason' => $reason,
                ];
            })
            ->filter()
            ->values();
    }

    private function isPositiveClosureReason(string $reason): bool
    {
        $normalized = mb_strtolower(trim($reason), 'UTF-8');

        if ($normalized === '') {
            return false;
        }

        foreach (['не совп', 'не подтверж'] as $negativeMarker) {
            if (str_contains($normalized, $negativeMarker)) {
                return false;
            }
        }

        if (in_array($normalized, ['ок', 'ok'], true)) {
            return true;
        }

        foreach (['совпало', 'подтверждено', 'подтверждён'] as $positiveMarker) {
            if ($normalized === $positiveMarker || str_contains($normalized, $positiveMarker)) {
                return true;
            }
        }

        return false;
    }

    private function latestSpaceReviewOperationId(MarketSpace $space): int
    {
        return (int) Operation::query()
            ->where('market_id', (int) $space->market_id)
            ->where('entity_type', 'market_space')
            ->where('entity_id', (int) $space->id)
            ->where('type', OperationType::SPACE_REVIEW)
            ->orderByDesc('id')
            ->value('id');
    }

    /**
     * @param  array{operation: Operation, space: MarketSpace, reason: string}  $candidate
     * @return array<string, mixed>
     */
    private function closeCandidate(array $candidate): array
    {
        /** @var Operation $operation */
        $operation = $candidate['operation'];
        /** @var MarketSpace $space */
        $space = $candidate['space'];
        $reason = $candidate['reason'];

        return DB::transaction(function () use ($operation, $space, $reason): array {
            $closure = Operation::create([
                'market_id' => (int) $operation->market_id,
                'entity_type' => 'market_space',
                'entity_id' => (int) $space->id,
                'type' => OperationType::SPACE_REVIEW,
                'status' => 'applied',
                'effective_at' => CarbonImmutable::now('UTC'),
                'created_by' => $operation->created_by,
                'payload' => [
                    'market_space_id' => (int) $space->id,
                    'decision' => 'matched',
                    'reason' => 'Auto-closed positive occupancy conflict observation.',
                    'source_review_operation_id' => (int) $operation->id,
                    'source_review_decision' => SpaceReviewDecision::OCCUPANCY_CONFLICT,
                    'source_review_reason' => $reason,
                    'auto_closed_by_positive_conflict_audit' => true,
                ],
            ]);

            Operation::rebuildMarketSpaceSnapshot((int) $operation->market_id, (int) $space->id);

            return [
                'source_operation_id' => (int) $operation->id,
                'closure_operation_id' => (int) $closure->id,
                'space_id' => (int) $space->id,
                'space_number' => $space->number,
            ];
        });
    }

    /**
     * @param  array{operation: Operation, space: MarketSpace, reason: string}  $candidate
     * @return array<string, mixed>
     */
    private function candidateOutput(array $candidate): array
    {
        /** @var Operation $operation */
        $operation = $candidate['operation'];
        /** @var MarketSpace $space */
        $space = $candidate['space'];

        return [
            'operation_id' => (int) $operation->id,
            'space_id' => (int) $space->id,
            'space_number' => $space->number,
            'review_status' => $space->map_review_status,
            'reason' => $candidate['reason'],
        ];
    }
}
