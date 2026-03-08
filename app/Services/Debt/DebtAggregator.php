<?php

declare(strict_types=1);

namespace App\Services\Debt;

use App\Models\Tenant;

/**
 * Сервис агрегации статусов задолженности по торговым местам арендатора.
 *
 * Режимы агрегации:
 * - worst: берёт худший статус среди всех мест
 * - dominant: берёт статус, который встречается у большего числа мест
 */
class DebtAggregator
{
    /**
     * Порядок серьёзности статусов.
     */
    private const STATUS_SEVERITY = [
        'gray' => 0,
        'green' => 1,
        'pending' => 2,
        'orange' => 3,
        'red' => 4,
    ];

    /**
     * Метки статусов.
     */
    private const STATUS_LABELS = [
        'gray' => 'Нет данных',
        'green' => 'Нет задолженности',
        'pending' => 'К оплате / срок не наступил',
        'orange' => 'Задолженность до 3 месяцев',
        'red' => 'Задолженность свыше 3 месяцев',
    ];

    /**
     * Агрегировать статусы по местам арендатора.
     *
     * @param Tenant $tenant
     * @param string $mode 'worst' | 'dominant'
     * @return array{
     *     aggregate_status: ?string,
     *     aggregate_label: string,
     *     aggregate_severity: int,
     *     mode: string,
     *     spaces: list<array>,
     *     summary: array{
     *         total: int,
     *         green: int,
     *         pending: int,
     *         orange: int,
     *         red: int,
     *         gray: int
     *     }
     * }
     */
    public function aggregate(Tenant $tenant, string $mode = 'worst'): array
    {
        // Получаем режим из настроек рынка, если не передан
        if (!in_array($mode, ['worst', 'dominant'], true)) {
            $mode = $this->getTenantAggregateMode($tenant);
        }

        // Получаем статусы по местам
        $spaces = $this->fetchSpaceStatuses($tenant);

        // Агрегируем по выбранному режиму
        $aggregateStatus = $this->aggregateByMode($spaces, $mode);

        // Считаем summary
        $summary = $this->buildSummary($spaces);

        return [
            'aggregate_status' => $aggregateStatus['status'],
            'aggregate_label' => $aggregateStatus['label'],
            'aggregate_severity' => $aggregateStatus['severity'],
            'mode' => $mode,
            'spaces' => $spaces,
            'summary' => $summary,
        ];
    }

    /**
     * Агрегировать статусы по режиму worst.
     *
     * @param list<array> $spaces
     * @return array{status: ?string, label: string, severity: int}
     */
    public function aggregateWorst(array $spaces): array
    {
        if (empty($spaces)) {
            return [
                'status' => 'gray',
                'label' => self::STATUS_LABELS['gray'],
                'severity' => 0,
            ];
        }

        $maxSeverity = -1;
        $worstStatus = 'gray';

        foreach ($spaces as $space) {
            $status = $space['status'] ?? 'gray';
            $severity = self::STATUS_SEVERITY[$status] ?? 0;

            if ($severity > $maxSeverity) {
                $maxSeverity = $severity;
                $worstStatus = $status;
            }
        }

        return [
            'status' => $worstStatus,
            'label' => self::STATUS_LABELS[$worstStatus] ?? self::STATUS_LABELS['gray'],
            'severity' => $maxSeverity >= 0 ? $maxSeverity : 0,
        ];
    }

    /**
     * Агрегировать статусы по режиму dominant.
     *
     * @param list<array> $spaces
     * @return array{status: ?string, label: string, severity: int}
     */
    public function aggregateDominant(array $spaces): array
    {
        if (empty($spaces)) {
            return [
                'status' => 'gray',
                'label' => self::STATUS_LABELS['gray'],
                'severity' => 0,
            ];
        }

        // Считаем количество каждого статуса
        $counts = [
            'gray' => 0,
            'green' => 0,
            'pending' => 0,
            'orange' => 0,
            'red' => 0,
        ];

        foreach ($spaces as $space) {
            $status = $space['status'] ?? 'gray';
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        // Находим максимальное количество
        $maxCount = max($counts);

        // Собираем статусы с максимальным количеством
        $topStatuses = [];
        foreach ($counts as $status => $count) {
            if ($count === $maxCount && $count > 0) {
                $topStatuses[] = $status;
            }
        }

        // Если ничья — побеждает более серьёзный статус
        $dominantStatus = 'gray';
        $maxSeverity = -1;

        foreach ($topStatuses as $status) {
            $severity = self::STATUS_SEVERITY[$status] ?? 0;
            if ($severity > $maxSeverity) {
                $maxSeverity = $severity;
                $dominantStatus = $status;
            }
        }

        return [
            'status' => $dominantStatus,
            'label' => self::STATUS_LABELS[$dominantStatus] ?? self::STATUS_LABELS['gray'],
            'severity' => $maxSeverity >= 0 ? $maxSeverity : 0,
        ];
    }

    /**
     * Получить статусы по местам арендатора.
     *
     * @param Tenant $tenant
     * @return list<array{
     *     market_space_id: int,
     *     status: ?string,
     *     label: string,
     *     severity: int,
     *     debt_amount: ?float
     * }>
     */
    private function fetchSpaceStatuses(Tenant $tenant): array
    {
        $resolver = app(DebtStatusResolver::class);
        $spaces = [];

        // Получаем все места арендатора
        $tenantSpaces = $tenant->spaces()
            ->where('market_id', $tenant->market_id)
            ->get(['id', 'tenant_id']);

        foreach ($tenantSpaces as $space) {
            $spaceStatus = $resolver->resolveForMarketSpace($space->id, $tenant->market_id);

            $spaces[] = [
                'market_space_id' => $space->id,
                'status' => $spaceStatus['status'],
                'label' => $spaceStatus['label'],
                'severity' => $spaceStatus['severity'],
                'debt_amount' => null, // можно расширить в будущем
            ];
        }

        return $spaces;
    }

    /**
     * Агрегировать по выбранному режиму.
     *
     * @param list<array> $spaces
     * @param string $mode
     * @return array{status: ?string, label: string, severity: int}
     */
    private function aggregateByMode(array $spaces, string $mode): array
    {
        return match ($mode) {
            'dominant' => $this->aggregateDominant($spaces),
            default => $this->aggregateWorst($spaces),
        };
    }

    /**
     * Построить summary по местам.
     *
     * @param list<array> $spaces
     * @return array{
     *     total: int,
     *     green: int,
     *     pending: int,
     *     orange: int,
     *     red: int,
     *     gray: int
     * }
     */
    private function buildSummary(array $spaces): array
    {
        $summary = [
            'total' => count($spaces),
            'green' => 0,
            'pending' => 0,
            'orange' => 0,
            'red' => 0,
            'gray' => 0,
        ];

        foreach ($spaces as $space) {
            $status = $space['status'] ?? 'gray';
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * Получить режим агрегации из настроек рынка.
     *
     * @param Tenant $tenant
     * @return string
     */
    private function getTenantAggregateMode(Tenant $tenant): string
    {
        $market = $tenant->market;
        if (!$market || !isset($market->settings['debt_monitoring'])) {
            return 'worst';
        }

        return $market->settings['debt_monitoring']['tenant_aggregate_mode'] ?? 'worst';
    }
}
