<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Domain\Operations\OperationType;
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

        if (array_key_exists('is_active', $payload)) {
            $normalized['is_active'] = self::boolOrNull($payload['is_active']);
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
}
