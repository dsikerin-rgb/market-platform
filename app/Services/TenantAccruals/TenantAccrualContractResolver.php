<?php

declare(strict_types=1);

namespace App\Services\TenantAccruals;

use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class TenantAccrualContractResolver
{
    public function __construct(
        private readonly ContractDocumentClassifier $classifier,
    ) {
    }

    public function resolve(
        int $marketId,
        ?int $tenantId,
        ?int $marketSpaceId,
        CarbonImmutable $period,
    ): ?int {
        if (! $tenantId || ! $marketSpaceId) {
            return null;
        }

        $contracts = TenantContract::query()
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('market_space_id', $marketSpaceId)
            ->where(function ($query): void {
                $query
                    ->whereNull('space_mapping_mode')
                    ->orWhere('space_mapping_mode', '!=', TenantContract::SPACE_MAPPING_MODE_EXCLUDED);
            })
            ->orderBy('id')
            ->get([
                'id',
                'number',
                'signed_at',
                'starts_at',
                'space_mapping_mode',
            ]);

        if ($contracts->isEmpty()) {
            return null;
        }

        $primary = $contracts
            ->map(function (TenantContract $contract): array {
                $classification = $this->classifier->classify((string) ($contract->number ?? ''));

                return [
                    'contract' => $contract,
                    'category' => $classification['category'],
                    'document_date' => $classification['document_date'],
                ];
            })
            ->filter(fn (array $item): bool => $item['category'] === 'primary_contract')
            ->values();

        if ($primary->isEmpty()) {
            return null;
        }

        if ($primary->count() === 1) {
            /** @var TenantContract $resolved */
            $resolved = $primary->first()['contract'];

            return (int) $resolved->id;
        }

        $periodEnd = $period->endOfMonth()->format('Y-m-d');

        $dated = $primary
            ->map(function (array $item): array {
                /** @var TenantContract $contract */
                $contract = $item['contract'];
                $effectiveDate = $item['document_date'];

                if (! is_string($effectiveDate) || $effectiveDate === '') {
                    $effectiveDate = $contract->signed_at?->format('Y-m-d');
                }

                return [
                    'contract' => $contract,
                    'effective_date' => $effectiveDate,
                ];
            })
            ->filter(fn (array $item): bool => is_string($item['effective_date']) && $item['effective_date'] !== '')
            ->filter(fn (array $item): bool => $item['effective_date'] <= $periodEnd)
            ->sortBy([
                ['effective_date', 'desc'],
                [fn (array $item) => (int) $item['contract']->id, 'desc'],
            ])
            ->values();

        if ($dated->isEmpty()) {
            return null;
        }

        $bestDate = (string) $dated->first()['effective_date'];
        $best = $dated
            ->filter(fn (array $item): bool => $item['effective_date'] === $bestDate)
            ->values();

        if ($best->count() !== 1) {
            return null;
        }

        /** @var TenantContract $resolved */
        $resolved = $best->first()['contract'];

        return (int) $resolved->id;
    }
}
