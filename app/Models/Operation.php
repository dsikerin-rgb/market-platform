<?php
# app/Models/Operation.php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use App\Domain\Operations\SpaceReviewStateMachine;
use App\Services\Ai\AiReviewService;
use App\Services\MarketMap\DuplicateSpaceResolutionService;
use App\Services\MarketMap\MergedSpaceRetirementService;
use App\Services\MarketSpaces\SpaceGroupManager;
use App\Services\Operations\MarketPeriodResolver;
use App\Services\Operations\OperationPayloadValidator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Operation extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'market_id',
        'entity_type',
        'entity_id',
        'type',
        'effective_at',
        'effective_tz',
        'effective_month',
        'status',
        'payload',
        'comment',
        'created_by',
        'cancels_operation_id',
    ];

    protected $casts = [
        'effective_at' => 'datetime',
        'effective_month' => 'date',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $operation): void {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $operation->payload = OperationPayloadValidator::normalize($operation->type, $payload);

            if (! $operation->entity_type && isset($operation->payload['market_space_id'])) {
                $operation->entity_type = 'market_space';
            }

            if (! $operation->entity_id && isset($operation->payload['market_space_id'])) {
                $operation->entity_id = (int) $operation->payload['market_space_id'];
            }

            if ($operation->type === OperationType::SPACE_REVIEW && blank($operation->status)) {
                $decision = (string) ($operation->payload['decision'] ?? '');
                if ($decision !== '') {
                    $operation->status = SpaceReviewStateMachine::defaultOperationStatus($decision);
                }
            }

            if (! $operation->created_by) {
                $operation->created_by = Auth::id();
            }

            $market = Market::query()->find($operation->market_id);
            $resolver = app(MarketPeriodResolver::class);
            $tz = $market?->timezone ?: (string) config('app.timezone', 'UTC');
            $operation->effective_tz = $operation->effective_tz ?: $tz;

            $effectiveAt = $operation->effective_at
                ? CarbonImmutable::parse($operation->effective_at)
                : ($market ? $resolver->marketNow($market) : CarbonImmutable::now($tz));

            $operation->effective_at = $effectiveAt->utc();

            if ($market) {
                $operation->effective_month = $resolver->resolveMarketPeriod($market, $effectiveAt->timezone($tz)->toDateString());
            } else {
                $operation->effective_month = CarbonImmutable::parse($effectiveAt->timezone($tz)->toDateString(), $tz)->startOfMonth();
            }
        });

        static::saved(function (self $operation): void {
            $payload = is_array($operation->payload) ? $operation->payload : [];
            $spaceId = (int) ($payload['market_space_id'] ?? $operation->entity_id ?? 0);

            if ($spaceId <= 0) {
                return;
            }

            if ($operation->entity_type !== 'market_space') {
                return;
            }

            if ($operation->type === OperationType::SPACE_REVIEW) {
                static::applySpaceReviewOperation($operation, $spaceId);
                return;
            }

            if (! static::isSpaceSnapshotAffectingType((string) $operation->type)) {
                return;
            }

            // Всегда пересчитываем срез места от applied-операций.
            // Это корректно обрабатывает create/update/status change (draft/canceled/applied).
            app(AiReviewService::class)->clearCache($spaceId, (int) $operation->market_id);
            static::syncMarketSpaceSnapshotFromOperations((int) $operation->market_id, $spaceId);
        });
    }

    private static function syncMarketSpaceSnapshotFromOperations(int $marketId, int $spaceId): void
    {
        $space = MarketSpace::query()
            ->where('market_id', $marketId)
            ->whereKey($spaceId)
            ->first();

        if (! $space) {
            return;
        }

        $nowUtc = CarbonImmutable::now('UTC');

        $latestTenantOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::TENANT_SWITCH)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        $tenantSwitchDetachedFromGroup = false;
        $tenantSwitchOldParentId = 0;

        if ($latestTenantOp) {
            $payload = is_array($latestTenantOp->payload) ? $latestTenantOp->payload : [];
            $tenantId = (int) ($payload['to_tenant_id'] ?? 0);

            // Если tenantId <= 0 - сбрасываем на null (освобождение места)
            // Если tenantId > 0, но tenant удалён - пропускаем, не меняем tenant_id
            // Это защищает от FK violation при rebuild snapshot с историческими операциями
            if ($tenantId <= 0) {
                $space->tenant_id = null;
            } elseif (Tenant::query()->whereKey($tenantId)->exists()) {
                $space->tenant_id = $tenantId;
            }
            // else: не меняем tenant_id

            $tenantSwitchDetachedFromGroup = (bool) ($payload['detach_from_group'] ?? false);
            $tenantSwitchOldParentId = (int) ($payload['from_group_parent_id'] ?? 0);

            if ($tenantSwitchDetachedFromGroup) {
                $space->space_group_role = MarketSpace::SPACE_GROUP_ROLE_NONE;
                $space->space_group_parent_id = null;
                $space->space_group_slot = null;
            }

            if ((bool) ($payload['review_close_on_effective_at'] ?? false)) {
                $space->map_review_status = 'matched';
                $space->map_reviewed_at = $latestTenantOp->effective_at;
                $space->map_reviewed_by = (int) ($latestTenantOp->created_by ?? 0) > 0
                    ? (int) $latestTenantOp->created_by
                    : $space->map_reviewed_by;
            }
        }

        $latestRentRateOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::RENT_RATE_CHANGE)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if ($latestRentRateOp) {
            $payload = is_array($latestRentRateOp->payload) ? $latestRentRateOp->payload : [];

            if (array_key_exists('rent_rate', $payload)) {
                $rentRate = $payload['rent_rate'];
                $space->rent_rate_value = is_numeric($rentRate) ? (float) $rentRate : null;
            }

            if (array_key_exists('unit', $payload) && is_string($payload['unit']) && trim($payload['unit']) !== '') {
                $space->rent_rate_unit = trim($payload['unit']);
            }
        }

        $latestAttrsOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::SPACE_ATTRS_CHANGE)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if ($latestAttrsOp) {
            $payload = is_array($latestAttrsOp->payload) ? $latestAttrsOp->payload : [];

            if (array_key_exists('area_sqm', $payload)) {
                $space->area_sqm = is_numeric($payload['area_sqm']) ? (float) $payload['area_sqm'] : null;
            }

            if (array_key_exists('activity_type', $payload)) {
                $space->activity_type = is_string($payload['activity_type']) && trim($payload['activity_type']) !== ''
                    ? trim($payload['activity_type'])
                    : null;
            }

            if (array_key_exists('location_id', $payload)) {
                $space->location_id = is_numeric($payload['location_id']) ? (int) $payload['location_id'] : null;
            }

            if (array_key_exists('type', $payload)) {
                $space->type = is_string($payload['type']) && trim($payload['type']) !== ''
                    ? trim($payload['type'])
                    : null;
            }

            if (array_key_exists('status', $payload)) {
                $space->status = is_string($payload['status']) && trim($payload['status']) !== ''
                    ? trim($payload['status'])
                    : $space->status;
            }

            if (array_key_exists('is_active', $payload)) {
                if ($payload['is_active'] !== null) {
                    $space->is_active = (bool) $payload['is_active'];
                }
            }

            if (array_key_exists('display_name', $payload)) {
                $space->display_name = is_string($payload['display_name']) && trim($payload['display_name']) !== ''
                    ? trim($payload['display_name'])
                    : null;
            }

            if (array_key_exists('number', $payload)) {
                $space->number = is_string($payload['number']) && trim($payload['number']) !== ''
                    ? trim($payload['number'])
                    : null;
            }
        }

        $latestReviewStatusCarrier = self::latestReviewStatusCarrier($marketId, $spaceId, $nowUtc);

        if ($latestReviewStatusCarrier instanceof self) {
            $payload = is_array($latestReviewStatusCarrier->payload) ? $latestReviewStatusCarrier->payload : [];
            $decision = (string) ($payload['decision'] ?? '');

            if ((string) $latestReviewStatusCarrier->type === OperationType::TENANT_SWITCH) {
                $space->map_review_status = 'matched';
                $space->map_reviewed_at = $latestReviewStatusCarrier->effective_at;
            } elseif ($decision !== '') {
                $space->map_review_status = SpaceReviewStateMachine::reviewStatusForDecision($decision);
                $space->map_reviewed_at = $latestReviewStatusCarrier->effective_at;
            }

            if ((int) ($latestReviewStatusCarrier->created_by ?? 0) > 0) {
                $space->map_reviewed_by = (int) $latestReviewStatusCarrier->created_by;
            }
        }

        $latestAppliedSpaceReviewOp = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::SPACE_REVIEW)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();

        if ($latestAppliedSpaceReviewOp) {
            $payload = is_array($latestAppliedSpaceReviewOp->payload) ? $latestAppliedSpaceReviewOp->payload : [];
            $decision = (string) ($payload['decision'] ?? '');

            if ($decision !== '') {
                $latestStatusAttrsOp = self::latestSpaceAttrsOperationAffectingField($marketId, $spaceId, $nowUtc, 'status');
                $latestNumberAttrsOp = self::latestSpaceAttrsOperationAffectingField($marketId, $spaceId, $nowUtc, 'number');
                $latestDisplayNameAttrsOp = self::latestSpaceAttrsOperationAffectingField($marketId, $spaceId, $nowUtc, 'display_name');

                if (
                    $decision === SpaceReviewDecision::MARK_SPACE_FREE
                    && self::isOperationNewerThan($latestAppliedSpaceReviewOp, $latestTenantOp)
                ) {
                    $space->tenant_id = null;
                }

                if (
                    $decision === SpaceReviewDecision::MARK_SPACE_FREE
                    && self::isOperationNewerThan($latestAppliedSpaceReviewOp, $latestStatusAttrsOp)
                ) {
                    $space->status = 'vacant';
                }

                if (
                    $decision === SpaceReviewDecision::MARK_SPACE_SERVICE
                    && self::isOperationNewerThan($latestAppliedSpaceReviewOp, $latestStatusAttrsOp)
                ) {
                    $space->status = 'maintenance';
                }

                if ($decision === SpaceReviewDecision::FIX_SPACE_IDENTITY) {
                    if (
                        array_key_exists('number', $payload)
                        && self::isOperationNewerThan($latestAppliedSpaceReviewOp, $latestNumberAttrsOp)
                    ) {
                        $space->number = $payload['number'];
                    }

                    if (
                        array_key_exists('display_name', $payload)
                        && self::isOperationNewerThan($latestAppliedSpaceReviewOp, $latestDisplayNameAttrsOp)
                    ) {
                        $space->display_name = $payload['display_name'];
                    }
                }
            }
        }

        if ($space->isDirty()) {
            $space->save();
        }

        if ($tenantSwitchDetachedFromGroup && $tenantSwitchOldParentId > 0) {
            $oldParent = MarketSpace::query()
                ->where('market_id', $marketId)
                ->whereKey($tenantSwitchOldParentId)
                ->first();

            if ($oldParent instanceof MarketSpace) {
                app(SpaceGroupManager::class)->syncParentIdentity($oldParent);
            }
        }
    }

    private static function applySpaceReviewOperation(self $operation, int $spaceId): void
    {
        $space = MarketSpace::query()
            ->where('market_id', (int) $operation->market_id)
            ->whereKey($spaceId)
            ->first();

        if (! $space) {
            return;
        }

        $payload = is_array($operation->payload) ? $operation->payload : [];
        $decision = (string) ($payload['decision'] ?? '');

        if ($decision === '') {
            return;
        }

        app(AiReviewService::class)->clearCache($spaceId, (int) $operation->market_id);

        if ($operation->status === 'applied' && $decision === SpaceReviewDecision::DUPLICATE_SPACE_NEEDS_RESOLUTION) {
            $candidateSpaceId = (int) ($payload['candidate_market_space_id'] ?? 0);

            if ($candidateSpaceId > 0) {
                app(DuplicateSpaceResolutionService::class)->resolve(
                    (int) $operation->market_id,
                    $spaceId,
                    $candidateSpaceId,
                    $operation->created_by ? (int) $operation->created_by : null,
                );
            }

            return;
        }

        if ($operation->status === 'applied' && $decision === SpaceReviewDecision::MERGE_SPACE_INTO_CANONICAL) {
            $canonicalSpaceId = (int) ($payload['candidate_market_space_id'] ?? 0);
            $effectiveDate = trim((string) ($payload['effective_date'] ?? ''));

            if ($canonicalSpaceId > 0 && $effectiveDate !== '') {
                app(MergedSpaceRetirementService::class)->retire(
                    (int) $operation->market_id,
                    $spaceId,
                    $canonicalSpaceId,
                    CarbonImmutable::parse($effectiveDate, (string) ($operation->effective_tz ?: config('app.timezone', 'UTC'))),
                    $operation->created_by ? (int) $operation->created_by : null,
                    isset($payload['reason']) ? (string) $payload['reason'] : null,
                );
            }

            return;
        }

        $reviewAttributes = [
            'map_review_status' => SpaceReviewStateMachine::reviewStatusForDecision($decision),
            'map_reviewed_at' => $operation->effective_at,
            'map_reviewed_by' => $operation->created_by,
        ];

        $reviewUpdateCount = DB::table('market_spaces')
            ->where('market_id', (int) $operation->market_id)
            ->where('id', $space->id)
            ->update($reviewAttributes);
        $space->forceFill($reviewAttributes);

        if ($operation->status !== 'applied') {
            return;
        }

        if ($decision === SpaceReviewDecision::BIND_SHAPE_TO_SPACE || $decision === SpaceReviewDecision::UNBIND_SHAPE_FROM_SPACE) {
            $shapeId = (int) ($payload['shape_id'] ?? 0);
            if ($shapeId > 0) {
                $shape = MarketSpaceMapShape::query()
                    ->where('market_id', (int) $operation->market_id)
                    ->whereKey($shapeId)
                    ->first();

                if ($shape) {
                    $shape->market_space_id = $decision === SpaceReviewDecision::BIND_SHAPE_TO_SPACE
                        ? $space->id
                        : null;
                    $shape->save();
                }
            }

            if ($reviewUpdateCount === 0) {
                $space->save();
            }

            return;
        }

        if ($decision === SpaceReviewDecision::MARK_SPACE_FREE) {
            $space->status = 'vacant';
            $space->tenant_id = null;
        }

        if ($decision === SpaceReviewDecision::MARK_SPACE_SERVICE) {
            $space->status = 'maintenance';
        }

        if ($decision === SpaceReviewDecision::FIX_SPACE_IDENTITY) {
            if (array_key_exists('number', $payload)) {
                $space->number = $payload['number'];
            }

            if (array_key_exists('display_name', $payload)) {
                $space->display_name = $payload['display_name'];
            }
        }

        if ($space->isDirty()) {
            $space->save();
        }

        if ($decision === SpaceReviewDecision::MARK_SPACE_FREE) {
            self::closeSpaceSnapshotBindings((int) $operation->market_id, $space->id, 'space_marked_free');
        }

        if ($decision === SpaceReviewDecision::MARK_SPACE_SERVICE) {
            self::closeSpaceSnapshotBindings((int) $operation->market_id, $space->id, 'space_marked_service');
        }
    }

    public static function rebuildMarketSpaceSnapshot(int $marketId, int $spaceId): void
    {
        static::syncMarketSpaceSnapshotFromOperations($marketId, $spaceId);
    }

    private static function latestReviewClosingTenantSwitch(int $marketId, int $spaceId, CarbonImmutable $nowUtc): ?self
    {
        /** @var self|null $operation */
        $operation = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::TENANT_SWITCH)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get()
            ->first(function (self $operation): bool {
                $payload = is_array($operation->payload) ? $operation->payload : [];

                return (bool) ($payload['review_close_on_effective_at'] ?? false);
            });

        return $operation;
    }

    private static function latestReviewStatusCarrier(int $marketId, int $spaceId, CarbonImmutable $nowUtc): ?self
    {
        /** @var self|null $operation */
        $operation = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('effective_at', '<=', $nowUtc)
            ->where(function ($query) {
                $query->where('type', OperationType::SPACE_REVIEW)
                    ->orWhere(function ($tenantSwitchQuery) {
                        $tenantSwitchQuery->where('type', OperationType::TENANT_SWITCH)
                            ->where('status', 'applied');
                    });
            })
            ->orderByDesc('id')
            ->get()
            ->first(function (self $operation): bool {
                if ((string) $operation->type === OperationType::SPACE_REVIEW) {
                    $payload = is_array($operation->payload) ? $operation->payload : [];

                    return trim((string) ($payload['decision'] ?? '')) !== '';
                }

                if ((string) $operation->type !== OperationType::TENANT_SWITCH) {
                    return false;
                }

                $payload = is_array($operation->payload) ? $operation->payload : [];

                return (bool) ($payload['review_close_on_effective_at'] ?? false);
            });

        return $operation;
    }

    private static function latestSpaceAttrsOperationAffectingField(
        int $marketId,
        int $spaceId,
        CarbonImmutable $nowUtc,
        string $field
    ): ?self {
        /** @var self|null $operation */
        $operation = self::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $spaceId)
            ->where('type', OperationType::SPACE_ATTRS_CHANGE)
            ->where('status', 'applied')
            ->where('effective_at', '<=', $nowUtc)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get()
            ->first(function (self $operation) use ($field): bool {
                $payload = is_array($operation->payload) ? $operation->payload : [];

                return array_key_exists($field, $payload);
            });

        return $operation;
    }

    private static function isOperationNewerThan(?self $candidate, ?self $reference): bool
    {
        if (! $candidate) {
            return false;
        }

        if (! $reference) {
            return true;
        }

        $candidateAt = CarbonImmutable::parse($candidate->effective_at);
        $referenceAt = CarbonImmutable::parse($reference->effective_at);

        if ($candidateAt->equalTo($referenceAt)) {
            return (int) $candidate->id > (int) $reference->id;
        }

        return $candidateAt->greaterThan($referenceAt);
    }

    private static function isSpaceSnapshotAffectingType(string $type): bool
    {
        return in_array($type, [
            OperationType::TENANT_SWITCH,
            OperationType::RENT_RATE_CHANGE,
            OperationType::SPACE_ATTRS_CHANGE,
        ], true);
    }

    private static function closeSpaceSnapshotBindings(int $marketId, int $spaceId, string $resolutionReason): void
    {
        $now = now();

        DB::table('market_space_tenant_bindings')
            ->where('market_id', $marketId)
            ->where('market_space_id', $spaceId)
            ->whereNull('tenant_contract_id')
            ->where('binding_type', 'space_snapshot')
            ->whereNull('ended_at')
            ->update([
                'ended_at' => $now,
                'updated_at' => $now,
                'resolution_reason' => $resolutionReason,
            ]);
    }
}
