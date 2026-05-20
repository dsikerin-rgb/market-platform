<?php
# app/Services/MarketMap/SpaceReviewActionService.php

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
use App\Services\MarketSpaces\TenantSwitchPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class SpaceReviewActionService
{
    public function __construct(
        private readonly DuplicateSpaceResolutionService $duplicateSpaceResolutionService,
    ) {
    }

    public function latestSpaceReviewOperation(int $marketId, int $spaceId): ?Operation
    {
        return Operation::query()
            ->where('market_id', $marketId)
            ->whereIn('entity_type', ['market_space', MarketSpace::class])
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::SPACE_REVIEW)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }

    public function blocksTenantSwitch(MarketSpace $space): bool
    {
        $latestReview = $this->latestSpaceReviewOperation((int) $space->market_id, (int) $space->id);

        return SpaceReviewStateMachine::blocksTenantSwitch(
            (string) data_get($latestReview?->payload, 'decision'),
            (string) ($space->map_review_status ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function reviewContractTenantSwitch(
        Market $market,
        MarketSpace $space,
        array $validated,
        ?int $userId,
    ): array {
        if ($this->blocksTenantSwitch($space)) {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Найден договор другого арендатора, но точная связь места требует уточнения. Сначала разберите место/дубли, затем подтверждайте смену.',
            ];
        }

        $targetTenant = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->whereKey((int) $validated['target_tenant_id'])
            ->first();

        if (! $targetTenant) {
            return [
                'ok' => false,
                'status_code' => 404,
                'message' => 'Арендатор из договора не найден в текущем рынке.',
            ];
        }

        $contractQuery = TenantContract::query()
            ->where('market_id', (int) $market->id)
            ->where('market_space_id', (int) $space->id)
            ->where('tenant_id', (int) $targetTenant->id);

        if (! empty($validated['contract_id'])) {
            $contractQuery->whereKey((int) $validated['contract_id']);
        }

        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $contractQuery->where('is_active', true);
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $contractQuery->whereNotIn('status', ['terminated', 'archived']);
        }

        $contract = $contractQuery
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();

        if (! $contract) {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Активный договор на это место и арендатора не найден.',
            ];
        }

        $effectiveDate = (string) $validated['effective_date'];
        $marketTz = $market->timezone ?: (string) config('app.timezone', 'UTC');
        $effectiveAt = CarbonImmutable::parse($effectiveDate, $marketTz)->startOfDay()->utc();
        $contractNumber = trim((string) ($contract->number ?? ''));
        $reason = trim((string) ($validated['reason'] ?? ''));
        $reasonParts = [
            'Смена арендатора подтверждена договором' . ($contractNumber !== '' ? ' ' . $contractNumber : ''),
        ];

        if ($reason !== '') {
            $reasonParts[] = $reason;
        }

        $closeReviewOnEffectiveAt = true;
        $applyReviewNow = $effectiveAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'));
        $closePreviousContract = (bool) ($validated['close_previous_contract'] ?? false);
        $shouldCloseReviewNow = $applyReviewNow && (int) $space->effectiveTenantId() !== (int) $targetTenant->id;

        if ($applyReviewNow && (int) $space->effectiveTenantId() === (int) $targetTenant->id) {
            $this->markSpaceReviewed($space, 'matched', $userId, now());

            return [
                'ok' => true,
                'mode' => 'tenant_switch_already_current',
                'operation' => null,
            ];
        }

        try {
            $operation = app(TenantSwitchPlanner::class)->plan(
                $space,
                $targetTenant,
                $effectiveAt,
                implode('. ', $reasonParts),
                $userId,
                $closeReviewOnEffectiveAt,
                true,
            );
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Не удалось запланировать смену арендатора.',
                'errors' => $exception->errors(),
            ];
        }

        if ($closePreviousContract) {
            $previousContractEndDate = $effectiveDate;

            if ($contract?->starts_at instanceof \DateTimeInterface) {
                $previousContractEndDate = $contract->starts_at->format('Y-m-d');
            } elseif (filled($contract?->starts_at)) {
                $previousContractEndDate = (string) $contract->starts_at;
            }

            $this->terminatePreviousContracts($market, $space, (int) $targetTenant->id, $previousContractEndDate);
        }

        $space->refresh();

        if ($shouldCloseReviewNow) {
            $sourceReviewStatus = (string) ($space->map_review_status ?? '');
            $this->markSpaceReviewed($space, 'matched', $userId, now());
            $this->recordAppliedMatchedReview(
                $market,
                $space,
                $userId,
                $operation->effective_at ?? now(),
                implode('. ', $reasonParts),
                $sourceReviewStatus,
            );
        }

        return [
            'ok' => true,
            'mode' => 'tenant_switch',
            'operation' => [
                'id' => (int) $operation->id,
                'status' => (string) $operation->status,
                'effective_at' => optional($operation->effective_at)->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function reviewTenantSwitch(
        Market $market,
        MarketSpace $space,
        array $validated,
        ?int $userId,
    ): array {
        $targetTenant = Tenant::query()
            ->where('market_id', (int) $market->id)
            ->whereKey((int) $validated['target_tenant_id'])
            ->first();

        if (! $targetTenant) {
            return [
                'ok' => false,
                'status_code' => 404,
                'message' => 'Целевой арендатор не найден в текущем рынке.',
            ];
        }

        $effectiveDate = (string) $validated['effective_date'];
        $marketTz = $market->timezone ?: (string) config('app.timezone', 'UTC');
        $effectiveAt = CarbonImmutable::parse($effectiveDate, $marketTz)->startOfDay()->utc();
        $reason = trim((string) ($validated['reason'] ?? ''));
        $closePreviousContract = (bool) ($validated['close_previous_contract'] ?? false);
        $reviewReason = $reason !== '' ? $reason : 'Смена арендатора подтверждена на карточке ревизии.';

        if ($effectiveAt->lessThanOrEqualTo(CarbonImmutable::now('UTC')) && (int) $space->effectiveTenantId() === (int) $targetTenant->id) {
            $this->markSpaceReviewed($space, 'matched', $userId, now());

            return [
                'ok' => true,
                'mode' => 'tenant_switch_already_current',
                'operation' => null,
            ];
        }

        try {
            $operation = app(TenantSwitchPlanner::class)->plan(
                $space,
                $targetTenant,
                $effectiveAt,
                $reviewReason,
                $userId,
                true,
            );
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => collect($exception->errors())->flatten()->first() ?: 'Не удалось запланировать смену арендатора.',
                'errors' => $exception->errors(),
            ];
        }

        if ($closePreviousContract) {
            $targetContract = TenantContract::query()
                ->where('market_id', (int) $market->id)
                ->where('market_space_id', (int) $space->id)
                ->where('tenant_id', (int) $targetTenant->id)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->first();

            $previousContractEndDate = $effectiveDate;

            if ($targetContract?->starts_at instanceof \DateTimeInterface) {
                $previousContractEndDate = $targetContract->starts_at->format('Y-m-d');
            } elseif (filled($targetContract?->starts_at)) {
                $previousContractEndDate = (string) $targetContract->starts_at;
            }

            $this->terminatePreviousContracts($market, $space, (int) $targetTenant->id, $previousContractEndDate);
        }

        $space->refresh();

        if ($effectiveAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            $sourceReviewStatus = (string) ($space->map_review_status ?? '');
            $this->markSpaceReviewed($space, 'matched', $userId, now());
            $space->refresh();
            $this->recordAppliedMatchedReview(
                $market,
                $space,
                $userId,
                $operation->effective_at ?? now(),
                $reviewReason,
                $sourceReviewStatus,
            );
        }

        return [
            'ok' => true,
            'mode' => 'tenant_switch_manual',
            'operation' => [
                'id' => (int) $operation->id,
                'status' => (string) $operation->status,
                'effective_at' => optional($operation->effective_at)->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function reviewDecision(
        Market $market,
        MarketSpace $space,
        array $validated,
        ?int $userId,
        callable $marketSpaceHasUsableShape,
    ): array {
        $decision = (string) $validated['decision'];
        $now = now();
        $operationEffectiveAt = $now;

        if ($decision === 'matched') {
            $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : '';
            $payload = [
                'market_space_id' => (int) $space->id,
                'decision' => 'matched',
            ];

            if ($reason !== '') {
                $payload['reason'] = $reason;
            }

            $this->createSpaceReviewOperation(
                (int) $market->id,
                (int) $space->id,
                $payload,
                'observed',
                $reason !== '' ? $reason : null,
                $operationEffectiveAt,
                $userId,
            );

            $space->refresh();

            return [
                'ok' => true,
                'mode' => 'lightweight',
            ];
        }

        if (! in_array($decision, SpaceReviewDecision::values(), true)) {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Unknown review decision.',
            ];
        }

        $reason = isset($validated['reason']) ? trim((string) $validated['reason']) : '';

        if (SpaceReviewDecision::requiresReason($decision) && $reason === '') {
            return [
                'ok' => false,
                'status_code' => 422,
                'message' => 'Для этого решения нужен комментарий.',
            ];
        }

        $payload = [
            'market_space_id' => (int) $space->id,
            'decision' => $decision,
        ];
        $duplicateResolutionPreview = null;

        foreach (['shape_id', 'observed_tenant_name', 'number', 'display_name'] as $field) {
            if (array_key_exists($field, $validated) && $validated[$field] !== null && $validated[$field] !== '') {
                $payload[$field] = $validated[$field];
            }
        }

        if (array_key_exists('effective_date', $validated) && $validated['effective_date'] !== null && $validated['effective_date'] !== '') {
            $payload['effective_date'] = (string) $validated['effective_date'];
        }

        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        if ($decision === SpaceReviewDecision::SPACE_IDENTITY_NEEDS_CLARIFICATION) {
            $latestReviewOperation = $this->latestSpaceReviewOperation((int) $market->id, (int) $space->id);
            $latestReviewDecision = trim((string) data_get($latestReviewOperation?->payload, 'decision', ''));
            $alreadyNeedsClarification = SpaceReviewStateMachine::shouldSkipRepeatedIdentityClarification(
                $latestReviewDecision,
                (string) ($space->map_review_status ?? ''),
            );

            if ($alreadyNeedsClarification) {
                return [
                    'ok' => true,
                    'mode' => 'already_marked',
                    'operation' => [
                        'id' => (int) $latestReviewOperation->id,
                        'status' => (string) $latestReviewOperation->status,
                        'decision' => $decision,
                    ],
                    'message' => 'Это место уже отмечено как требующее уточнения.',
                ];
            }
        }

        if ($decision === SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION) {
            $candidateSpaceId = (int) ($validated['candidate_market_space_id'] ?? 0);

            if ($candidateSpaceId <= 0 || $candidateSpaceId === (int) $space->id) {
                return [
                    'ok' => false,
                    'status_code' => 422,
                    'message' => 'Duplicate review candidate space is required.',
                ];
            }

            $candidateSpaceExists = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->whereKey($candidateSpaceId)
                ->exists();

            if (! $candidateSpaceExists) {
                return [
                    'ok' => false,
                    'status_code' => 404,
                    'message' => 'Duplicate review candidate space was not found in the current market.',
                ];
            }

            $duplicateResolutionPreview = $this->duplicateSpaceResolutionService->preview(
                (int) $market->id,
                (int) $space->id,
                $candidateSpaceId,
            );

            $payload['candidate_market_space_id'] = $candidateSpaceId;
            $payload['reason'] = $payload['reason'] ?? 'Выбрано основное место дубля; перенести безопасные связи.';
            $payload['duplicate_resolution'] = [
                'candidate_market_space_id' => $candidateSpaceId,
                'transfer_counts' => $duplicateResolutionPreview['transfer_counts'] ?? [],
                'blocking_counts' => $duplicateResolutionPreview['blocking_counts'] ?? [],
                'classification' => $duplicateResolutionPreview['classification'] ?? null,
                'accrual_classification' => $duplicateResolutionPreview['accrual_classification'] ?? null,
                'retained_financial_tail' => $duplicateResolutionPreview['retained_financial_tail'] ?? null,
            ];
        }

        if ($decision === SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL) {
            $candidateSpaceId = (int) ($validated['candidate_market_space_id'] ?? 0);
            $effectiveDate = trim((string) ($validated['effective_date'] ?? ''));

            if ($candidateSpaceId <= 0 || $candidateSpaceId === (int) $space->id) {
                return [
                    'ok' => false,
                    'status_code' => 422,
                    'message' => 'Выберите основное место, в которое вошло упраздняемое место.',
                ];
            }

            if ($effectiveDate === '') {
                return [
                    'ok' => false,
                    'status_code' => 422,
                    'message' => 'Укажите дату, с которой место считается объединённым.',
                ];
            }

            $candidateSpaceExists = MarketSpace::query()
                ->where('market_id', (int) $market->id)
                ->whereKey($candidateSpaceId)
                ->where('is_active', true)
                ->exists();

            if (! $candidateSpaceExists) {
                return [
                    'ok' => false,
                    'status_code' => 404,
                    'message' => 'Основное место не найдено или уже неактивно.',
                ];
            }

            $marketTz = $market->timezone ?: (string) config('app.timezone', 'UTC');
            $operationEffectiveAt = CarbonImmutable::parse($effectiveDate, $marketTz)->startOfDay()->utc();
            $payload['candidate_market_space_id'] = $candidateSpaceId;
            $payload['reason'] = $payload['reason'] ?? 'Место упразднено: физически объединено с основным местом.';
        }

        if (isset($payload['shape_id'])) {
            $shape = MarketSpaceMapShape::query()
                ->where('market_id', (int) $market->id)
                ->whereKey((int) $payload['shape_id'])
                ->first();

            if (! $shape) {
                return [
                    'ok' => false,
                    'status_code' => 404,
                    'message' => 'Map review shape was not found in the current market.',
                ];
            }

            if ($decision === SpaceReviewDecision::BIND_SHAPE_TO_SPACE) {
                if ((int) ($shape->market_space_id ?? 0) > 0 && (int) $shape->market_space_id !== (int) $space->id) {
                    return [
                        'ok' => false,
                        'status_code' => 422,
                        'message' => 'Эта разметка уже привязана к другому месту. Сначала отвяжите её или выберите другую разметку.',
                    ];
                }

                if ($marketSpaceHasUsableShape((int) $market->id, (int) $space->id, (int) $shape->id)) {
                    return [
                        'ok' => false,
                        'status_code' => 422,
                        'message' => 'У этого места уже есть привязанная разметка. Выберите место без разметки.',
                    ];
                }
            }
        }

        $operation = $this->createSpaceReviewOperation(
            (int) $market->id,
            (int) $space->id,
            $payload,
            SpaceReviewDecision::defaultOperationStatus($decision),
            isset($payload['reason']) ? (string) $payload['reason'] : null,
            $operationEffectiveAt,
            $userId,
        );

        $space->refresh();

        return [
            'ok' => true,
            'mode' => 'operation',
            'operation' => [
                'id' => (int) $operation->id,
                'status' => (string) $operation->status,
                'decision' => $decision,
            ],
            'resolution' => $duplicateResolutionPreview,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createSpaceReviewOperation(
        int $marketId,
        int $spaceId,
        array $payload,
        string $status,
        ?string $comment,
        mixed $effectiveAt,
        ?int $userId,
    ): Operation {
        return DB::transaction(static fn (): Operation => Operation::query()->create([
            'market_id' => $marketId,
            'entity_type' => 'market_space',
            'entity_id' => $spaceId,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $effectiveAt,
            'status' => $status,
            'payload' => $payload,
            'comment' => $comment,
            'created_by' => $userId,
        ]));
    }

    private function terminatePreviousContracts(
        Market $market,
        MarketSpace $space,
        int $targetTenantId,
        string $endDate,
    ): void {
        $previousContractsQuery = TenantContract::query()
            ->where('market_id', (int) $market->id)
            ->where('market_space_id', (int) $space->id)
            ->where('tenant_id', '!=', $targetTenantId);

        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $previousContractsQuery->where('is_active', true);
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $previousContractsQuery->whereNotIn('status', ['terminated', 'archived']);
        }

        foreach ($previousContractsQuery->get() as $previousContract) {
            if (Schema::hasColumn('tenant_contracts', 'ends_at')) {
                $previousContract->ends_at = $endDate;
            }

            if (Schema::hasColumn('tenant_contracts', 'status')) {
                $previousContract->status = 'terminated';
            }

            if (Schema::hasColumn('tenant_contracts', 'is_active')) {
                $previousContract->is_active = false;
            }

            $previousContract->save();
        }
    }

    private function markSpaceReviewed(MarketSpace $space, string $status, ?int $userId = null, mixed $reviewedAt = null): void
    {
        $space->forceFill([
            'map_review_status' => $status,
            'map_reviewed_at' => $reviewedAt ?? now(),
            'map_reviewed_by' => $userId,
        ])->save();
    }

    private function recordAppliedMatchedReview(
        Market $market,
        MarketSpace $space,
        ?int $userId = null,
        mixed $effectiveAt = null,
        ?string $reason = null,
        ?string $sourceReviewStatus = null
    ): Operation {
        $payload = [
            'market_space_id' => (int) $space->id,
            'decision' => 'matched',
        ];

        $normalizedSourceReviewStatus = is_string($sourceReviewStatus) ? trim($sourceReviewStatus) : '';
        if ($normalizedSourceReviewStatus !== '') {
            $payload['source_review_status'] = $normalizedSourceReviewStatus;
        }

        $normalizedReason = is_string($reason) ? trim($reason) : '';
        if ($normalizedReason !== '') {
            $payload['reason'] = $normalizedReason;
        }

        return Operation::query()->create([
            'market_id' => (int) $market->id,
            'entity_type' => 'market_space',
            'entity_id' => (int) $space->id,
            'type' => OperationType::SPACE_REVIEW,
            'effective_at' => $effectiveAt ?? now(),
            'status' => 'applied',
            'payload' => $payload,
            'comment' => $normalizedReason !== '' ? $normalizedReason : null,
            'created_by' => $userId,
        ]);
    }
}
