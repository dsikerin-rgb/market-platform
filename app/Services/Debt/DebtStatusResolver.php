<?php

namespace App\Services\Debt;

use App\Models\Tenant;
use App\Models\Market;
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

    /**
     * Метки статусов.
     */
    private const STATUS_LABELS = [
        self::STATUS_GREEN => 'Нет задолженности',
        self::STATUS_PENDING => 'К оплате / срок не наступил',
        self::STATUS_ORANGE => 'Задолженность до 3 месяцев',
        self::STATUS_RED => 'Задолженность свыше 3 месяцев',
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

    /**
     * Рассчитать статус для конкретного торгового места.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int}
     */
    public function resolveForMarketSpace(int $marketSpaceId, int $marketId): array
    {
        // Получаем место и арендатора
        $space = DB::table('market_spaces')
            ->where('id', $marketSpaceId)
            ->where('market_id', $marketId)
            ->first(['tenant_id']);

        if (!$space || !$space->tenant_id) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет арендатора',
                severity: 0
            );
        }

        $tenant = Tenant::find($space->tenant_id);
        if (!$tenant) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Арендатор не найден',
                severity: 0
            );
        }

        // Получаем external_id контрактов, привязанных к этому месту
        $contractExternalIds = DB::table('tenant_contracts')
            ->where('market_space_id', $marketSpaceId)
            ->where('market_id', $marketId)
            ->whereNotNull('external_id')
            ->pluck('external_id');

        if ($contractExternalIds->isEmpty()) {
            // Нет контрактов с external_id для этого места
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет договора 1С для места',
                severity: 0
            );
        }

        // Проверяем наличие таблицы contract_debts
        if (!Schema::hasTable('contract_debts')) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Таблица contract_debts отсутствует',
                severity: 0
            );
        }

        // Получаем долги по contract_external_id
        $hasDebt = Schema::hasColumn('contract_debts', 'debt_amount');
        $hasCalculatedAt = Schema::hasColumn('contract_debts', 'calculated_at');
        $hasCreatedAt = Schema::hasColumn('contract_debts', 'created_at');
        $hasPeriod = Schema::hasColumn('contract_debts', 'period');

        if (!$hasDebt) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет поля debt_amount',
                severity: 0
            );
        }

        // Запрашиваем долги по contract_external_id
        $query = DB::table('contract_debts')
            ->whereIn('contract_external_id', $contractExternalIds->all())
            ->where('market_id', $marketId);

        // Определяем последний snapshot
        $snapshotLabel = null;
        if ($hasCalculatedAt) {
            $latest = $query->clone()->max('calculated_at');
            if ($latest) {
                $query->where('calculated_at', $latest);
                try {
                    $snapshotLabel = Carbon::parse($latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        } elseif ($hasCreatedAt) {
            $latest = $query->clone()->max('created_at');
            if ($latest) {
                $query->where('created_at', $latest);
                try {
                    $snapshotLabel = Carbon::parse($latest)->format('d.m.Y H:i');
                } catch (\Throwable) {
                    $snapshotLabel = (string) $latest;
                }
            }
        } elseif ($hasPeriod) {
            $latest = $query->clone()->max('period');
            if ($latest) {
                $query->where('period', $latest);
                $snapshotLabel = (string) $latest;
            }
        }

        $rows = $query->get(['debt_amount', 'period']);

        if ($rows->isEmpty()) {
            // Нет записей 1С для контрактов этого места — это gray
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Нет данных 1С по договору',
                updatedAt: $snapshotLabel,
                source: 'contract_debts: пусто',
                severity: 0
            );
        }

        // Считаем общий долг по месту
        $totalDebt = $rows->sum('debt_amount');

        if ($totalDebt <= 0.009) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: 'Нет задолженности',
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 0
            );
        }

        // Есть долг - определяем статус по просрочке
        $settings = $this->getMarketSettings($marketId);
        $graceDays = $settings['grace_days'] ?? 5;
        $yellowAfterDays = $settings['yellow_after_days'] ?? $settings['orange_after_days'] ?? 1;
        $redAfterDays = $settings['red_after_days'] ?? 30;

        $dueDate = $this->calculateDueDateFromRows($rows, $graceDays, $hasPeriod, $hasCalculatedAt, $hasCreatedAt);

        if ($dueDate === null) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: 'Не удалось определить срок оплаты',
                updatedAt: $snapshotLabel,
                source: 'contract_debts: нет дат',
                severity: 0
            );
        }

        $now = Carbon::now();
        $isOverdue = $now->gt($dueDate);

        if (!$isOverdue) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: 'К оплате / срок не наступил',
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 1
            );
        }

        $daysOverdue = $dueDate->diffInDays($now);

        if ($daysOverdue >= $redAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_RED,
                label: 'Задолженность свыше 3 месяцев',
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 3,
                extra: ['overdue_days' => $daysOverdue]
            );
        }

        if ($daysOverdue >= $yellowAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_ORANGE,
                label: 'Задолженность до 3 месяцев',
                updatedAt: $snapshotLabel,
                source: 'contract_debts',
                severity: 2,
                extra: ['overdue_days' => $daysOverdue]
            );
        }

        // Просрочка есть, но меньше yellow_after_days — pending
        return $this->makeResult(
            mode: 'auto',
            status: self::STATUS_PENDING,
            label: 'К оплате / срок не наступил',
            updatedAt: $snapshotLabel,
            source: 'contract_debts',
            severity: 1,
            extra: ['overdue_days' => max(0, $daysOverdue)]
        );
    }

    /**
     * Рассчитать дату оплаты (due date) из rows.
     *
     * @param \Illuminate\Support\Collection $rows
     * @param int $graceDays
     * @param bool $hasPeriod
     * @param bool $hasCalculatedAt
     * @param bool $hasCreatedAt
     * @return Carbon|null
     */
    private function calculateDueDateFromRows(
        Collection $rows,
        int $graceDays,
        bool $hasPeriod,
        bool $hasCalculatedAt,
        bool $hasCreatedAt
    ): ?Carbon {
        // 1. Если есть calculated_at в rows - используем его + grace_days
        if ($hasCalculatedAt) {
            $latestCalculatedAt = $rows->max('calculated_at');
            if ($latestCalculatedAt) {
                try {
                    return Carbon::parse($latestCalculatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 2. Если есть created_at в rows - используем его + grace_days
        if ($hasCreatedAt) {
            $latestCreatedAt = $rows->max('created_at');
            if ($latestCreatedAt) {
                try {
                    return Carbon::parse($latestCreatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 3. Если есть period - используем его + grace_days
        if ($hasPeriod) {
            $latestPeriod = $rows->max('period');
            if ($latestPeriod) {
                try {
                    // period в формате YYYY-MM
                    if (preg_match('/^\d{4}-\d{2}/', $latestPeriod) === 1) {
                        return Carbon::createFromFormat('Y-m-d', substr($latestPeriod, 0, 7) . '-01')
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
     * Получить агрегированный статус для всех мест арендатора.
     *
     * @return array{mode:string,status:?string,label:string,updated_at:?string,source:?string,severity:int,spaces_count:int,debt_spaces_count:int}
     */
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
     *
     * @return array|null
     */
    private function checkManualStatus(Tenant $tenant): ?array
    {
        $manualStatus = trim($tenant->debt_status ?? '');

        if ($manualStatus !== '' && array_key_exists($manualStatus, self::STATUS_LABELS)) {
            return $this->makeResult(
                mode: 'manual',
                status: $manualStatus,
                label: self::STATUS_LABELS[$manualStatus],
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
    private function calculateAutoStatus(Tenant $tenant): array
    {
        $settings = $this->getMarketSettings($tenant->market_id);
        $graceDays = $settings['grace_days'] ?? 5;
        $redAfterDays = $settings['red_after_days'] ?? 90;

        // Получаем данные из contract_debts
        $debtsData = $this->fetchDebtsData($tenant);

        if ($debtsData === null) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: self::STATUS_LABELS[self::STATUS_GRAY],
                source: 'Данные 1С недоступны',
                severity: 0
            );
        }

        if ($debtsData['rows']->isEmpty()) {
            // Нет записей по арендатору — это gray (данные есть, но записей нет)
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: self::STATUS_LABELS[self::STATUS_GRAY],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Нет данных 1С по арендатору',
                severity: 0
            );
        }

        // Считаем общую задолженность
        $totalDebt = $debtsData['rows']->sum('debt_amount');

        if ($totalDebt <= 0.009) {
            // Записи есть, долг нулевой — это green
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GREEN,
                label: self::STATUS_LABELS[self::STATUS_GREEN],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 0
            );
        }

        // Есть долг - определяем статус по сроку
        $dueDate = $this->calculateDueDate($debtsData, $graceDays);

        if ($dueDate === null) {
            // Не удалось определить срок - возвращаем gray
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_GRAY,
                label: self::STATUS_LABELS[self::STATUS_GRAY],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Не удалось определить срок оплаты',
                severity: 0
            );
        }

        $now = Carbon::now();
        $isOverdue = $now->gt($dueDate);

        if (!$isOverdue) {
            // Срок ещё не наступил
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_PENDING,
                label: self::STATUS_LABELS[self::STATUS_PENDING],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 1
            );
        }

        // Просрочка - считаем дни
        $daysOverdue = $dueDate->diffInDays($now);

        if ($daysOverdue >= $redAfterDays) {
            return $this->makeResult(
                mode: 'auto',
                status: self::STATUS_RED,
                label: self::STATUS_LABELS[self::STATUS_RED],
                updatedAt: $debtsData['snapshot_label'],
                source: 'Источник: contract_debts',
                severity: 3
            );
        }

        return $this->makeResult(
            mode: 'auto',
            status: self::STATUS_ORANGE,
            label: self::STATUS_LABELS[self::STATUS_ORANGE],
            updatedAt: $debtsData['snapshot_label'],
            source: 'Источник: contract_debts',
            severity: 2
        );
    }

    /**
     * Получить данные о задолженностях из БД.
     *
     * @return array{rows:\Illuminate\Support\Collection,snapshot_label:?string}|null
     */
    private function fetchDebtsData(Tenant $tenant): ?array
    {
        if (!Schema::hasTable('contract_debts')) {
            return null;
        }

        $hasTenantExternalId = Schema::hasColumn('contract_debts', 'tenant_external_id');
        $hasMarketId = Schema::hasColumn('contract_debts', 'market_id');
        $hasCalculatedAt = Schema::hasColumn('contract_debts', 'calculated_at');
        $hasCreatedAt = Schema::hasColumn('contract_debts', 'created_at');
        $hasPeriod = Schema::hasColumn('contract_debts', 'period');
        $hasDueDate = Schema::hasColumn('contract_debts', 'due_date');
        $hasDebt = Schema::hasColumn('contract_debts', 'debt_amount');

        if (!$hasDebt) {
            return null;
        }

        $query = DB::table('contract_debts');

        // Поиск по external_id или one_c_uid
        if ($hasTenantExternalId) {
            $externalId = trim($tenant->external_id ?? '');
            $oneCUid = trim($tenant->one_c_uid ?? '');

            if ($externalId !== '' || $oneCUid !== '') {
                $query->where(function ($q) use ($externalId, $oneCUid) {
                    if ($externalId !== '') {
                        $q->where('tenant_external_id', $externalId);
                    }
                    if ($oneCUid !== '') {
                        $q->orWhere('tenant_external_id', $oneCUid);
                    }
                });
            } else {
                $query->where('tenant_id', $tenant->id);
            }
        } else {
            $query->where('tenant_id', $tenant->id);
        }

        if ($hasMarketId) {
            $query->where('market_id', $tenant->market_id);
        }

        // Получаем поля
        $fields = ['debt_amount'];
        if ($hasDueDate) $fields[] = 'due_date';
        if ($hasCalculatedAt) $fields[] = 'calculated_at';
        if ($hasCreatedAt) $fields[] = 'created_at';
        if ($hasPeriod) $fields[] = 'period';

        $rows = $query->get($fields);

        // Определяем snapshot label
        $snapshotLabel = null;
        if ($hasCalculatedAt && !$rows->isEmpty()) {
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
            'snapshot_label' => $snapshotLabel,
            'has_due_date' => $hasDueDate,
            'has_calculated_at' => $hasCalculatedAt,
            'has_created_at' => $hasCreatedAt,
            'has_period' => $hasPeriod,
        ];
    }

    /**
     * Рассчитать дату оплаты (due date).
     *
     * @param array $data
     * @param int $graceDays
     * @return Carbon|null
     */
    private function calculateDueDate(array $data, int $graceDays): ?Carbon
    {
        $rows = $data['rows'];

        // 1. Если есть due_date - используем его
        if ($data['has_due_date']) {
            $latestDueDate = null;
            foreach ($rows as $row) {
                if (!empty($row->due_date)) {
                    try {
                        $due = Carbon::parse($row->due_date);
                        if ($latestDueDate === null || $due->gt($latestDueDate)) {
                            $latestDueDate = $due;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
            }
            if ($latestDueDate !== null) {
                return $latestDueDate;
            }
        }

        // 2. Если есть calculated_at - используем его + grace_days
        if ($data['has_calculated_at']) {
            $latestCalculatedAt = $rows->max('calculated_at');
            if ($latestCalculatedAt) {
                try {
                    return Carbon::parse($latestCalculatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 3. Если есть created_at - используем его + grace_days
        if ($data['has_created_at']) {
            $latestCreatedAt = $rows->max('created_at');
            if ($latestCreatedAt) {
                try {
                    return Carbon::parse($latestCreatedAt)->addDays($graceDays);
                } catch (\Throwable) {
                    // continue
                }
            }
        }

        // 4. Если есть period - используем его + grace_days
        if ($data['has_period']) {
            $latestPeriod = $rows->max('period');
            if ($latestPeriod) {
                try {
                    // period в формате YYYY-MM
                    if (preg_match('/^\d{4}-\d{2}/', $latestPeriod) === 1) {
                        return Carbon::createFromFormat('Y-m-d', substr($latestPeriod, 0, 7) . '-01')
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
     *
     * @param int $marketId
     * @return array
     */
    private function getMarketSettings(int $marketId): array
    {
        $market = Market::find($marketId);
        if (!$market) {
            return [
                'grace_days' => 5,
                'yellow_after_days' => 1,
                'red_after_days' => 30,
            ];
        }

        $settings = $market->settings ?? [];
        $debtMonitoring = $settings['debt_monitoring'] ?? [];

        return [
            'grace_days' => $debtMonitoring['grace_days'] ?? 5,
            'yellow_after_days' => $debtMonitoring['yellow_after_days'] ?? $debtMonitoring['orange_after_days'] ?? 1,
            'red_after_days' => $debtMonitoring['red_after_days'] ?? 30,
        ];
    }

    /**
     * Создать результат расчёта.
     *
     * @param string $mode
     * @param string|null $status
     * @param string $label
     * @param string|null $updatedAt
     * @param string|null $source
     * @param int $severity
     * @param array $extra
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
     *
     * @param string $status
     * @return int
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
     *
     * @param Tenant $tenant
     * @return string
     */
    private function getCacheKey(Tenant $tenant): string
    {
        return $tenant->market_id . ':' . $tenant->id . ':' . ($tenant->updated_at?->timestamp ?? 0);
    }

    /**
     * Очистить кеш.
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
