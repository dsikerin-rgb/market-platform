<?php

declare(strict_types=1);

namespace App\Support\TenantContracts;

use App\Models\TenantContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TenantContractOperationalActivity
{
    public const DEFAULT_STALE_AFTER_MONTHS_WITHOUT_ACCRUALS = 2;

    public function isOperationalForCurrentMap(
        TenantContract $contract,
        int $staleAfterMonths = self::DEFAULT_STALE_AFTER_MONTHS_WITHOUT_ACCRUALS,
    ): bool {
        if (! $this->hasOperationalBaseState($contract)) {
            return false;
        }

        $cutoff = $this->recentAccrualCutoffPeriod((int) $contract->market_id, $staleAfterMonths);
        if (! $cutoff instanceof CarbonImmutable) {
            return true;
        }

        if ($this->contractIsRecentEnoughToWaitForFirstAccrual($contract, $cutoff)) {
            return true;
        }

        return $this->hasRecentAccrual($contract, $cutoff);
    }

    public function shouldArchiveAsStale(
        TenantContract $contract,
        int $staleAfterMonths = self::DEFAULT_STALE_AFTER_MONTHS_WITHOUT_ACCRUALS,
    ): bool {
        if (! $this->hasArchivableBaseState($contract)) {
            return false;
        }

        $cutoff = $this->recentAccrualCutoffPeriod((int) $contract->market_id, $staleAfterMonths);
        if (! $cutoff instanceof CarbonImmutable) {
            return false;
        }

        if ($this->contractIsRecentEnoughToWaitForFirstAccrual($contract, $cutoff)) {
            return false;
        }

        if ($this->hasRecentAccrual($contract, $cutoff)) {
            return false;
        }

        return ! $this->hasRecentPayment($contract, $cutoff);
    }

    public function hasOperationalBaseState(TenantContract $contract): bool
    {
        if (! $contract->tenant_id) {
            return false;
        }

        if ($contract->excludesFromSpaceMapping()) {
            return false;
        }

        if (! (bool) $contract->is_active) {
            return false;
        }

        $status = trim((string) ($contract->status ?? ''));
        if (in_array($status, ['terminated', 'archived', 'cancelled'], true)) {
            return false;
        }

        if ($contract->ends_at && $contract->ends_at->copy()->endOfDay()->lessThan(now())) {
            return false;
        }

        return true;
    }

    public function hasArchivableBaseState(TenantContract $contract): bool
    {
        if (! $contract->tenant_id) {
            return false;
        }

        if (! (bool) $contract->is_active) {
            return false;
        }

        $status = trim((string) ($contract->status ?? ''));
        if (in_array($status, ['terminated', 'archived', 'cancelled'], true)) {
            return false;
        }

        return true;
    }

    public function recentAccrualCutoffPeriod(
        int $marketId,
        int $staleAfterMonths = self::DEFAULT_STALE_AFTER_MONTHS_WITHOUT_ACCRUALS,
    ): ?CarbonImmutable {
        $latestPeriod = $this->latestAccrualPeriod($marketId);
        if (! $latestPeriod instanceof CarbonImmutable) {
            return null;
        }

        return $latestPeriod
            ->startOfMonth()
            ->subMonths(max(0, $staleAfterMonths));
    }

    public function latestAccrualPeriod(int $marketId): ?CarbonImmutable
    {
        if (
            $marketId <= 0
            || ! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'market_id')
            || ! Schema::hasColumn('tenant_accruals', 'period')
        ) {
            return null;
        }

        $value = DB::table('tenant_accruals')
            ->where('market_id', $marketId)
            ->whereNotNull('period')
            ->max('period');

        if (! filled($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    public function hasRecentAccrual(TenantContract $contract, CarbonImmutable $cutoffPeriod): bool
    {
        if (
            ! Schema::hasTable('tenant_accruals')
            || ! Schema::hasColumn('tenant_accruals', 'market_id')
            || ! Schema::hasColumn('tenant_accruals', 'period')
        ) {
            return true;
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', (int) $contract->market_id)
            ->whereDate('period', '>=', $cutoffPeriod->toDateString());

        $this->scopeAccrualMatchesContract($query, $contract);

        return $query->exists();
    }

    public function hasRecentPayment(TenantContract $contract, CarbonImmutable $cutoffPeriod): bool
    {
        if (
            ! Schema::hasTable('tenant_payments')
            || ! Schema::hasColumn('tenant_payments', 'market_id')
            || ! Schema::hasColumn('tenant_payments', 'payment_date')
        ) {
            return false;
        }

        $query = DB::table('tenant_payments')
            ->where('market_id', (int) $contract->market_id)
            ->whereDate('payment_date', '>=', $cutoffPeriod->toDateString());

        $this->scopePaymentMatchesContract($query, $contract);

        return $query->exists();
    }

    public static function scopeOperationalForCurrentMap(
        mixed $query,
        string $contractAlias,
        int $marketId,
        int $staleAfterMonths = self::DEFAULT_STALE_AFTER_MONTHS_WITHOUT_ACCRUALS,
    ): mixed {
        self::scopeBaseActiveContract($query, $contractAlias);

        $activity = app(self::class);
        $cutoff = $activity->recentAccrualCutoffPeriod($marketId, $staleAfterMonths);
        if (! $cutoff instanceof CarbonImmutable) {
            return $query;
        }

        $query->where(function ($inner) use ($contractAlias, $cutoff): void {
            if (Schema::hasColumn('tenant_contracts', 'starts_at')) {
                $inner->whereDate($contractAlias.'.starts_at', '>=', $cutoff->toDateString());
            }

            if (Schema::hasColumn('tenant_contracts', 'signed_at')) {
                $inner->orWhereDate($contractAlias.'.signed_at', '>=', $cutoff->toDateString());
            }

            $inner->orWhereExists(function ($sub) use ($contractAlias, $cutoff): void {
                $sub->selectRaw('1')
                    ->from('tenant_accruals as recent_ta')
                    ->whereColumn('recent_ta.market_id', $contractAlias.'.market_id')
                    ->whereDate('recent_ta.period', '>=', $cutoff->toDateString())
                    ->where(function ($match) use ($contractAlias): void {
                        if (Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
                            $match->whereColumn('recent_ta.tenant_contract_id', $contractAlias.'.id');
                        }

                        if (
                            Schema::hasColumn('tenant_accruals', 'contract_external_id')
                            && Schema::hasColumn('tenant_contracts', 'external_id')
                        ) {
                            $match->orWhere(function ($external) use ($contractAlias): void {
                                $external
                                    ->whereNotNull($contractAlias.'.external_id')
                                    ->whereColumn('recent_ta.contract_external_id', $contractAlias.'.external_id');
                            });
                        }

                        if (
                            Schema::hasColumn('tenant_accruals', 'market_space_id')
                            && Schema::hasColumn('tenant_accruals', 'tenant_id')
                            && Schema::hasColumn('tenant_contracts', 'market_space_id')
                            && Schema::hasColumn('tenant_contracts', 'tenant_id')
                        ) {
                            $match->orWhere(function ($spaceTenant) use ($contractAlias): void {
                                if (Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
                                    $spaceTenant->whereNull('recent_ta.tenant_contract_id');
                                }

                                $spaceTenant
                                    ->whereColumn('recent_ta.market_space_id', $contractAlias.'.market_space_id')
                                    ->whereColumn('recent_ta.tenant_id', $contractAlias.'.tenant_id');
                            });
                        }
                    });
            });
        });

        return $query;
    }

    private static function scopeBaseActiveContract(mixed $query, string $contractAlias): void
    {
        if (Schema::hasColumn('tenant_contracts', 'is_active')) {
            $query->where($contractAlias.'.is_active', true);
        }

        if (Schema::hasColumn('tenant_contracts', 'status')) {
            $query->whereNotIn($contractAlias.'.status', ['terminated', 'archived', 'cancelled']);
        }

        if (Schema::hasColumn('tenant_contracts', 'space_mapping_mode')) {
            $query->where(function ($inner) use ($contractAlias): void {
                $inner
                    ->whereNull($contractAlias.'.space_mapping_mode')
                    ->orWhere($contractAlias.'.space_mapping_mode', '!=', TenantContract::SPACE_MAPPING_MODE_EXCLUDED);
            });
        }

        if (Schema::hasColumn('tenant_contracts', 'starts_at')) {
            $query->where(function ($inner) use ($contractAlias): void {
                $inner
                    ->whereNull($contractAlias.'.starts_at')
                    ->orWhere($contractAlias.'.starts_at', '<=', now());
            });
        }

        if (Schema::hasColumn('tenant_contracts', 'ends_at')) {
            $query->where(function ($inner) use ($contractAlias): void {
                $inner
                    ->whereNull($contractAlias.'.ends_at')
                    ->orWhere($contractAlias.'.ends_at', '>', now());
            });
        }
    }

    private function contractIsRecentEnoughToWaitForFirstAccrual(TenantContract $contract, CarbonImmutable $cutoffPeriod): bool
    {
        foreach ([$contract->starts_at, $contract->signed_at] as $date) {
            if ($date && $date->copy()->startOfDay()->greaterThanOrEqualTo($cutoffPeriod)) {
                return true;
            }
        }

        return false;
    }

    private function scopeAccrualMatchesContract(mixed $query, TenantContract $contract): void
    {
        $query->where(function ($match) use ($contract): void {
            if (Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
                $match->where('tenant_contract_id', (int) $contract->id);
            }

            $externalId = trim((string) ($contract->external_id ?? ''));
            if ($externalId !== '' && Schema::hasColumn('tenant_accruals', 'contract_external_id')) {
                $match->orWhere('contract_external_id', $externalId);
            }

            if (
                filled($contract->market_space_id)
                && filled($contract->tenant_id)
                && Schema::hasColumn('tenant_accruals', 'market_space_id')
                && Schema::hasColumn('tenant_accruals', 'tenant_id')
            ) {
                $match->orWhere(function ($spaceTenant) use ($contract): void {
                    if (Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
                        $spaceTenant->whereNull('tenant_contract_id');
                    }

                    $spaceTenant
                        ->where('market_space_id', (int) $contract->market_space_id)
                        ->where('tenant_id', (int) $contract->tenant_id);
                });
            }
        });
    }

    private function scopePaymentMatchesContract(mixed $query, TenantContract $contract): void
    {
        $query->where(function ($match) use ($contract): void {
            if (Schema::hasColumn('tenant_payments', 'tenant_contract_id')) {
                $match->where('tenant_contract_id', (int) $contract->id);
            }

            $externalId = trim((string) ($contract->external_id ?? ''));
            if ($externalId !== '' && Schema::hasColumn('tenant_payments', 'contract_external_id')) {
                $match->orWhere('contract_external_id', $externalId);
            }
        });
    }
}
