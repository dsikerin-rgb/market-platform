<?php

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

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeSpaceReview(array $payload): array
    {
        $decision = self::stringOrNull($payload['decision'] ?? null, true);

        if (! in_array($decision, SpaceReviewDecision::values(), true)) {
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

        return $normalized;
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

    private static function stringOrNull(mixed $value, bool $required = false): ?string
    {
        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            if ($required) {
                throw ValidationException::withMessages(['payload' => 'Не заполнено обязательное поле.']);
            }
            return null;
        }

        return $value;
    }

    private static function boolOrNull(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (bool) $value;
    }

    private static function spaceStatusOrNull(mixed $value): ?string
    {
        $value = self::stringOrNull($value);

        if ($value === null) {
            return null;
        }

        $allowed = ['occupied', 'vacant', 'maintenance', 'reserved'];

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        throw ValidationException::withMessages([
            'payload.status' => 'Invalid market space status.',
        ]);
    }
}
