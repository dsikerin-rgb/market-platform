<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Domain\Operations\OperationType;
use App\Models\Operation;
use Carbon\CarbonImmutable;

final class OperationsStateService
{
    /**
     * @return array{tenant_id: int|null, rent_rate: float|null, electricity: float, adjustments: float}
     */
    public function getSpaceStateForPeriod(int $marketId, CarbonImmutable $period, int $marketSpaceId): array
    {
        $month = $period->startOfMonth()->toDateString();
        $periodEndUtc = $period->endOfMonth()->utc();

        $tenantOp = Operation::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $marketSpaceId)
            ->where('type', OperationType::TENANT_SWITCH)
            ->where('effective_at', '<=', $periodEndUtc->toDateTimeString())
            ->orderByDesc('effective_at')
            ->first();

        $rentOp = Operation::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $marketSpaceId)
            ->where('type', OperationType::RENT_RATE_CHANGE)
            ->where('effective_at', '<=', $periodEndUtc->toDateTimeString())
            ->orderByDesc('effective_at')
            ->first();

        $electricity = 0.0;
        $adjustments = 0.0;

        $monthlyOps = Operation::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('entity_id', $marketSpaceId)
            ->where('effective_month', $month)
            ->whereIn('type', [OperationType::ELECTRICITY_INPUT, OperationType::ACCRUAL_ADJUSTMENT])
            ->get();

        foreach ($monthlyOps as $op) {
            $payload = is_array($op->payload) ? $op->payload : [];

            if ($op->type === OperationType::ELECTRICITY_INPUT) {
                $electricity += (float) ($payload['amount'] ?? 0);
            }

            if ($op->type === OperationType::ACCRUAL_ADJUSTMENT) {
                $adjustments += (float) ($payload['amount_delta'] ?? 0);
            }
        }

        return [
            'tenant_id' => $tenantOp ? (int) ($tenantOp->payload['to_tenant_id'] ?? 0) ?: null : null,
            'rent_rate' => $rentOp ? (float) ($rentOp->payload['rent_rate'] ?? 0) ?: null : null,
            'electricity' => $electricity,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @return array<int, float> [market_space_id => amount]
     */
    public function getElectricityTotalsForPeriod(int $marketId, CarbonImmutable $period): array
    {
        return $this->collectAmountBySpace($marketId, $period, OperationType::ELECTRICITY_INPUT, 'amount');
    }

    /**
     * @return array<int, float> [market_space_id => amount]
     */
    public function getAdjustmentTotalsForPeriod(int $marketId, CarbonImmutable $period): array
    {
        return $this->collectAmountBySpace($marketId, $period, OperationType::ACCRUAL_ADJUSTMENT, 'amount_delta');
    }

    /**
     * @return array<int, float>
     */
    private function collectAmountBySpace(int $marketId, CarbonImmutable $period, string $type, string $payloadKey): array
    {
        $month = $period->startOfMonth()->toDateString();

        $rows = Operation::query()
            ->where('market_id', $marketId)
            ->where('entity_type', 'market_space')
            ->where('effective_month', $month)
            ->where('type', $type)
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $payload = is_array($row->payload) ? $row->payload : [];
            $spaceId = (int) ($payload['market_space_id'] ?? $row->entity_id ?? 0);

            if ($spaceId <= 0) {
                continue;
            }

            $amount = (float) ($payload[$payloadKey] ?? 0);
            $totals[$spaceId] = ($totals[$spaceId] ?? 0) + $amount;
        }

        return $totals;
    }
}
