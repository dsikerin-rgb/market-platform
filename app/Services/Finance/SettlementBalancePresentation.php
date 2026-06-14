<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Models\TenantSettlementBalance;
use Illuminate\Support\Collection;
use stdClass;

class SettlementBalancePresentation
{
    private const EPSILON = 0.009;

    /**
     * @param  Collection<int, TenantSettlementBalance>  $rows
     * @return Collection<int, object>
     */
    public function contractGroups(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (TenantSettlementBalance $row): string => $this->contractGroupKey($row))
            ->map(function (Collection $group): object {
                /** @var TenantSettlementBalance $first */
                $first = $group->first();

                $row = new stdClass();
                $row->tenant_id = $first->tenant_id;
                $row->tenant_name = $first->tenant_name;
                $row->tenant_contract_id = $first->tenant_contract_id;
                $row->tenantContract = $first->tenantContract;
                $row->contract_external_id = $first->contract_external_id;
                $row->contract_name = $first->contract_name;
                $row->organization_name = $first->organization_name;
                $row->account = $first->account;
                $row->rows_count = $group->count();
                $row->opening_debit = $this->sum($group, 'opening_debit');
                $row->opening_credit = $this->sum($group, 'opening_credit');
                $row->turnover_debit = $this->sum($group, 'turnover_debit');
                $row->turnover_credit = $this->sum($group, 'turnover_credit');
                $row->closing_debit = $this->sum($group, 'closing_debit');
                $row->closing_credit = $this->sum($group, 'closing_credit');
                $row->imported_at = $group->pluck('imported_at')->filter()->sortDesc()->first();
                $row->is_current_contract = $group->contains(fn (TenantSettlementBalance $item): bool => $this->isCurrentContractRow($item));

                return $row;
            })
            ->values();
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    public function workRows(Collection $rows, ?int $limit = null): Collection
    {
        $visible = $rows
            ->reject(fn (object $row): bool => $this->isSilentArchiveRow($row))
            ->sort(fn (object $left, object $right): int => $this->compareWorkRows($left, $right))
            ->values();

        return $limit !== null ? $visible->take($limit)->values() : $visible;
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    public function hiddenRowsCount(Collection $rows): int
    {
        return (int) $rows
            ->filter(fn (object $row): bool => $this->isSilentArchiveRow($row))
            ->sum(fn (object $row): int => $this->rowCount($row));
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    public function hiddenGroupsCount(Collection $rows): int
    {
        return $rows
            ->filter(fn (object $row): bool => $this->isSilentArchiveRow($row))
            ->count();
    }

    public function markCurrentContracts(Collection $rows, Collection $contractExternalIds): Collection
    {
        $ids = $contractExternalIds
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        return $rows->each(function (object $row) use ($ids): void {
            $externalId = trim((string) data_get($row, 'contract_external_id', ''));
            $row->is_current_contract = $externalId !== '' && $ids->contains($externalId);
        });
    }

    public function isSilentArchiveRow(object $row): bool
    {
        return $this->isZero($this->net($row))
            && $this->isZero((float) data_get($row, 'turnover_debit', 0))
            && $this->isZero((float) data_get($row, 'turnover_credit', 0))
            && ! (bool) data_get($row, 'is_current_contract', false);
    }

    private function compareWorkRows(object $left, object $right): int
    {
        $leftPriority = $this->priority($left);
        $rightPriority = $this->priority($right);

        if ($leftPriority !== $rightPriority) {
            return $leftPriority <=> $rightPriority;
        }

        $amountCompare = abs($this->net($right)) <=> abs($this->net($left));

        return $amountCompare !== 0
            ? $amountCompare
            : strcmp(
                mb_strtolower((string) data_get($left, 'contract_name', '')),
                mb_strtolower((string) data_get($right, 'contract_name', '')),
            );
    }

    private function priority(object $row): int
    {
        $net = $this->net($row);

        if ($net > self::EPSILON) {
            return 10;
        }

        if (! $this->isZero((float) data_get($row, 'turnover_debit', 0)) || ! $this->isZero((float) data_get($row, 'turnover_credit', 0))) {
            return 20;
        }

        if ($net < -self::EPSILON) {
            return 30;
        }

        if ((bool) data_get($row, 'is_current_contract', false)) {
            return 40;
        }

        return 90;
    }

    private function net(object $row): float
    {
        return (float) data_get($row, 'closing_debit', 0) - (float) data_get($row, 'closing_credit', 0);
    }

    private function isZero(float $value): bool
    {
        return abs($value) <= self::EPSILON;
    }

    private function rowCount(object $row): int
    {
        return max(1, (int) data_get($row, 'rows_count', 1));
    }

    private function isCurrentContractRow(TenantSettlementBalance $row): bool
    {
        $contract = $row->tenantContract;

        if (! $contract) {
            return false;
        }

        $status = trim((string) $contract->status);

        if (! $contract->is_active || in_array($status, ['terminated', 'archived'], true)) {
            return false;
        }

        if (! $contract->market_space_id) {
            return false;
        }

        $space = $contract->marketSpace;

        return ! $space || (bool) $space->is_active;
    }

    private function contractGroupKey(TenantSettlementBalance $row): string
    {
        return implode('|', [
            (string) ($row->tenant_contract_id ?? ''),
            trim((string) ($row->contract_external_id ?? '')),
            mb_strtolower(trim((string) ($row->contract_name ?? ''))),
            mb_strtolower(trim((string) ($row->organization_name ?? ''))),
            trim((string) ($row->account ?? '')),
        ]);
    }

    private function sum(Collection $rows, string $column): float
    {
        return (float) $rows->sum(fn (TenantSettlementBalance $row): float => (float) data_get($row, $column, 0));
    }
}
