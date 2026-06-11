<?php

// app/Services/Debt/DebtStatusResolver.php

namespace App\Services\Debt;

use App\Models\ContractDebt;
use App\Models\Market;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Единый сервис расчёта статуса задолженности арендатора.
 *
 * Возвращает нормализованный результат:
 * {
 *   mode: 'manual' | 'auto',
 *   status: 'green' | 'pending' | 'orange' | 'red' | 'gray' | null,
 *   label: string,
 *   updated_at: ?string,
 *   source: ?string,
 *   severity: int
 * }
 */
class DebtStatusResolver
{
    /**
     * Статусы задолженности.
     */
    private const STATUS_GREEN = 'green';

    private const STATUS_PENDING = 'pending';

    private const STATUS_ORANGE = 'orange';

    private const STATUS_RED = 'red';

    private const STATUS_GRAY = 'gray';

    private const SETTLEMENT_DEBT_SOURCE = 'tenant_settlement_balances';

    /**
     * Метки статусов.
     */
    private const STATUS_LABELS = [
        self::STATUS_GREEN => 'Нет задолженности',
        self::STATUS_PENDING => 'К оплате / срок не наступил',
        self::STATUS_ORANGE => 'Есть просрочка',
        self::STATUS_RED => 'Длительная просрочка',
        self::STATUS_GRAY => 'Нет данных',
    ];

    /**
     * Кеш для результатов.
     */
    private static array $cache = [];

    /**
     * Рассчитать статус задолженности для арендатора.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int}
     */
    public function resolve(Tenant $tenant): array
    {
        $cacheKey = $this->getCacheKey($tenant);
        if (isset(self::$cache[$cacheKey])) {
            return self::$cache[$cacheKey];
        }

        $result = $this->doResolve($tenant);
        self::$cache[$cacheKey] = $result;

        return $result;
    }

    public function labelForStatus(?string $status, int $marketId): string
    {
        $labels = $this->getStatusLabels($marketId);

        return $labels[$status] ?? $labels[self::STATUS_GRAY];
    }

    /**
     * Рассчитать статус для конкретного торгового места.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int,extra:?array}
     */
    public function resolveForMarketSpace(int $marketSpaceId, int $marketId): array
    {
        $labels = $this->getStatusLabels($marketId);

        // Получаем место и арендатора
        $space = DB::table('market_spaces')
            ->where('id', $marketSpaceId)
            ->where('market_id', $marketId)
            ->first(['tenant_id', 'is_active']);

        // Место не найдено или нет арендатора — нейтральный результат (scope=none)
        if (! $space || ! $space->tenant_id) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет арендатора',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        // Место не активно — нейтральный результат (scope=none)
        if (! $space->is_active) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Место не активно',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        $sharedUseParticipantCount = $this->activeSharedUseParticipantCount($marketSpaceId, $marketId);
        if ($sharedUseParticipantCount > 1) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Совместное использование: нет точной финансовой связи 1С',
                source: 'shared-use: multiple active participants',
                severity: 0,
                extra: [
                    'scope' => 'shared_use',
                    'active_count' => $sharedUseParticipantCount,
                ]
            );
        }

        $tenant = Tenant::find($space->tenant_id);
        if (! $tenant) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Арендатор не найден',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        // Берём только текущий активный контрактный контур места.
        // Исторические/неактивные договоры не должны давать scope=space.
        $contractExternalIds = $this->resolveActiveContractExternalIdsForMarketSpace(
            marketSpaceId: $marketSpaceId,
            marketId: $marketId,
            tenantId: (int) $space->tenant_id,
        );

        // Точной связи с местом нет — используем tenant-fallback
        if ($contractExternalIds->isEmpty()) {
            $fallbackResult = $this->makeTenantFallbackResult(
                $tenant,
                'tenant-fallback: no financial link to space',
                useSettlementBalances: $this->shouldUseSettlementBalancesForMap($marketId)
            );

            if ($fallbackResult !== null) {
                return $fallbackResult;
            }

            $tenantResolved = $this->resolveTenantForMapFallback($tenant);
            $tenantStatus = $tenantResolved['status'] ?? null;

            // Проверяем валидность tenant-level статуса
            if (in_array($tenantStatus, [self::STATUS_GREEN, self::STATUS_PENDING, self::STATUS_ORANGE, self::STATUS_RED], true)) {
                $tenantExtra = is_array($tenantResolved['extra'] ?? null) ? $tenantResolved['extra'] : [];
                $tenantExtra['scope'] = 'tenant_fallback';

                return $this->makeResult(
                    mode: $tenantResolved['mode'],
                    status: $tenantResolved['status'],
                    label: $tenantResolved['label'],
                    updatedAt: $tenantResolved['updated_at'],
                    source: 'tenant-fallback: нет финансовой связи с местом',
                    severity: $tenantResolved['severity'],
                    extra: $tenantExtra
                );
            }

            // tenant-fallback невалиден — нейтральный результат
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет данных 1С',
                source: 'tenant-fallback недоступен',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        if ($this->shouldUseSettlementBalancesForMap($marketId)) {
            $settlementData = $this->fetchSettlementBalanceData($tenant, $contractExternalIds);
            if ($settlementData !== null) {
                return $this->makeMapResultFromSettlementBalanceData(
                    $settlementData,
                    $labels,
                    $marketId,
                    'space'
                );
            }

            $fallbackResult = $this->makeTenantFallbackResult(
                $tenant,
                'tenant-fallback: no OSV data for linked space',
                useSettlementBalances: true
            );

            if ($fallbackResult !== null) {
                return $fallbackResult;
            }
        }

        if (! Schema::hasTable('contract_debts')) {
            // Таблица отсутствует — нейтральный результат (scope=none)
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Таблица contract_debts отсутствует',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        // Получаем долги по contract_external_id
        $hasDebt = Schema::hasColumn('contract_debts', 'debt_amount');
        $hasCalculatedAt = Schema::hasColumn('contract_debts', 'calculated_at');
        $hasCreatedAt = Schema::hasColumn('contract_debts', 'created_at');
        $hasPeriod = Schema::hasColumn('contract_debts', 'period');

        if (! $hasDebt) {
            // Поле отсутствует — нейтральный результат (scope=none)
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет поля debt_amount',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        // Запрашиваем долги по contract_external_id через модель
        $query = ContractDebt::latestContractStateQuery($marketId)
            ->whereIn('cd.contract_external_id', $contractExternalIds->all());

        // Определяем последний snapshot
        $snapshotLabel = null;
        if ($hasCalculatedAt) {
            $latest = $query->clone()->max('calculated_at');
            if ($latest) {
                try {
                    $snapshotLabel = Carbon::parse($latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        } elseif ($hasCreatedAt) {
            $latest = $query->clone()->max('created_at');
            if ($latest) {
                try {
                    $snapshotLabel = Carbon::parse($latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        } elseif ($hasPeriod) {
            $latest = $query->clone()->max('period');
            if ($latest) {
                $snapshotLabel = (string) $latest;
            }
        }

        $fields = ['debt_amount', 'period'];
        if (Schema::hasColumn('contract_debts', 'accrued_amount')) {
            $fields[] = 'accrued_amount';
        }
        if (Schema::hasColumn('contract_debts', 'paid_amount')) {
            $fields[] = 'paid_amount';
        }
        if ($hasCalculatedAt) {
            $fields[] = 'calculated_at';
        }
        if ($hasCreatedAt) {
            $fields[] = 'created_at';
        }

        $rows = $query->get($fields);
        $dueDateRows = $this->fetchContractDebtAgingRows($marketId, $contractExternalIds->all(), $fields);

        if ($rows->isEmpty()) {
            // Нет записей 1С для контрактов этого места — используем tenant-fallback
            $tenantResolved = $this->resolveTenantForMapFallback($tenant);
            $tenantStatus = $tenantResolved['status'] ?? null;

            // Проверяем валидность tenant-level статуса
            if (in_array($tenantStatus, [self::STATUS_GREEN, self::STATUS_PENDING, self::STATUS_ORANGE, self::STATUS_RED], true)) {
                $tenantExtra = is_array($tenantResolved['extra'] ?? null) ? $tenantResolved['extra'] : [];
                $tenantExtra['scope'] = 'tenant_fallback';

                return $this->makeResult(
                    mode: $tenantResolved['mode'],
                    status: $tenantResolved['status'],
                    label: $tenantResolved['label'],
                    updatedAt: $tenantResolved['updated_at'],
                    source: 'tenant-fallback: нет финансовых данных по месту',
                    severity: $tenantResolved['severity'],
                    extra: $tenantExtra
                );
            }

            // tenant-fallback невалиден — нейтральный результат
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет данных 1С',
                source: 'tenant-fallback недоступен',
                severity: 0,
                extra: ['scope' => 'none']
            );
        }

        $positiveDebtRows = $rows->filter(static function ($row): bool {
            return (float) ($row->debt_amount ?? 0) > 0.009;
        });

        if ($positiveDebtRows->isEmpty()) {
            $fallbackResult = $this->makeTenantFallbackResult(
                $tenant,
                'tenant-fallback: no space debt',
                useSettlementBalances: false
            );

            if ($fallbackResult !== null && $this->isTenantFallbackDebtStatus($fallbackResult['status'] ?? null)) {
                return $fallbackResult;
            }

            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: $labels[self::STATUS_GREEN],
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 0,
                extra: ['scope' => 'space']
            );
        }

        $displayDebtAmount = (float) $positiveDebtRows->sum('debt_amount');

        // Есть долг - определяем статус по просрочке
        $settings = $this->getMarketSettings($marketId);
        $graceDays = $settings['grace_days'] ?? 5;
        $yellowAfterDays = $settings['yellow_after_days'] ?? $settings['orange_after_days'] ?? 1;
        $redAfterDays = $settings['red_after_days'] ?? 30;
        $minimumDebtAmount = (float) ($settings['minimum_debt_amount'] ?? 500);

        if ($displayDebtAmount < $minimumDebtAmount) {
            $fallbackResult = $this->makeTenantFallbackResult(
                $tenant,
                'tenant-fallback: space debt below threshold',
                useSettlementBalances: false
            );

            if ($fallbackResult !== null && $this->isTenantFallbackDebtStatus($fallbackResult['status'] ?? null)) {
                return $fallbackResult;
            }

            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: $labels[self::STATUS_GREEN],
                updatedAt: $snapshotLabel,
                source: 'contract_debts: долг ниже порога',
                severity: 0,
                extra: ['debt_amount' => $displayDebtAmount, 'minimum_debt_amount' => $minimumDebtAmount, 'scope' => 'space']
            );
        }

        $dueDate = $this->calculateDueDateFromRows(
            $dueDateRows->isNotEmpty() ? $dueDateRows : $rows,
            $graceDays,
            $hasPeriod,
            $hasCalculatedAt,
            $hasCreatedAt
        );

        if ($dueDate === null) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Не удалось определить срок оплаты',
                updatedAt: $snapshotLabel,
                source: 'contract_debts: нет дат',
                severity: 0,
                extra: ['scope' => 'space']
            );
        }

        $now = Carbon::now();
        $isOverdue = $now->gt($dueDate);

        if (! $isOverdue) {
            $fallbackResult = $this->makeTenantFallbackResult(
                $tenant,
                'tenant-fallback: space debt not due',
                useSettlementBalances: false
            );

            if ($fallbackResult !== null && $this->isTenantFallbackDebtStatus($fallbackResult['status'] ?? null)) {
                return $fallbackResult;
            }

            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: $labels[self::STATUS_PENDING],
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 1,
                extra: ['overdue_days' => 0, 'debt_amount' => $displayDebtAmount, 'scope' => 'space']
            );
        }

        $daysOverdue = $dueDate->diffInDays($now);
        $totalDebtAmount = $displayDebtAmount;
        $overdueAmount = $this->calculateOverdueAmountFromRows(
            $dueDateRows->isNotEmpty() ? $dueDateRows : $rows,
            $graceDays,
            $hasPeriod,
            $hasCalculatedAt,
            $hasCreatedAt,
        );
        $displayDebtAmount = $overdueAmount > 0.009 ? $overdueAmount : $displayDebtAmount;

        if ($overdueAmount > 0.009 && $overdueAmount < $minimumDebtAmount) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: $labels[self::STATUS_PENDING],
                updatedAt: $snapshotLabel,
                source: 'contract_debts: overdue debt below threshold',
                severity: 1,
                extra: [
                    'overdue_days' => max(0, $daysOverdue),
                    'debt_amount' => $totalDebtAmount,
                    'overdue_debt_amount' => $overdueAmount,
                    'minimum_debt_amount' => $minimumDebtAmount,
                    'scope' => 'space',
                ]
            );
        }

        if ($daysOverdue >= $redAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_RED,
                label: $labels[self::STATUS_RED],
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 3,
                extra: ['overdue_days' => $daysOverdue, 'debt_amount' => $displayDebtAmount, 'scope' => 'space']
            );
        }

        if ($daysOverdue >= $yellowAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_ORANGE,
                label: $labels[self::STATUS_ORANGE],
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 2,
                extra: ['overdue_days' => $daysOverdue, 'debt_amount' => $displayDebtAmount, 'scope' => 'space']
            );
        }

        // Просрочка есть, но меньше yellow_after_days — pending
        return $this->makeResult(
            mode: 'auto',
            status: self::STATUS_PENDING,
            label: $labels[self::STATUS_PENDING],
            updatedAt: $snapshotLabel,
            source: 'contract_debts',
            severity: 1,
            extra: ['overdue_days' => max(0, $daysOverdue), 'debt_amount' => $displayDebtAmount, 'scope' => 'space']
        );
    }

    /**
     * Рассчитать дату оплаты (due date) из rows.
     */
    private function calculateDueDateFromRows(
        Collection $rows,
        int $graceDays,
        bool $hasPeriod,
        bool $hasCalculatedAt,
        bool $hasCreatedAt
    ): ?Carbon {
        $positiveRows = $rows->filter(static function ($row): bool {
            return (float) ($row->debt_amount ?? 0) > 0.009;
        });

        $agingRows = $positiveRows->isNotEmpty() ? $positiveRows : $rows;

        $oldBalanceDueDates = $agingRows
            ->map(fn ($row): ?Carbon => $this->calculateOldBalanceDueDate($row, $graceDays, $hasPeriod))
            ->filter()
            ->values();

        if ($oldBalanceDueDates->isNotEmpty()) {
            return $oldBalanceDueDates->sortBy(fn (Carbon $date): int => $date->getTimestamp())->first();
        }

        // 1. Для aging берём самую раннюю положительную debt row, а не последний snapshot.
        // Иначе новый snapshot "омолаживает" старую просрочку и скрывает overdue-статусы.
        if ($hasCalculatedAt) {
            $oldestCalculatedAt = $agingRows->min('calculated_at');
            if ($oldestCalculatedAt) {
                try {
                    return Carbon::parse($oldestCalculatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 2. Если calculated_at нет, используем самую раннюю положительную created_at.
        if ($hasCreatedAt) {
            $oldestCreatedAt = $agingRows->min('created_at');
            if ($oldestCreatedAt) {
                try {
                    return Carbon::parse($oldestCreatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 3. Fallback: самый ранний положительный period.
        if ($hasPeriod) {
            $oldestPeriod = $agingRows->min('period');
            if ($oldestPeriod) {
                try {
                    // period в формате YYYY-MM
                    if (preg_match('/^\d{4}-\d{2}/', $oldestPeriod) === 1) {
                        return Carbon::createFromFormat('Y-m-d', substr($oldestPeriod, 0, 7).'-01')
                            ->startOfMonth()
                            ->addDays($graceDays);
                    }
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        return null;
    }

    private function calculateOverdueAmountFromRows(
        Collection $rows,
        int $graceDays,
        bool $hasPeriod,
        bool $hasCalculatedAt,
        bool $hasCreatedAt
    ): float {
        $now = Carbon::now();
        $overdueAmount = 0.0;

        foreach ($rows as $row) {
            $debtAmount = (float) ($row->debt_amount ?? 0);
            if ($debtAmount <= 0.009) {
                continue;
            }

            $rowDueDate = $this->resolveRowDueDate(
                $row,
                $graceDays,
                $hasPeriod,
                $hasCalculatedAt,
                $hasCreatedAt,
            );

            if ($rowDueDate !== null && $rowDueDate->lte($now)) {
                $overdueAmount += $debtAmount;
            }
        }

        return $overdueAmount;
    }

    private function resolveRowDueDate(
        object $row,
        int $graceDays,
        bool $hasPeriod,
        bool $hasCalculatedAt,
        bool $hasCreatedAt
    ): ?Carbon {
        $oldBalanceDueDate = $this->calculateOldBalanceDueDate($row, $graceDays, $hasPeriod);
        if ($oldBalanceDueDate !== null) {
            return $oldBalanceDueDate;
        }

        if (property_exists($row, 'due_date') && ! empty($row->due_date)) {
            try {
                return Carbon::parse($row->due_date);
            } catch (\Throwable) {
                // continue
            }
        }

        if ($hasCalculatedAt && ! empty($row->calculated_at)) {
            try {
                return Carbon::parse($row->calculated_at)->addDays($graceDays);
            } catch (\Throwable) {
                // continue
            }
        }

        if ($hasCreatedAt && ! empty($row->created_at)) {
            try {
                return Carbon::parse($row->created_at)->addDays($graceDays);
            } catch (\Throwable) {
                // continue
            }
        }

        if ($hasPeriod) {
            $period = (string) ($row->period ?? '');
            if (preg_match('/^\d{4}-\d{2}/', $period) === 1) {
                try {
                    return Carbon::createFromFormat('Y-m-d', substr($period, 0, 7).'-01')
                        ->startOfMonth()
                        ->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        return null;
    }

    /**
     * Получить агрегированный статус для всех мест арендатора.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int,spaces_count:int,debt_spaces_count:int}
     */
    private function calculateOldBalanceDueDate(object $row, int $graceDays, bool $hasPeriod): ?Carbon
    {
        if (! $hasPeriod) {
            return null;
        }

        $period = (string) ($row->period ?? '');
        if (preg_match('/^\d{4}-\d{2}/', $period) !== 1) {
            return null;
        }

        if (! property_exists($row, 'accrued_amount') || ! property_exists($row, 'paid_amount')) {
            return null;
        }

        $debtAmount = (float) ($row->debt_amount ?? 0);
        $currentPeriodUnpaid = max(0.0, (float) ($row->accrued_amount ?? 0) - (float) ($row->paid_amount ?? 0));

        if ($debtAmount <= $currentPeriodUnpaid + 0.009) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', substr($period, 0, 7).'-01')
                ->startOfMonth()
                ->subMonthNoOverflow()
                ->addDays($graceDays);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $contractExternalIds
     * @param  list<string>  $fields
     */
    private function fetchContractDebtAgingRows(int $marketId, array $contractExternalIds, array $fields): Collection
    {
        $contractExternalIds = array_values(array_filter(array_unique(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $contractExternalIds
        ))));

        if ($contractExternalIds === []) {
            return collect();
        }

        return DB::query()
            ->fromSub(ContractDebt::currentStateQuery($marketId), 'cd')
            ->whereIn('cd.contract_external_id', $contractExternalIds)
            ->get(array_map(
                static fn (string $field): string => "cd.{$field}",
                $fields
            ));
    }

    private function fetchSettlementBalanceData(Tenant $tenant, ?Collection $contractExternalIds = null): ?array
    {
        if (
            ! Schema::hasTable('tenant_settlement_balances')
            || ! Schema::hasColumn('tenant_settlement_balances', 'tenant_id')
            || ! Schema::hasColumn('tenant_settlement_balances', 'contract_external_id')
            || ! Schema::hasColumn('tenant_settlement_balances', 'account')
            || ! Schema::hasColumn('tenant_settlement_balances', 'period_to')
            || ! Schema::hasColumn('tenant_settlement_balances', 'closing_debit')
            || ! Schema::hasColumn('tenant_settlement_balances', 'closing_credit')
        ) {
            return null;
        }

        $marketId = (int) $tenant->market_id;
        if ($marketId <= 0) {
            return null;
        }

        $latestPerAccount = DB::table('tenant_settlement_balances as latest')
            ->select('latest.account')
            ->selectRaw('MAX(latest.period_to) as latest_period_to')
            ->where('latest.market_id', $marketId)
            ->groupBy('latest.account');
        $this->applySettlementDebtAccountFilter($latestPerAccount, 'latest.account');

        $query = DB::table('tenant_settlement_balances as tsb')
            ->joinSub($latestPerAccount, 'latest_settlements', function ($join): void {
                $join->on('tsb.account', '=', 'latest_settlements.account')
                    ->on('tsb.period_to', '=', 'latest_settlements.latest_period_to');
            })
            ->where('tsb.market_id', $marketId);

        if ($contractExternalIds !== null) {
            $ids = $contractExternalIds
                ->map(static fn (mixed $value): string => trim((string) $value))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($ids === []) {
                return null;
            }

            $query->whereIn('tsb.contract_external_id', $ids);
        } else {
            $query->where('tsb.tenant_id', (int) $tenant->id);
        }

        $rows = $query
            ->select([
                'tsb.tenant_id',
                'tsb.tenant_contract_id',
                'tsb.account',
                'tsb.period_from',
                'tsb.period_to',
                'tsb.contract_external_id',
                'tsb.contract_name',
                'tsb.settlement_document_name',
                'tsb.imported_at',
                'tsb.opening_debit',
                'tsb.opening_credit',
                'tsb.turnover_debit',
                'tsb.turnover_credit',
                'tsb.closing_debit',
                'tsb.closing_credit',
            ])
            ->selectRaw('(COALESCE(tsb.closing_debit, 0) - COALESCE(tsb.closing_credit, 0)) as debt_amount')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $snapshotAt = $rows->max('imported_at');
        $snapshotLabel = null;
        if ($snapshotAt) {
            try {
                $snapshotLabel = Carbon::parse($snapshotAt)->format('d.m.Y H:i');
            } catch (\Throwable) {
                $snapshotLabel = (string) $snapshotAt;
            }
        }

        return [
            'rows' => $rows,
            'snapshot_label' => $snapshotLabel,
            'latest_period_to' => (string) $rows->max('period_to'),
        ];
    }

    private function applySettlementDebtAccountFilter(\Illuminate\Database\Query\Builder $query, string $column): void
    {
        $query->where(function (\Illuminate\Database\Query\Builder $accounts) use ($column): void {
            $accounts->whereIn($column, ContractDebt::CALCULATION_ACCOUNTS);

            foreach (ContractDebt::CALCULATION_ACCOUNT_PREFIXES as $prefix) {
                $accounts->orWhere($column, 'like', $prefix.'%');
            }
        });
    }

    private function makeResultFromSettlementBalanceData(array $data, array $labels, int $marketId, string $scope): array
    {
        $settings = $this->getMarketSettings($marketId);
        $graceDays = $settings['grace_days'] ?? 5;
        $yellowAfterDays = $settings['yellow_after_days'] ?? $settings['orange_after_days'] ?? 1;
        $redAfterDays = $settings['red_after_days'] ?? 30;
        $minimumDebtAmount = (float) ($settings['minimum_debt_amount'] ?? 500);
        /** @var Collection $rows */
        $rows = $data['rows'];

        $netDebtAmount = (float) $rows->sum('debt_amount');
        $extra = [
            'debt_amount' => $netDebtAmount,
            'scope' => $scope,
            'accounts' => $rows->pluck('account')->filter()->unique()->values()->all(),
            'latest_period_to' => $data['latest_period_to'] ?? null,
        ];

        if ($netDebtAmount <= 0.009) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: $labels[self::STATUS_GREEN],
                updatedAt: $data['snapshot_label'] ?? null,
                source: self::SETTLEMENT_DEBT_SOURCE,
                severity: 0,
                extra: $extra
            );
        }

        if ($netDebtAmount < $minimumDebtAmount) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: $labels[self::STATUS_GREEN],
                updatedAt: $data['snapshot_label'] ?? null,
                source: self::SETTLEMENT_DEBT_SOURCE.': debt below threshold',
                severity: 0,
                extra: $extra + ['minimum_debt_amount' => $minimumDebtAmount]
            );
        }

        $dueDate = $this->calculateSettlementDueDate($rows, (int) $graceDays);
        if ($dueDate === null) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: $labels[self::STATUS_GRAY],
                updatedAt: $data['snapshot_label'] ?? null,
                source: self::SETTLEMENT_DEBT_SOURCE.': no due date',
                severity: 0,
                extra: $extra
            );
        }

        $now = Carbon::now();
        if (! $now->gt($dueDate)) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: $labels[self::STATUS_PENDING],
                updatedAt: $data['snapshot_label'] ?? null,
                source: self::SETTLEMENT_DEBT_SOURCE,
                severity: 1,
                extra: $extra + ['overdue_days' => 0]
            );
        }

        $daysOverdue = $dueDate->diffInDays($now);
        $extra += ['overdue_days' => $daysOverdue];

        if ($daysOverdue >= $redAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_RED,
                label: $labels[self::STATUS_RED],
                updatedAt: $data['snapshot_label'] ?? null,
                source: self::SETTLEMENT_DEBT_SOURCE,
                severity: 3,
                extra: $extra
            );
        }

        if ($daysOverdue >= $yellowAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_ORANGE,
                label: $labels[self::STATUS_ORANGE],
                updatedAt: $data['snapshot_label'] ?? null,
                source: self::SETTLEMENT_DEBT_SOURCE,
                severity: 2,
                extra: $extra
            );
        }

        return $this->makeResult(
            mode: 'auto',
            status: self::STATUS_PENDING,
            label: $labels[self::STATUS_PENDING],
            updatedAt: $data['snapshot_label'] ?? null,
            source: self::SETTLEMENT_DEBT_SOURCE,
            severity: 1,
            extra: $extra
        );
    }

    private function makeMapResultFromSettlementBalanceData(
        array $data,
        array $labels,
        int $marketId,
        string $scope,
        ?string $decisionScope = null,
    ): array {
        $decisionScope ??= $scope;
        $rows = $data['rows'];
        $candidate = app(DebtDecisionPolicy::class)->candidateFromSettlementRows(
            marketId: $marketId,
            rows: $rows,
            scope: $decisionScope,
            reason: $scope === 'space'
                ? 'active space contract has OSV rows'
                : 'tenant OSV rows used as map fallback',
            account: implode(',', $rows->pluck('account')->filter()->unique()->values()->all()),
            agingPolicy: $this->settlementMapAgingPolicy($marketId),
        );

        $status = (string) ($candidate['status'] ?? self::STATUS_GRAY);
        $extra = [
            'scope' => $scope,
            'debt_amount' => (float) ($candidate['debt_amount'] ?? 0),
            'accounts' => $rows->pluck('account')->filter()->unique()->values()->all(),
            'latest_period_to' => $candidate['latest_period_to'] ?? ($data['latest_period_to'] ?? null),
            'amount_source' => $candidate['amount_source'] ?? null,
            'amount_basis' => $candidate['amount_basis'] ?? null,
            'aging_policy' => $candidate['aging_policy'] ?? null,
            'aging_source' => $candidate['aging_source'] ?? null,
            'due_date' => $candidate['due_date'] ?? null,
            'overdue_days' => $candidate['overdue_days'] ?? null,
            'confidence' => $candidate['confidence'] ?? null,
            'reason' => $candidate['reason'] ?? null,
        ];

        return $this->makeResult(
            mode: 'auto',
            status: $status,
            label: $labels[$status] ?? $labels[self::STATUS_GRAY],
            updatedAt: $data['snapshot_label'] ?? null,
            source: self::SETTLEMENT_DEBT_SOURCE.': map decision',
            severity: $this->getSeverity($status),
            extra: $extra
        );
    }

    private function calculateSettlementDueDate(Collection $rows, int $graceDays): ?Carbon
    {
        $positiveRows = $rows->filter(static function ($row): bool {
            return (float) ($row->debt_amount ?? 0) > 0.009;
        });

        $documentDates = $positiveRows
            ->map(fn ($row): ?Carbon => $this->parseSettlementDocumentDate((string) ($row->settlement_document_name ?? '')))
            ->filter()
            ->values();

        if ($documentDates->isNotEmpty()) {
            return $documentDates
                ->sortBy(fn (Carbon $date): int => $date->getTimestamp())
                ->first()
                ?->startOfDay()
                ->addDays($graceDays);
        }

        $periodFrom = $positiveRows->min('period_from');
        if (! $periodFrom) {
            return null;
        }

        try {
            return Carbon::parse($periodFrom)->startOfDay()->addDays($graceDays);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseSettlementDocumentDate(string $value): ?Carbon
    {
        if (preg_match('/\b(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?\b/u', $value, $matches) !== 1) {
            return null;
        }

        $time = sprintf(
            '%02d:%02d:%02d',
            isset($matches[4]) ? (int) $matches[4] : 0,
            isset($matches[5]) ? (int) $matches[5] : 0,
            isset($matches[6]) ? (int) $matches[6] : 0,
        );

        try {
            return Carbon::createFromFormat('d.m.Y H:i:s', "{$matches[1]}.{$matches[2]}.{$matches[3]} {$time}");
        } catch (\Throwable) {
            return null;
        }
    }

    public function getAggregateStatusForTenant(Tenant $tenant): array
    {
        $baseResult = $this->resolve($tenant);

        // Считаем количество мест
        $spacesCount = DB::table('market_spaces')
            ->where('tenant_id', $tenant->id)
            ->count();

        // Считаем количество мест с задолженностью
        $debtSpacesCount = 0;
        if ($baseResult['status'] !== self::STATUS_GREEN && $baseResult['status'] !== self::STATUS_GRAY) {
            // Если есть долг, считаем все места как "с задолженностью"
            // В будущем можно сделать более детальный расчёт по каждому месту
            $debtSpacesCount = $spacesCount;
        }

        return array_merge($baseResult, [
            'spaces_count' => $spacesCount,
            'debt_spaces_count' => $debtSpacesCount,
        ]);
    }

    /**
     * Основная логика расчёта.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int}
     */
    private function doResolve(Tenant $tenant): array
    {
        // 1. Проверяем ручной статус
        $manualResult = $this->checkManualStatus($tenant);
        if ($manualResult !== null) {
            return $manualResult;
        }

        // 2. Автоматический расчёт из contract_debts
        return $this->calculateAutoStatus($tenant);
    }

    /**
     * Проверить ручной статус.
     */
    private function checkManualStatus(Tenant $tenant): ?array
    {
        $manualStatus = trim($tenant->debt_status ?? '');

        if ($manualStatus !== '' && array_key_exists($manualStatus, self::STATUS_LABELS)) {
            return $this->makeResult(
                mode: 'manual',
                status: $manualStatus,
                label: $this->labelForStatus($manualStatus, (int) $tenant->market_id),
                updatedAt: $tenant->debt_status_updated_at?->format('d.m.Y H:i'),
                source: null,
                severity: $this->getSeverity($manualStatus)
            );
        }

        return null;
    }

    /**
     * Автоматический расчёт из contract_debts.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int}
     */
    private function calculateAutoStatus(Tenant $tenant, bool $useSettlementBalances = true): array
    {
        $settings = $this->getMarketSettings($tenant->market_id);
        $labels = $this->getStatusLabels((int) $tenant->market_id);
        $graceDays = $settings['grace_days'] ?? 5;
        $yellowAfterDays = $settings['yellow_after_days'] ?? $settings['orange_after_days'] ?? 1;
        $redAfterDays = $settings['red_after_days'] ?? 30;
        $minimumDebtAmount = (float) ($settings['minimum_debt_amount'] ?? 500);

        // Получаем данные из contract_debts
        if ($useSettlementBalances) {
            $settlementData = $this->fetchSettlementBalanceData($tenant);
            if ($settlementData !== null) {
                return $this->makeResultFromSettlementBalanceData(
                    $settlementData,
                    $labels,
                    (int) $tenant->market_id,
                    'tenant'
                );
            }
        }

        $debtsData = $this->fetchDebtsData($tenant);

        if ($debtsData === null) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: $labels[self::STATUS_GRAY],
                source: 'Данные 1С недоступны',
                severity: 0
            );
        }

        if ($debtsData['rows']->isEmpty()) {
            $accrualsFallback = $this->fetchAccrualsFallbackData($tenant);

            if ($accrualsFallback !== null && $accrualsFallback['count'] > 0) {
                $dueDate = $this->calculateDueDateFromAccruals($accrualsFallback, $graceDays);

                if ($dueDate === null) {
                    return $this->makeResult(
                        mode: 'auto',
                        status: self::STATUS_GREEN,
                        label: $labels[self::STATUS_GREEN],
                        updatedAt: $accrualsFallback['updated_at_label'],
                        source: 'Fallback: tenant_accruals, строк в contract_debts нет',
                        severity: 0
                    );
                }

                $now = Carbon::now();

                if ($now->gt($dueDate)) {
                    return $this->makeResult(
                        mode: 'auto',
                        status: self::STATUS_GREEN,
                        label: $labels[self::STATUS_GREEN],
                        updatedAt: $accrualsFallback['updated_at_label'],
                        source: 'Fallback: tenant_accruals, строк в contract_debts нет',
                        severity: 0
                    );
                }

                return $this->makeResult(
                    mode: 'auto',
                    status: self::STATUS_PENDING,
                    label: $labels[self::STATUS_PENDING],
                    updatedAt: $accrualsFallback['updated_at_label'],
                    source: 'Fallback: tenant_accruals, строк в contract_debts нет',
                    severity: 1
                );
            }

            // Нет записей по арендатору — это gray (данные есть, но записей нет)
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: $labels[self::STATUS_GRAY],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Нет данных 1С по арендатору',
                severity: 0
            );
        }

        $netDebtAmount = (float) $debtsData['rows']->sum('debt_amount');

        $positiveDebtRows = $debtsData['rows']->filter(static function ($row): bool {
            return (float) ($row->debt_amount ?? 0) > 0.009;
        });

        if ($positiveDebtRows->isEmpty() || $netDebtAmount <= 0.009) {
            // Записи есть, долг нулевой — это green
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: $labels[self::STATUS_GREEN],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 0,
                extra: ['debt_amount' => $netDebtAmount]
            );
        }

        $displayDebtAmount = $netDebtAmount;

        if ($displayDebtAmount < $minimumDebtAmount) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: $labels[self::STATUS_GREEN],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts, долг ниже порога',
                severity: 0,
                extra: ['debt_amount' => $displayDebtAmount, 'minimum_debt_amount' => $minimumDebtAmount]
            );
        }

        // Есть долг - определяем статус по сроку
        $dueDate = $this->calculateDueDate($debtsData, $graceDays);

        if ($dueDate === null) {
            // Не удалось определить срок - возвращаем gray
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: $labels[self::STATUS_GRAY],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Не удалось определить срок оплаты',
                severity: 0
            );
        }

        $now = Carbon::now();
        $isOverdue = $now->gt($dueDate);

        if (! $isOverdue) {
            // Срок ещё не наступил
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: $labels[self::STATUS_PENDING],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 1,
                extra: ['overdue_days' => 0, 'debt_amount' => $displayDebtAmount]
            );
        }

        // Просрочка - считаем дни
        $daysOverdue = $dueDate->diffInDays($now);
        $totalDebtAmount = $displayDebtAmount;
        $overdueAmount = $this->calculateOverdueAmountFromRows(
            ($debtsData['aging_rows'] ?? collect())->isNotEmpty() ? $debtsData['aging_rows'] : $debtsData['rows'],
            $graceDays,
            (bool) ($debtsData['has_period'] ?? false),
            (bool) ($debtsData['has_calculated_at'] ?? false),
            (bool) ($debtsData['has_created_at'] ?? false),
        );
        if ($overdueAmount > $netDebtAmount) {
            $overdueAmount = $netDebtAmount;
        }
        $displayDebtAmount = $overdueAmount > 0.009 ? $overdueAmount : $displayDebtAmount;

        if ($overdueAmount > 0.009 && $overdueAmount < $minimumDebtAmount) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: $labels[self::STATUS_PENDING],
                updatedAt: $debtsData['snapshot_label'],
                source: 'contract_debts: overdue debt below threshold',
                severity: 1,
                extra: [
                    'overdue_days' => max(0, $daysOverdue),
                    'debt_amount' => $totalDebtAmount,
                    'overdue_debt_amount' => $overdueAmount,
                    'minimum_debt_amount' => $minimumDebtAmount,
                ]
            );
        }

        if ($daysOverdue >= $redAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_RED,
                label: $labels[self::STATUS_RED],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 3,
                extra: ['overdue_days' => $daysOverdue, 'debt_amount' => $displayDebtAmount]
            );
        }

        if ($daysOverdue < $yellowAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: $labels[self::STATUS_PENDING],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 1,
                extra: ['overdue_days' => max(0, $daysOverdue), 'debt_amount' => $displayDebtAmount]
            );
        }

        return $this->makeResult(
            mode: 'auto',
            status: self::STATUS_ORANGE,
            label: $labels[self::STATUS_ORANGE],
            updatedAt: $debtsData['snapshot_label'],
            source: 'Источник: contract_debts',
            severity: 2,
            extra: ['overdue_days' => $daysOverdue, 'debt_amount' => $displayDebtAmount]
        );
    }

    /**
     * Получить данные о задолженностях из БД.
     *
     * @return array{rows:\Illuminate\Support\Collection,snapshot_label:?string}|null
     */
    private function fetchDebtsData(Tenant $tenant): ?array
    {
        if (! Schema::hasTable('contract_debts')) {
            return null;
        }

        $hasTenantExternalId = Schema::hasColumn('contract_debts', 'tenant_external_id');
        $hasContractExternalId = Schema::hasColumn('contract_debts', 'contract_external_id');
        $hasMarketId = Schema::hasColumn('contract_debts', 'market_id');
        $hasCalculatedAt = Schema::hasColumn('contract_debts', 'calculated_at');
        $hasCreatedAt = Schema::hasColumn('contract_debts', 'created_at');
        $hasPeriod = Schema::hasColumn('contract_debts', 'period');
        $hasDueDate = Schema::hasColumn('contract_debts', 'due_date');
        $hasDebt = Schema::hasColumn('contract_debts', 'debt_amount');

        if (! $hasDebt) {
            return null;
        }

        $query = ContractDebt::latestContractStateQuery((int) $tenant->market_id);

        // Prioritise the same contour as space-level:
        // 1) Get contract external_ids from tenant_contracts for this tenant
        // 2) If found, query contract_debts by contract_external_id
        // 3) Fallback to tenant_external_id / one_c_uid only if no contracts found
        $contractExternalIds = DB::table('tenant_contracts')
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->unique()
            ->values()
            ->all();
        $usesContractExternalIds = $hasContractExternalId && $contractExternalIds !== [];

        if ($usesContractExternalIds) {
            $query->whereIn('cd.contract_external_id', $contractExternalIds);
        } elseif ($hasTenantExternalId) {
            $externalId = trim($tenant->external_id ?? '');
            $oneCUid = trim($tenant->one_c_uid ?? '');

            if ($externalId !== '' || $oneCUid !== '') {
                $query->where(function ($q) use ($externalId, $oneCUid) {
                    if ($externalId !== '') {
                        $q->where('cd.tenant_external_id', $externalId);
                    }
                    if ($oneCUid !== '') {
                        $q->orWhere('cd.tenant_external_id', $oneCUid);
                    }
                });
            } else {
                $query->where('cd.tenant_id', $tenant->id);
            }
        } else {
            $query->where('cd.tenant_id', $tenant->id);
        }
        // Получаем поля
        $fields = ['debt_amount'];
        if (Schema::hasColumn('contract_debts', 'accrued_amount')) {
            $fields[] = 'accrued_amount';
        }
        if (Schema::hasColumn('contract_debts', 'paid_amount')) {
            $fields[] = 'paid_amount';
        }
        if ($hasDueDate) {
            $fields[] = 'due_date';
        }
        if ($hasCalculatedAt) {
            $fields[] = 'calculated_at';
        }
        if ($hasCreatedAt) {
            $fields[] = 'created_at';
        }
        if ($hasPeriod) {
            $fields[] = 'period';
        }

        $rows = $query->get($fields);
        $agingRows = $usesContractExternalIds
            ? $this->fetchContractDebtAgingRows((int) $tenant->market_id, $contractExternalIds, $fields)
            : collect();

        // Определяем snapshot label
        $snapshotLabel = null;
        if ($hasCalculatedAt && ! $rows->isEmpty()) {
            $latest = $rows->max('calculated_at');
            if ($latest) {
                try {
                    $snapshotLabel = Carbon::parse($latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        }

        return [
            'rows' => $rows,
            'aging_rows' => $agingRows,
            'snapshot_label' => $snapshotLabel,
            'has_due_date' => $hasDueDate,
            'has_calculated_at' => $hasCalculatedAt,
            'has_created_at' => $hasCreatedAt,
            'has_period' => $hasPeriod,
        ];
    }

    /**
     * Получить fallback-данные из tenant_accruals, если contract_debts пуст.
     *
     * @return array{count:int,last_period:?string,last_accrual_date:?string,updated_at_label:?string}|null
     */
    private function fetchAccrualsFallbackData(Tenant $tenant): ?array
    {
        if (! Schema::hasTable('tenant_accruals')) {
            return null;
        }

        $query = DB::table('tenant_accruals')
            ->where('market_id', $tenant->market_id)
            ->where('tenant_id', $tenant->id);

        $count = (clone $query)->count();

        if ($count === 0) {
            return null;
        }

        $hasPeriod = Schema::hasColumn('tenant_accruals', 'period');
        $hasAccrualDate = Schema::hasColumn('tenant_accruals', 'accrual_date');

        $lastPeriod = $hasPeriod ? (clone $query)->max('period') : null;
        $lastAccrualDate = $hasAccrualDate ? (clone $query)->max('accrual_date') : null;

        $updatedAtLabel = null;
        if ($lastAccrualDate) {
            try {
                $updatedAtLabel = Carbon::parse($lastAccrualDate)->format('d.m.Y');
            } catch (\Throwable) {
                $updatedAtLabel = (string) $lastAccrualDate;
            }
        } elseif ($lastPeriod) {
            $updatedAtLabel = (string) $lastPeriod;
        }

        return [
            'count' => $count,
            'last_period' => $lastPeriod ? (string) $lastPeriod : null,
            'last_accrual_date' => $lastAccrualDate ? (string) $lastAccrualDate : null,
            'updated_at_label' => $updatedAtLabel,
        ];
    }

    /**
     * Рассчитать дату оплаты из accruals.
     */
    private function calculateDueDateFromAccruals(array $data, int $graceDays): ?Carbon
    {
        $lastAccrualDate = $data['last_accrual_date'] ?? null;
        if ($lastAccrualDate) {
            try {
                return Carbon::parse($lastAccrualDate)->addDays($graceDays);
            } catch (\Throwable) {
                // continue
            }
        }

        $lastPeriod = $data['last_period'] ?? null;
        if ($lastPeriod) {
            try {
                if (preg_match('/^\d{4}-\d{2}/', (string) $lastPeriod) === 1) {
                    return Carbon::createFromFormat('Y-m-d', substr((string) $lastPeriod, 0, 7).'-01')
                        ->startOfMonth()
                        ->addDays($graceDays);
                }
            } catch (\Throwable) {
                // continue
            }
        }

        return null;
    }

    /**
     * Рассчитать дату оплаты (due date).
     *
     * For positive debt rows, use the OLDEST date to avoid "rejuvenating"
     * old debt with newer snapshots — same principle as calculateDueDateFromRows().
     */
    private function calculateDueDate(array $data, int $graceDays): ?Carbon
    {
        $rows = ($data['aging_rows'] ?? collect())->isNotEmpty()
            ? $data['aging_rows']
            : $data['rows'];

        // Filter to positive debt rows (same as calculateDueDateFromRows)
        $positiveRows = $rows->filter(static function ($row): bool {
            return (float) ($row->debt_amount ?? 0) > 0.009;
        });

        $agingRows = $positiveRows->isNotEmpty() ? $positiveRows : $rows;

        // 1. Если есть due_date - используем самый ранний
        $oldBalanceDueDates = $agingRows
            ->map(fn ($row): ?Carbon => $this->calculateOldBalanceDueDate($row, $graceDays, (bool) $data['has_period']))
            ->filter()
            ->values();

        if ($oldBalanceDueDates->isNotEmpty()) {
            return $oldBalanceDueDates->sortBy(fn (Carbon $date): int => $date->getTimestamp())->first();
        }

        if ($data['has_due_date']) {
            $earliestDueDate = null;
            foreach ($agingRows as $row) {
                if (! empty($row->due_date)) {
                    try {
                        $due = Carbon::parse($row->due_date);
                        if ($earliestDueDate === null || $due->lt($earliestDueDate)) {
                            $earliestDueDate = $due;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
            if ($earliestDueDate !== null) {
                return $earliestDueDate;
            }
        }

        // 2. Если есть calculated_at - используем самый ранний + grace_days
        if ($data['has_calculated_at']) {
            $oldestCalculatedAt = $agingRows->min('calculated_at');
            if ($oldestCalculatedAt) {
                try {
                    return Carbon::parse($oldestCalculatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 3. Если есть created_at - используем самый ранний + grace_days
        if ($data['has_created_at']) {
            $oldestCreatedAt = $agingRows->min('created_at');
            if ($oldestCreatedAt) {
                try {
                    return Carbon::parse($oldestCreatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 4. Если есть period - используем самый ранний + grace_days
        if ($data['has_period']) {
            $oldestPeriod = $agingRows->min('period');
            if ($oldestPeriod) {
                try {
                    // period в формате YYYY-MM
                    if (preg_match('/^\d{4}-\d{2}/', $oldestPeriod) === 1) {
                        return Carbon::createFromFormat('Y-m-d', substr($oldestPeriod, 0, 7).'-01')
                            ->startOfMonth()
                            ->addDays($graceDays);
                    }
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        return null;
    }

    /**
     * Получить настройки рынка.
     */
    private function getMarketSettings(int $marketId): array
    {
        $market = Market::find($marketId);
        if (! $market) {
            return [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
                'minimum_debt_amount' => 500,
            ];
        }

        $settings = $market->settings ?? [];
        $debtMonitoring = $settings['debt_monitoring'] ?? [];

        return [
            'grace_days' => $debtMonitoring['grace_days'] ?? 5,
            'yellow_after_days' => $debtMonitoring['yellow_after_days'] ?? $debtMonitoring['orange_after_days'] ?? 1,
            'red_after_days' => $debtMonitoring['red_after_days'] ?? 30,
            'minimum_debt_amount' => $debtMonitoring['minimum_debt_amount'] ?? 500,
        ];
    }

    private function shouldUseSettlementBalancesForMap(int $marketId): bool
    {
        $market = Market::find($marketId);
        $settings = is_array($market?->settings) ? $market->settings : [];
        $debtMonitoring = is_array($settings['debt_monitoring'] ?? null) ? $settings['debt_monitoring'] : [];

        return (bool) ($debtMonitoring['use_settlement_balances_for_map'] ?? false);
    }

    private function settlementMapAgingPolicy(int $marketId): string
    {
        $market = Market::find($marketId);
        $settings = is_array($market?->settings) ? $market->settings : [];
        $debtMonitoring = is_array($settings['debt_monitoring'] ?? null) ? $settings['debt_monitoring'] : [];
        $policy = (string) ($debtMonitoring['settlement_map_aging_policy'] ?? DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE);

        return in_array($policy, [
            DebtDecisionPolicy::AGING_INVOICE_DAY,
            DebtDecisionPolicy::AGING_PERIOD_START,
            DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT,
            DebtDecisionPolicy::AGING_SETTLEMENT_DOCUMENT_INVOICE_DAY,
            DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE,
        ], true)
            ? $policy
            : DebtDecisionPolicy::AGING_SETTLEMENT_NET_BALANCE;
    }

    private function makeTenantFallbackResult(Tenant $tenant, string $source, bool $useSettlementBalances = true): ?array
    {
        if ($useSettlementBalances) {
            $settlementData = $this->fetchSettlementBalanceData($tenant);
            if ($settlementData !== null) {
                $exactContractExternalIds = $this->resolveActiveContractExternalIdsForTenantSpaces(
                    marketId: (int) $tenant->market_id,
                    tenantId: (int) $tenant->id,
                );
                $fallbackMode = 'tenant_total';
                $decisionScope = 'tenant_fallback';

                if ($exactContractExternalIds->isNotEmpty()) {
                    /** @var Collection $settlementRows */
                    $settlementRows = $settlementData['rows'];
                    $residualRows = $settlementRows
                        ->filter(static function ($row) use ($exactContractExternalIds): bool {
                            $contractExternalId = trim((string) ($row->contract_external_id ?? ''));

                            return $contractExternalId === ''
                                || ! $exactContractExternalIds->contains($contractExternalId);
                        })
                        ->values();

                    if ($residualRows->isEmpty()) {
                        $result = $this->makeNoResidualTenantFallbackResult(
                            data: $settlementData,
                            labels: $this->getStatusLabels((int) $tenant->market_id),
                            marketId: (int) $tenant->market_id,
                            excludedContractExternalIds: $exactContractExternalIds,
                        );
                        $result['source'] = $source.': OSV residual map decision, no residual debt';

                        return $result;
                    }

                    $settlementData['rows'] = $residualRows;
                    $fallbackMode = 'residual';
                    $decisionScope = 'tenant_fallback_residual';
                }

                $result = $this->makeMapResultFromSettlementBalanceData(
                    $settlementData,
                    $this->getStatusLabels((int) $tenant->market_id),
                    (int) $tenant->market_id,
                    'tenant_fallback',
                    $decisionScope
                );
                $result['source'] = $source.(
                    $fallbackMode === 'residual'
                        ? ': OSV residual map decision'
                        : ': OSV map decision'
                );
                $result['extra'] = array_merge(is_array($result['extra'] ?? null) ? $result['extra'] : [], [
                    'fallback_mode' => $fallbackMode,
                    'exact_space_contracts_excluded' => $exactContractExternalIds->values()->all(),
                    'exact_space_contracts_excluded_count' => $exactContractExternalIds->count(),
                ]);

                return $result;
            }
        }

        $tenantResolved = $this->resolveTenantForMapFallback($tenant);
        $tenantStatus = $tenantResolved['status'] ?? null;

        if (! in_array($tenantStatus, [self::STATUS_GREEN, self::STATUS_PENDING, self::STATUS_ORANGE, self::STATUS_RED], true)) {
            return null;
        }

        $tenantExtra = is_array($tenantResolved['extra'] ?? null) ? $tenantResolved['extra'] : [];
        $tenantExtra['scope'] = 'tenant_fallback';

        return $this->makeResult(
            mode: $tenantResolved['mode'],
            status: $tenantResolved['status'],
            label: $tenantResolved['label'],
            updatedAt: $tenantResolved['updated_at'],
            source: $source,
            severity: $tenantResolved['severity'],
            extra: $tenantExtra
        );
    }

    private function resolveTenantForMapFallback(Tenant $tenant): array
    {
        $manualResult = $this->checkManualStatus($tenant);
        if ($manualResult !== null) {
            return $manualResult;
        }

        return $this->calculateAutoStatus($tenant, useSettlementBalances: false);
    }

    private function makeNoResidualTenantFallbackResult(
        array $data,
        array $labels,
        int $marketId,
        Collection $excludedContractExternalIds,
    ): array {
        /** @var Collection $rows */
        $rows = $data['rows'];

        return $this->makeResult(
            mode: 'auto',
            status: self::STATUS_GREEN,
            label: $labels[self::STATUS_GREEN],
            updatedAt: $data['snapshot_label'] ?? null,
            source: self::SETTLEMENT_DEBT_SOURCE.': residual map decision',
            severity: 0,
            extra: [
                'scope' => 'tenant_fallback',
                'debt_amount' => 0.0,
                'accounts' => $rows->pluck('account')->filter()->unique()->values()->all(),
                'latest_period_to' => $data['latest_period_to'] ?? null,
                'amount_source' => 'tenant_settlement_balances.residual_after_exact_space_contracts',
                'amount_basis' => 'net_balance',
                'aging_policy' => $this->settlementMapAgingPolicy($marketId),
                'confidence' => 'medium',
                'reason' => 'tenant OSV rows are already represented by exact active space contracts',
                'fallback_mode' => 'residual',
                'exact_space_contracts_excluded' => $excludedContractExternalIds->values()->all(),
                'exact_space_contracts_excluded_count' => $excludedContractExternalIds->count(),
            ]
        );
    }

    private function isTenantFallbackDebtStatus(?string $status): bool
    {
        return in_array($status, [self::STATUS_PENDING, self::STATUS_ORANGE, self::STATUS_RED], true);
    }

    private function activeSharedUseParticipantCount(int $marketSpaceId, int $marketId): int
    {
        if (
            ! Schema::hasTable('market_space_tenant_bindings')
            || ! Schema::hasColumn('market_space_tenant_bindings', 'binding_type')
        ) {
            return 0;
        }

        $now = now();

        return (int) DB::table('market_space_tenant_bindings')
            ->where('market_space_id', $marketSpaceId)
            ->where('market_id', $marketId)
            ->where('binding_type', 'shared_use')
            ->where(function ($query) use ($now): void {
                $query->whereNull('started_at')
                    ->orWhere('started_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $now);
            })
            ->count();
    }

    private function resolveActiveContractExternalIdsForMarketSpace(
        int $marketSpaceId,
        int $marketId,
        int $tenantId,
    ): Collection {
        $contractIds = collect();

        if (
            Schema::hasTable('market_space_tenant_bindings')
            && Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')
        ) {
            $now = now();

            $contractIds = $contractIds->merge(DB::table('market_space_tenant_bindings as mstb')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'mstb.tenant_contract_id')
                ->where('mstb.market_space_id', $marketSpaceId)
                ->where('mstb.market_id', $marketId)
                ->where('mstb.tenant_id', $tenantId)
                ->whereNotNull('mstb.tenant_contract_id')
                ->whereNotNull('tc.external_id')
                ->where('tc.market_id', $marketId)
                ->where('tc.tenant_id', $tenantId)
                ->where('tc.is_active', true)
                ->whereNotIn('tc.status', ['terminated', 'archived'])
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', $now);
                })
                ->pluck('tc.external_id'));
        }

        $contractIds = $contractIds->merge(DB::table('tenant_contracts')
            ->where('market_space_id', $marketSpaceId)
            ->where('market_id', $marketId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereNotIn('status', ['terminated', 'archived'])
            ->whereNotNull('external_id')
            ->pluck('external_id'));

        return $contractIds
            ->unique()
            ->values();
    }

    private function resolveActiveContractExternalIdsForTenantSpaces(int $marketId, int $tenantId): Collection
    {
        if (
            ! Schema::hasTable('tenant_contracts')
            || ! Schema::hasTable('market_spaces')
            || ! Schema::hasColumn('tenant_contracts', 'external_id')
            || ! Schema::hasColumn('tenant_contracts', 'market_space_id')
            || ! Schema::hasColumn('market_spaces', 'tenant_id')
        ) {
            return collect();
        }

        $contractIds = collect();
        $now = now();

        if (
            Schema::hasTable('market_space_tenant_bindings')
            && Schema::hasColumn('market_space_tenant_bindings', 'tenant_contract_id')
        ) {
            $contractIds = $contractIds->merge(DB::table('market_space_tenant_bindings as mstb')
                ->join('tenant_contracts as tc', 'tc.id', '=', 'mstb.tenant_contract_id')
                ->join('market_spaces as ms', 'ms.id', '=', 'mstb.market_space_id')
                ->where('mstb.market_id', $marketId)
                ->where('mstb.tenant_id', $tenantId)
                ->whereNotNull('mstb.tenant_contract_id')
                ->whereNotNull('tc.external_id')
                ->where('tc.market_id', $marketId)
                ->where('tc.tenant_id', $tenantId)
                ->where('tc.is_active', true)
                ->whereNotIn('tc.status', ['terminated', 'archived'])
                ->where('ms.market_id', $marketId)
                ->where('ms.tenant_id', $tenantId)
                ->where('ms.is_active', true)
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.started_at')
                        ->orWhere('mstb.started_at', '<=', $now);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('mstb.ended_at')
                        ->orWhere('mstb.ended_at', '>', $now);
                })
                ->pluck('tc.external_id'));
        }

        $contractIds = $contractIds->merge(DB::table('tenant_contracts as tc')
            ->join('market_spaces as ms', 'ms.id', '=', 'tc.market_space_id')
            ->where('tc.market_id', $marketId)
            ->where('tc.tenant_id', $tenantId)
            ->where('tc.is_active', true)
            ->whereNotIn('tc.status', ['terminated', 'archived'])
            ->whereNotNull('tc.external_id')
            ->where('ms.market_id', $marketId)
            ->where('ms.tenant_id', $tenantId)
            ->where('ms.is_active', true)
            ->pluck('tc.external_id'));

        return $contractIds
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Build user-facing status labels from market settings.
     *
     * @return array<string, string>
     */
    private function getStatusLabels(int $marketId): array
    {
        $settings = $this->getMarketSettings($marketId);
        $yellowAfterDays = max(1, (int) ($settings['yellow_after_days'] ?? $settings['orange_after_days'] ?? 1));
        $redAfterDays = max($yellowAfterDays + 1, (int) ($settings['red_after_days'] ?? 30));

        $orangeLabel = $yellowAfterDays <= 1
            ? 'Просрочка до '.($redAfterDays - 1).' дн.'
            : 'Просрочка '.$yellowAfterDays.'-'.($redAfterDays - 1).' дн.';

        return [
            self::STATUS_GREEN => 'Нет задолженности',
            self::STATUS_PENDING => 'К оплате / срок не наступил',
            self::STATUS_ORANGE => $orangeLabel,
            self::STATUS_RED => 'Просрочка от '.$redAfterDays.' дн.',
            self::STATUS_GRAY => 'Нет данных',
        ];
    }

    /**
     * Создать результат расчёта.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int,extra:?array}
     */
    private function makeResult(
        string $mode,
        ?string $status,
        string $label,
        ?string $updatedAt = null,
        ?string $source = null,
        int $severity = 0,
        array $extra = []
    ): array {
        return [
            'mode' => $mode,
            'status' => $status,
            'label' => $label,
            'updated_at' => $updatedAt,
            'source' => $source,
            'severity' => $severity,
            'extra' => $extra !== [] ? $extra : null,
        ];
    }

    /**
     * Получить severity статуса.
     */
    private function getSeverity(string $status): int
    {
        return match ($status) {
            self::STATUS_GREEN => 0,
            self::STATUS_PENDING => 1,
            self::STATUS_ORANGE => 2,
            self::STATUS_RED => 3,
            default => 0,
        };
    }

    /**
     * Получить ключ кеша.
     */
    private function getCacheKey(Tenant $tenant): string
    {
        return $tenant->market_id.':'.$tenant->id.':'.($tenant->updated_at?->timestamp ?? 0);
    }

    /**
     * Очистить кеш.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
