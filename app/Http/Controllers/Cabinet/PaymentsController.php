<?php

namespace App\Http\Controllers\Cabinet;

use App\Http\Controllers\Controller;
use App\Models\TenantAccrual;
use App\Models\TenantPayment;
use App\Models\TenantSettlementBalance;
use App\Services\Finance\SettlementBalancePresentation;
use App\Support\CabinetAssistanceMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PaymentsController extends Controller
{
    public function __invoke(Request $request): View
    {
        abort_unless(CabinetAssistanceMode::canViewFinance($request), 403);

        $user = $request->user();
        $tenant = $user->tenant;
        $allowedSpaceIds = $this->financeAllowedSpaceIds($user);

        $availablePeriods = $this->availablePeriods((int) $tenant->id, (int) $tenant->market_id);
        $selectedPeriod = $this->selectedPeriod($request->string('month')->toString(), $availablePeriods);
        [$periodFrom, $periodTo] = $this->periodRange($selectedPeriod);

        $settlementRows = $this->settlementRows(
            tenantId: (int) $tenant->id,
            marketId: (int) $tenant->market_id,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            allowedSpaceIds: $allowedSpaceIds,
        );

        $accruals = $this->accrualRows(
            tenantId: (int) $tenant->id,
            marketId: (int) $tenant->market_id,
            periodFrom: $periodFrom,
            allowedSpaceIds: $allowedSpaceIds,
        );

        $payments = $this->paymentRows(
            tenantId: (int) $tenant->id,
            marketId: (int) $tenant->market_id,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
            allowedSpaceIds: $allowedSpaceIds,
        );

        $settlementPresentation = app(SettlementBalancePresentation::class);
        $settlementGroups = $settlementPresentation->contractGroups($settlementRows);
        $visibleSettlementRows = $settlementPresentation->workRows($settlementGroups);
        $summary = $this->summary($settlementRows, $accruals, $payments);
        $summary['settlementGroupsCount'] = $settlementGroups->count();
        $summary['settlementHiddenRowsCount'] = $settlementPresentation->hiddenRowsCount($settlementGroups);
        $summary['settlementHiddenGroupsCount'] = $settlementPresentation->hiddenGroupsCount($settlementGroups);

        return view('cabinet.payments', [
            'tenant' => $tenant,
            'availablePeriods' => $availablePeriods,
            'selectedPeriod' => $selectedPeriod,
            'periodFrom' => $periodFrom,
            'periodTo' => $periodTo,
            'summary' => $summary,
            'settlementRows' => $visibleSettlementRows,
            'accruals' => $accruals,
            'payments' => $payments,
            'firstPeriodLabel' => $availablePeriods->last()?->translatedFormat('F Y'),
            'latestImportAt' => $settlementRows
                ->pluck('imported_at')
                ->filter()
                ->sortDesc()
                ->first(),
        ]);
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private function availablePeriods(int $tenantId, int $marketId): Collection
    {
        $periods = collect();

        if (Schema::hasTable('tenant_settlement_balances')) {
            $periods = $periods->merge(
                TenantSettlementBalance::query()
                    ->where('tenant_id', $tenantId)
                    ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
                    ->whereNotNull('period_from')
                    ->pluck('period_from')
            );
        }

        if (Schema::hasTable('tenant_accruals')) {
            $periods = $periods->merge(
                TenantAccrual::query()
                    ->where('tenant_id', $tenantId)
                    ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
                    ->whereNotNull('period')
                    ->pluck('period')
            );
        }

        if (Schema::hasTable('tenant_payments')) {
            $periods = $periods->merge(
                TenantPayment::query()
                    ->where('tenant_id', $tenantId)
                    ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
                    ->whereNotNull('period')
                    ->pluck('period')
            );
        }

        return $periods
            ->map(fn ($period): ?CarbonImmutable => $this->parsePeriod($period))
            ->filter()
            ->map(fn (CarbonImmutable $period): CarbonImmutable => $period->startOfMonth())
            ->unique(fn (CarbonImmutable $period): string => $period->format('Y-m'))
            ->sortByDesc(fn (CarbonImmutable $period): string => $period->format('Y-m'))
            ->values();
    }

    /**
     * @param  Collection<int, CarbonImmutable>  $availablePeriods
     */
    private function selectedPeriod(string $requestedPeriod, Collection $availablePeriods): CarbonImmutable
    {
        $requested = $this->parsePeriod($requestedPeriod);

        if ($requested instanceof CarbonImmutable) {
            $requestedKey = $requested->format('Y-m');
            $match = $availablePeriods->first(
                fn (CarbonImmutable $period): bool => $period->format('Y-m') === $requestedKey
            );

            if ($match instanceof CarbonImmutable) {
                return $match;
            }
        }

        return $availablePeriods->first() ?? CarbonImmutable::now()->startOfMonth();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodRange(CarbonImmutable $period): array
    {
        return [$period->startOfMonth(), $period->endOfMonth()];
    }

    /**
     * Main tenant account sees the whole tenant financial contour. Additional cabinet users
     * keep the existing per-space restriction.
     *
     * @return list<int>
     */
    private function financeAllowedSpaceIds(mixed $user): array
    {
        if (method_exists($user, 'hasRole') && $user->hasRole('merchant')) {
            return [];
        }

        if (! method_exists($user, 'allowedTenantSpaceIds')) {
            return [];
        }

        return $user->allowedTenantSpaceIds();
    }

    private function parsePeriod(mixed $value): ?CarbonImmutable
    {
        if (! filled($value)) {
            return null;
        }

        try {
            $raw = trim((string) $value);
            $date = preg_match('/^\d{4}-\d{2}$/', $raw) === 1
                ? CarbonImmutable::createFromFormat('Y-m-d', $raw.'-01')
                : CarbonImmutable::parse($raw);

            return $date instanceof CarbonImmutable
                ? $date->startOfMonth()
                : CarbonImmutable::instance($date)->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<int>  $allowedSpaceIds
     * @return Collection<int, TenantSettlementBalance>
     */
    private function settlementRows(
        int $tenantId,
        int $marketId,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
        array $allowedSpaceIds
    ): Collection {
        if (! Schema::hasTable('tenant_settlement_balances')) {
            return collect();
        }

        return TenantSettlementBalance::query()
            ->with(['tenantContract.marketSpace:id,number,display_name,code'])
            ->where('tenant_id', $tenantId)
            ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
            ->whereDate('period_from', $periodFrom->toDateString())
            ->whereDate('period_to', $periodTo->toDateString())
            ->when($allowedSpaceIds !== [], function ($query) use ($allowedSpaceIds): void {
                $query->where(function ($inner) use ($allowedSpaceIds): void {
                    $inner
                        ->whereNull('tenant_contract_id')
                        ->orWhereHas('tenantContract', fn ($contract) => $contract->whereIn('market_space_id', $allowedSpaceIds));
                });
            })
            ->orderBy('contract_name')
            ->orderBy('settlement_document_name')
            ->limit(500)
            ->get();
    }

    /**
     * @param  list<int>  $allowedSpaceIds
     * @return Collection<int, TenantAccrual>
     */
    private function accrualRows(
        int $tenantId,
        int $marketId,
        CarbonImmutable $periodFrom,
        array $allowedSpaceIds
    ): Collection {
        if (! Schema::hasTable('tenant_accruals')) {
            return collect();
        }

        return TenantAccrual::query()
            ->with(['tenantContract:id,number', 'marketSpace:id,number,display_name,code'])
            ->where('tenant_id', $tenantId)
            ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
            ->whereDate('period', $periodFrom->toDateString())
            ->when($allowedSpaceIds !== [], function ($query) use ($allowedSpaceIds): void {
                $query->where(function ($inner) use ($allowedSpaceIds): void {
                    $inner->whereNull('market_space_id')->orWhereIn('market_space_id', $allowedSpaceIds);
                });
            })
            ->orderBy('source_place_name')
            ->orderBy('source_place_code')
            ->limit(200)
            ->get();
    }

    /**
     * @param  list<int>  $allowedSpaceIds
     * @return Collection<int, TenantPayment>
     */
    private function paymentRows(
        int $tenantId,
        int $marketId,
        CarbonImmutable $periodFrom,
        CarbonImmutable $periodTo,
        array $allowedSpaceIds
    ): Collection {
        if (! Schema::hasTable('tenant_payments')) {
            return collect();
        }

        return TenantPayment::query()
            ->with(['tenantContract.marketSpace:id,number,display_name,code'])
            ->where('tenant_id', $tenantId)
            ->when($marketId > 0, fn ($query) => $query->where('market_id', $marketId))
            ->whereDate('period', $periodFrom->toDateString())
            ->when($allowedSpaceIds !== [], function ($query) use ($allowedSpaceIds): void {
                $query->where(function ($inner) use ($allowedSpaceIds): void {
                    $inner
                        ->whereNull('tenant_contract_id')
                        ->orWhereHas('tenantContract', fn ($contract) => $contract->whereIn('market_space_id', $allowedSpaceIds));
                });
            })
            ->orderByDesc('payment_date')
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * @param  Collection<int, TenantSettlementBalance>  $settlementRows
     * @param  Collection<int, TenantAccrual>  $accruals
     * @param  Collection<int, TenantPayment>  $payments
     * @return array<string, mixed>
     */
    private function summary(Collection $settlementRows, Collection $accruals, Collection $payments): array
    {
        $closingDebit = (float) $settlementRows->sum(fn (TenantSettlementBalance $row): float => (float) $row->closing_debit);
        $closingCredit = (float) $settlementRows->sum(fn (TenantSettlementBalance $row): float => (float) $row->closing_credit);
        $balance = round($closingDebit - $closingCredit, 2);
        $settlementAccrued = (float) $settlementRows->sum(fn (TenantSettlementBalance $row): float => (float) $row->turnover_debit);
        $settlementPaid = (float) $settlementRows->sum(fn (TenantSettlementBalance $row): float => (float) $row->turnover_credit);
        $accrualRowsTotal = (float) $accruals->sum(fn (TenantAccrual $row): float => (float) ($row->total_with_vat ?? $row->total_no_vat ?? 0));
        $paymentRowsTotal = (float) $payments->sum(fn (TenantPayment $row): float => (float) $row->amount);

        $status = 'zero';
        $statusLabel = 'Нет задолженности';
        $statusCaption = 'По ОСВ за выбранный период долг не отражён.';

        if ($balance > 0.009) {
            $status = 'debt';
            $statusLabel = 'Есть задолженность';
            $statusCaption = 'Итоговый остаток рассчитан по ОСВ 1С.';
        } elseif ($balance < -0.009) {
            $status = 'credit';
            $statusLabel = 'Переплата';
            $statusCaption = 'В ОСВ отражена переплата.';
        } elseif ($settlementRows->isEmpty()) {
            $status = 'empty';
            $statusLabel = 'Нет данных ОСВ';
            $statusCaption = 'За выбранный период ОСВ по арендатору не найдена.';
        }

        return [
            'status' => $status,
            'statusLabel' => $statusLabel,
            'statusCaption' => $statusCaption,
            'balance' => $balance,
            'accrued' => $settlementRows->isNotEmpty() ? $settlementAccrued : $accrualRowsTotal,
            'paid' => $settlementRows->isNotEmpty() ? $settlementPaid : $paymentRowsTotal,
            'accrualRowsTotal' => $accrualRowsTotal,
            'paymentRowsTotal' => $paymentRowsTotal,
            'settlementRowsCount' => $settlementRows->count(),
            'accrualRowsCount' => $accruals->count(),
            'paymentRowsCount' => $payments->count(),
        ];
    }
}
