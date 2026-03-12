<?php

declare(strict_types=1);

namespace App\Services\TenantAccruals;

use App\Models\TenantContract;
use App\Services\TenantContracts\ContractDocumentClassifier;
use Carbon\CarbonImmutable;

class TenantAccrualContractResolver
{
    public const LOOKBACK_MONTHS = 3;

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
        return $this->resolveMatch($marketId, $tenantId, $marketSpaceId, $period)->tenantContractId;
    }

    public function resolveMatch(
        int $marketId,
        ?int $tenantId,
        ?int $marketSpaceId,
        CarbonImmutable $period,
        ?string $contractExternalId = null,
    ): TenantAccrualContractMatch {
        $contractExternalId = trim((string) $contractExternalId);

        if ($contractExternalId !== '') {
            $exactContractId = TenantContract::query()
                ->where('market_id', $marketId)
                ->where('external_id', $contractExternalId)
                ->value('id');

            if ($exactContractId) {
                return TenantAccrualContractMatch::exact((int) $exactContractId);
            }
        }

        if (! $tenantId || ! $marketSpaceId) {
            return TenantAccrualContractMatch::unmatched(
                source: $contractExternalId !== '' ? 'contract_external_id' : 'none',
                note: 'missing_tenant_or_market_space',
            );
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
            return TenantAccrualContractMatch::unmatched(
                source: 'tenant_space_period',
                note: $contractExternalId !== '' ? 'contract_external_id_missing_no_space_match' : 'no_contracts_for_tenant_and_space',
            );
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
            return TenantAccrualContractMatch::unmatched(
                source: 'tenant_space_period',
                note: 'no_primary_contracts',
            );
        }

        if ($primary->count() === 1) {
            /** @var TenantContract $resolved */
            $resolved = $primary->first()['contract'];

            return TenantAccrualContractMatch::resolved(
                tenantContractId: (int) $resolved->id,
                note: $contractExternalId !== '' ? 'resolved_without_direct_contract_card' : null,
            );
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
            return TenantAccrualContractMatch::ambiguous(
                note: 'multiple_primary_contracts_without_effective_date',
            );
        }

        $bestDate = (string) $dated->first()['effective_date'];
        $best = $dated
            ->filter(fn (array $item): bool => $item['effective_date'] === $bestDate)
            ->values();

        if ($best->count() !== 1) {
            return TenantAccrualContractMatch::ambiguous(
                note: 'multiple_primary_contracts_same_effective_date',
            );
        }

        /** @var TenantContract $resolved */
        $resolved = $best->first()['contract'];

        return TenantAccrualContractMatch::resolved(
            tenantContractId: (int) $resolved->id,
            note: $contractExternalId !== '' ? 'resolved_without_direct_contract_card' : 'resolved_by_latest_effective_date',
        );
    }
}
