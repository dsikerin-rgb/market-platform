<?php

declare(strict_types=1);

namespace App\Services\MarketSpaces;

use App\Models\MarketSpace;
use App\Models\MarketSpaceTenantBinding;
use App\Models\TenantContract;
use Illuminate\Support\Facades\Auth;

class MarketSpaceTenantBindingRecorder
{
    public function syncFromSpaceSnapshot(MarketSpace $space): void
    {
        if (! $space->exists || ! $space->wasChanged('tenant_id')) {
            return;
        }

        $now = now();

        MarketSpaceTenantBinding::query()
            ->where('market_space_id', $space->id)
            ->whereNull('ended_at')
            ->update([
                'ended_at' => $now,
                'updated_at' => $now,
                'resolution_reason' => 'space_snapshot_changed',
            ]);

        if (! $space->tenant_id) {
            return;
        }

        $alreadyActive = MarketSpaceTenantBinding::query()
            ->where('market_space_id', $space->id)
            ->where('tenant_id', $space->tenant_id)
            ->whereNull('tenant_contract_id')
            ->where('binding_type', 'space_snapshot')
            ->whereNull('ended_at')
            ->exists();

        if ($alreadyActive) {
            return;
        }

        MarketSpaceTenantBinding::query()->create([
            'market_id' => (int) $space->market_id,
            'market_space_id' => (int) $space->id,
            'tenant_id' => (int) $space->tenant_id,
            'tenant_contract_id' => null,
            'started_at' => $now,
            'ended_at' => null,
            'binding_type' => 'space_snapshot',
            'confidence' => 'medium',
            'source' => 'market_space_snapshot',
            'created_by_user_id' => Auth::id(),
            'resolution_reason' => 'space_snapshot_changed',
            'meta' => [
                'status' => $space->status,
                'is_active' => (bool) $space->is_active,
            ],
        ]);
    }

    public function syncFromContract(TenantContract $contract): void
    {
        if (! $contract->exists) {
            return;
        }

        $now = now();
        $activeBinding = $this->buildContractBindingPayload($contract);

        if ($activeBinding === null) {
            MarketSpaceTenantBinding::query()
                ->where('tenant_contract_id', $contract->id)
                ->whereNull('ended_at')
                ->update([
                    'ended_at' => $this->resolveContractEnd($contract, $now),
                    'updated_at' => $now,
                    'resolution_reason' => $this->inactiveReason($contract),
                ]);

            return;
        }

        MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $contract->id)
            ->whereNull('ended_at')
            ->where(function ($query) use ($activeBinding): void {
                $query->where('market_space_id', '!=', $activeBinding['market_space_id'])
                    ->orWhere('tenant_id', '!=', $activeBinding['tenant_id']);
            })
            ->update([
                'ended_at' => $activeBinding['started_at'],
                'updated_at' => $now,
                'resolution_reason' => 'contract_rebound',
            ]);

        MarketSpaceTenantBinding::query()
            ->where('market_space_id', $activeBinding['market_space_id'])
            ->whereNull('ended_at')
            ->where('binding_type', '!=', 'space_snapshot')
            ->where(function ($query) use ($activeBinding): void {
                $query->where('tenant_contract_id', '!=', $activeBinding['tenant_contract_id'])
                    ->orWhereNull('tenant_contract_id')
                    ->orWhere('tenant_id', '!=', $activeBinding['tenant_id']);
            })
            ->update([
                'ended_at' => $activeBinding['started_at'],
                'updated_at' => $now,
                'resolution_reason' => 'superseded_by_contract_binding',
            ]);

        $existing = MarketSpaceTenantBinding::query()
            ->where('tenant_contract_id', $contract->id)
            ->where('market_space_id', $activeBinding['market_space_id'])
            ->where('tenant_id', $activeBinding['tenant_id'])
            ->whereNull('ended_at')
            ->first();

        if ($existing) {
            $existing->fill([
                'binding_type' => $activeBinding['binding_type'],
                'confidence' => $activeBinding['confidence'],
                'source' => $activeBinding['source'],
                'created_by_user_id' => $activeBinding['created_by_user_id'],
                'resolution_reason' => $activeBinding['resolution_reason'],
                'meta' => $activeBinding['meta'],
            ]);
            $existing->save();

            return;
        }

        MarketSpaceTenantBinding::query()->create($activeBinding);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildContractBindingPayload(TenantContract $contract): ?array
    {
        if (! $contract->tenant_id || ! $contract->market_space_id) {
            return null;
        }

        if ($contract->excludesFromSpaceMapping()) {
            return null;
        }

        if (! (bool) $contract->is_active) {
            return null;
        }

        if (in_array((string) $contract->status, ['terminated', 'archived'], true)) {
            return null;
        }

        $startsAt = $contract->starts_at?->copy()?->startOfDay() ?? now();
        $mode = (string) ($contract->space_mapping_mode ?? '');
        $isManual = $contract->usesManualSpaceMapping();

        return [
            'market_id' => (int) $contract->market_id,
            'market_space_id' => (int) $contract->market_space_id,
            'tenant_id' => (int) $contract->tenant_id,
            'tenant_contract_id' => (int) $contract->id,
            'started_at' => $startsAt,
            'ended_at' => null,
            'binding_type' => $isManual ? 'manual' : 'exact',
            'confidence' => 'high',
            'source' => $isManual ? 'tenant_contract_manual' : ('tenant_contract_' . ($mode !== '' ? $mode : 'auto')),
            'created_by_user_id' => $contract->space_mapping_updated_by_user_id ?: Auth::id(),
            'resolution_reason' => $isManual ? 'manual_contract_space_link' : 'contract_space_link',
            'meta' => [
                'contract_status' => $contract->status,
                'space_mapping_mode' => $contract->space_mapping_mode,
                'signed_at' => $contract->signed_at?->format('Y-m-d'),
                'ends_at' => $contract->ends_at?->format('Y-m-d'),
            ],
        ];
    }

    private function resolveContractEnd(TenantContract $contract, \Illuminate\Support\Carbon $fallback): \Illuminate\Support\Carbon
    {
        return $contract->ends_at?->copy()?->endOfDay() ?? $fallback;
    }

    private function inactiveReason(TenantContract $contract): string
    {
        if ($contract->excludesFromSpaceMapping()) {
            return 'contract_excluded_from_mapping';
        }

        if (! $contract->market_space_id) {
            return 'contract_space_unset';
        }

        if (! $contract->tenant_id) {
            return 'contract_tenant_unset';
        }

        if (! (bool) $contract->is_active) {
            return 'contract_inactive';
        }

        if (in_array((string) $contract->status, ['terminated', 'archived'], true)) {
            return 'contract_' . (string) $contract->status;
        }

        return 'contract_binding_removed';
    }
}
