<?php
# app/Services/Operations/OperationPayloadValidator.php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Domain\Operations\OperationType;
use App\Domain\Operations\SpaceReviewDecision;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class OperationPayloadValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function normalize(string $type, array $payload): array
    {
        return match ($type) {
            OperationType::TENANT_SWITCH => self::normalizeTenantSwitch($payload),
            OperationType::RENT_RATE_CHANGE => self::normalizeRentRateChange($payload),
            OperationType::SPACE_ATTRS_CHANGE => self::normalizeSpaceAttrsChange($payload),
            OperationType::SPACE_REVIEW => self::normalizeSpaceReview($payload),
            OperationType::ELECTRICITY_INPUT => self::normalizeElectricityInput($payload),
            OperationType::ACCRUAL_ADJUSTMENT => self::normalizeAccrualAdjustment($payload),
            OperationType::PERIOD_CLOSE => self::normalizePeriodClose($payload),
            OperationType::GROUP_MEMBERSHIP => self::normalizeGroupMembership($payload),
            default => throw ValidationException::withMessages([
                'type' => 'Неизвестный тип операции.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeTenantSwitch(array $payload): array
    {
        return [
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
            'from_tenant_id' => self::intOrNull($payload['from_tenant_id'] ?? null),
            'to_tenant_id' => self::intOrNull($payload['to_tenant_id'] ?? null),
            'reason' => self::stringOrNull($payload['reason'] ?? null),
            'detach_from_group' => self::boolOrNull($payload['detach_from_group'] ?? null) ?? false,
            'from_group_parent_id' => self::intOrNull($payload['from_group_parent_id'] ?? null),
            'from_group_slot' => self::stringOrNull($payload['from_group_slot'] ?? null),
            'from_group_role' => self::stringOrNull($payload['from_group_role'] ?? null),
            'review_close_on_effective_at' => self::boolOrNull($payload['review_close_on_effective_at'] ?? null) ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeRentRateChange(array $payload): array
    {
        return [
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
            'from_rent_rate' => self::numericOrNull($payload['from_rent_rate'] ?? null),
            'rent_rate' => self::numericOrNull($payload['rent_rate'] ?? null, true),
            'currency' => self::stringOrNull($payload['currency'] ?? null),
            'unit' => self::stringOrNull($payload['unit'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeSpaceAttrsChange(array $payload): array
    {
        $payload = Arr::only($payload, [
            'market_space_id',
            'area_sqm',
            'activity_type',
            'location_id',
            'type',
            'status',
            'is_active',
            'number',
            'display_name',
        ]);

        $normalized = [
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
        ];

        if (array_key_exists('area_sqm', $payload)) {
            $normalized['area_sqm'] = self::numericOrNull($payload['area_sqm']);
        }

        if (array_key_exists('activity_type', $payload)) {
            $normalized['activity_type'] = self::stringOrNull($payload['activity_type']);
        }

        if (array_key_exists('location_id', $payload)) {
            $normalized['location_id'] = self::intOrNull($payload['location_id']);
        }

        if (array_key_exists('type', $payload)) {
            $normalized['type'] = self::stringOrNull($payload['type']);
        }

        if (array_key_exists('status', $payload)) {
            $normalized['status'] = self::spaceStatusOrNull($payload['status']);
        }

        if (array_key_exists('is_active', $payload)) {
            $normalized['is_active'] = self::boolOrNull($payload['is_active']);
        }

        if (array_key_exists('number', $payload)) {
            $normalized['number'] = self::stringOrNull($payload['number']);
        }

        if (array_key_exists('display_name', $payload)) {
            $normalized['display_name'] = self::stringOrNull($payload['display_name']);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeSpaceReview(array $payload): array
    {
        $decision = self::stringOrNull($payload['decision'] ?? null, true);

        $isMatchedDecision = $decision === 'matched';

        if (! in_array($decision, SpaceReviewDecision::values(), true) && ! $isMatchedDecision) {
            throw ValidationException::withMessages([
                'payload.decision' => 'Недопустимое решение ревизии.',
            ]);
        }

        $normalized = [
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
            'decision' => $decision,
        ];

        if (SpaceReviewDecision::requiresShapeId($decision)) {
            $normalized['shape_id'] = self::intOrNull($payload['shape_id'] ?? null, true);
        } elseif (array_key_exists('shape_id', $payload)) {
            $normalized['shape_id'] = self::intOrNull($payload['shape_id'] ?? null);
        }

        if (SpaceReviewDecision::requiresReason($decision)) {
            $normalized['reason'] = self::stringOrNull($payload['reason'] ?? null, true);
        } elseif (array_key_exists('reason', $payload)) {
            $normalized['reason'] = self::stringOrNull($payload['reason'] ?? null);
        }

        if (SpaceReviewDecision::requiresObservedTenantName($decision)) {
            $normalized['observed_tenant_name'] = self::stringOrNull($payload['observed_tenant_name'] ?? null, true);
        } elseif (array_key_exists('observed_tenant_name', $payload)) {
            $normalized['observed_tenant_name'] = self::stringOrNull($payload['observed_tenant_name'] ?? null);
        }

        if (SpaceReviewDecision::requiresCandidateSpaceId($decision)) {
            $normalized['candidate_market_space_id'] = self::intOrNull($payload['candidate_market_space_id'] ?? null, true);

            if (isset($payload['duplicate_resolution']) && is_array($payload['duplicate_resolution'])) {
                $normalized['duplicate_resolution'] = $payload['duplicate_resolution'];
            }
        } elseif (array_key_exists('candidate_market_space_id', $payload)) {
            $normalized['candidate_market_space_id'] = self::intOrNull($payload['candidate_market_space_id'] ?? null);
        }

        if (SpaceReviewDecision::requiresEffectiveDate($decision)) {
            $normalized['effective_date'] = self::dateStringOrNull($payload['effective_date'] ?? null, true);
        } elseif (array_key_exists('effective_date', $payload)) {
            $normalized['effective_date'] = self::dateStringOrNull($payload['effective_date'] ?? null);
        }

        if (SpaceReviewDecision::isIdentityFix($decision)) {
            $number = self::stringOrNull($payload['number'] ?? null);
            $displayName = self::stringOrNull($payload['display_name'] ?? null);

            if ($number === null && $displayName === null) {
                throw ValidationException::withMessages([
                    'payload' => 'Для уточнения места нужен номер или отображаемое имя.',
                ]);
            }

            $normalized['number'] = $number;
            $normalized['display_name'] = $displayName;
        } else {
            if (array_key_exists('number', $payload)) {
                $normalized['number'] = self::stringOrNull($payload['number'] ?? null);
            }

            if (array_key_exists('display_name', $payload)) {
                $normalized['display_name'] = self::stringOrNull($payload['display_name'] ?? null);
            }
        }

        if (isset($payload['retirement']) && is_array($payload['retirement'])) {
            $normalized['retirement'] = self::normalizeRetirementPayload($payload['retirement']);
        }

        if (array_key_exists('auto_closed_by_reconciliation', $payload)) {
            $normalized['auto_closed_by_reconciliation'] = (bool) $payload['auto_closed_by_reconciliation'];
        }

        if (array_key_exists('auto_close_at', $payload)) {
            $value = $payload['auto_close_at'];
            $normalized['auto_close_at'] = $value !== null ? (string) $value : null;
        }

        if (array_key_exists('auto_close_binding_id', $payload)) {
            $normalized['auto_close_binding_id'] = self::intOrNull($payload['auto_close_binding_id'] ?? null);
        }

        if (array_key_exists('source_review_operation_id', $payload)) {
            $normalized['source_review_operation_id'] = self::intOrNull($payload['source_review_operation_id'] ?? null);
        }

        if (array_key_exists('source_review_decision', $payload)) {
            $normalized['source_review_decision'] = self::stringOrNull($payload['source_review_decision'] ?? null);
        }

        if (array_key_exists('source_review_reason', $payload)) {
            $normalized['source_review_reason'] = self::stringOrNull($payload['source_review_reason'] ?? null);
        }

        if (array_key_exists('auto_closed_by_positive_conflict_audit', $payload)) {
            $normalized['auto_closed_by_positive_conflict_audit'] = (bool) $payload['auto_closed_by_positive_conflict_audit'];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeRetirementPayload(array $payload): array
    {
        $relationCounts = $payload['relation_counts'] ?? [];
        if (! is_array($relationCounts)) {
            $relationCounts = [];
        }

        return [
            'canonical_market_space_id' => self::intOrNull($payload['canonical_market_space_id'] ?? null),
            'deactivated_map_shapes' => self::intOrNull($payload['deactivated_map_shapes'] ?? null) ?? 0,
            'closed_snapshot_bindings' => self::intOrNull($payload['closed_snapshot_bindings'] ?? null) ?? 0,
            'relation_counts' => array_map(static fn (mixed $value): int => is_numeric($value) ? (int) $value : 0, $relationCounts),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeElectricityInput(array $payload): array
    {
        return [
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
            'amount' => self::numericOrNull($payload['amount'] ?? null, true),
            'unit' => self::stringOrNull($payload['unit'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeAccrualAdjustment(array $payload): array
    {
        $reason = self::stringOrNull($payload['reason'] ?? null, true);

        return [
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
            'amount_delta' => self::numericOrNull($payload['amount_delta'] ?? null, true),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizePeriodClose(array $payload): array
    {
        $period = $payload['period'] ?? null;

        if (! is_string($period) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $period)) {
            throw ValidationException::withMessages([
                'payload.period' => 'Нужна дата периода в формате YYYY-MM-01.',
            ]);
        }

        return [
            'period' => $period,
            'closed' => (bool) ($payload['closed'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeGroupMembership(array $payload): array
    {
        return [
            'action' => self::stringOrNull($payload['action'] ?? null, true),
            'market_space_id' => self::intOrNull($payload['market_space_id'] ?? null, true),
            'old_space_group_role' => self::stringOrNull($payload['old_space_group_role'] ?? null),
            'old_space_group_parent_id' => self::intOrNull($payload['old_space_group_parent_id'] ?? null),
            'old_space_group_slot' => self::stringOrNull($payload['old_space_group_slot'] ?? null),
            'new_space_group_role' => self::stringOrNull($payload['new_space_group_role'] ?? null),
            'new_space_group_parent_id' => self::intOrNull($payload['new_space_group_parent_id'] ?? null),
            'new_space_group_slot' => self::stringOrNull($payload['new_space_group_slot'] ?? null),
            'target_parent_id' => self::intOrNull($payload['target_parent_id'] ?? null),
            'target_slot' => self::stringOrNull($payload['target_slot'] ?? null),
            'source' => self::stringOrNull($payload['source'] ?? null),
            'user_comment' => self::stringOrNull($payload['user_comment'] ?? null),
        ];
    }

    private static function intOrNull(mixed $value, bool $required = false): ?int
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages(['payload' => 'Не заполнено обязательное поле.']);
            }
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        throw ValidationException::withMessages(['payload' => 'Ожидалось числовое значение.']);
    }

    private static function numericOrNull(mixed $value, bool $required = false): ?float
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages(['payload' => 'Не заполнено обязательное поле.']);
            }
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        throw ValidationException::withMessages(['payload' => 'Ожидалось числовое значение.']);
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function stringOrNull(mixed $value, bool $required = false): ?string
    {
        if ($value === null) {
            if ($required) {
                throw ValidationException::withMessages(['payload' => 'Не заполнено обязательное поле.']);
            }
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            if ($required) {
                throw ValidationException::withMessages(['payload' => 'Не заполнено обязательное поле.']);
            }
            return null;
        }

        return $value;
    }

    private static function dateStringOrNull(mixed $value, bool $required = false): ?string
    {
        $value = self::stringOrNull($value, $required);

        if ($value === null) {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ValidationException::withMessages(['payload' => 'Нужна дата в формате YYYY-MM-DD.']);
        }

        return $value;
    }

    private static function spaceStatusOrNull(mixed $value): ?string
    {
        $value = self::stringOrNull($value);

        if ($value === null) {
            return null;
        }

        return in_array($value, ['vacant', 'occupied', 'reserved', 'maintenance'], true)
            ? $value
            : null;
    }
}
