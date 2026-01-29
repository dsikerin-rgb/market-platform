<?php
# app/Filament/Widgets/TenantActivityStatsWidget.php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use App\Models\TenantContract;
use App\Models\TenantRequest;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

class TenantActivityStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $heading = 'Оперативная активность';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return true;
        }

        return (bool) $user->market_id;
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->zeroStats('Нет пользователя');
        }

        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        $marketId = $isSuperAdmin
            ? (int) (session('dashboard_market_id') ?: 0)
            : (int) ($user->market_id ?: 0);

        if ($marketId <= 0) {
            return $this->zeroStats($isSuperAdmin ? 'Рынок не выбран' : 'Нет привязки к рынку');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);

        [$monthStartTz, $monthEndTz, $periodLabel] = $this->resolveMonthRange($tz);

        // Границы месяца считаем в TZ рынка, но в БД обычно лежит UTC → сравниваем по UTC.
        $monthStartUtc = $monthStartTz->utc();
        $monthEndUtc   = $monthEndTz->utc();

        $requestsQuery = TenantRequest::query()->where('market_id', $marketId);
        $contractsQuery = TenantContract::query()->where('market_id', $marketId);

        // TenantRequest: колонки и статусы
        $requestsTable = (new TenantRequest())->getTable();
        $requestsStatusCol = $this->pickFirstExistingColumn($requestsTable, ['status']) ?? 'status';
        $requestsCreatedCol = $this->pickFirstExistingColumn($requestsTable, ['created_at']) ?? 'created_at';
        $requestsResolvedCol = $this->pickFirstExistingColumn($requestsTable, [
            'resolved_at',
            'closed_at',
            'completed_at',
            'updated_at', // fallback
        ]) ?? 'updated_at';

        // TenantContract: колонки
        $contractsTable = (new TenantContract())->getTable();
        $contractsCreatedCol = $this->pickFirstExistingColumn($contractsTable, ['created_at']) ?? 'created_at';
        $contractsEndCol = $this->pickFirstExistingColumn($contractsTable, [
            'ends_at',
            'end_date',
            'ended_at',
            'expires_at',
        ]);

        $closedStatuses = ['resolved', 'closed'];

        // 1) Новые обращения за месяц (созданы в месяце)
        $requestsCreatedInMonth = (clone $requestsQuery)
            ->where($requestsCreatedCol, '>=', $monthStartUtc)
            ->where($requestsCreatedCol, '<', $monthEndUtc)
            ->count();

        // 2) Открытые обращения (на конец месяца): созданы ДО конца месяца и не закрыты
        $openRequestsAtMonthEnd = (clone $requestsQuery)
            ->where($requestsCreatedCol, '<', $monthEndUtc)
            ->whereNotIn($requestsStatusCol, $closedStatuses)
            ->count();

        // 3) Решённые/закрытые в месяце (по resolved_at/closed_at если есть)
        $resolvedInMonth = (clone $requestsQuery)
            ->whereIn($requestsStatusCol, $closedStatuses)
            ->where($requestsResolvedCol, '>=', $monthStartUtc)
            ->where($requestsResolvedCol, '<', $monthEndUtc)
            ->count();

        // 4) Новые договоры за месяц (созданы в месяце)
        $contractsCreatedInMonth = (clone $contractsQuery)
            ->where($contractsCreatedCol, '>=', $monthStartUtc)
            ->where($contractsCreatedCol, '<', $monthEndUtc)
            ->count();

        // 5) Завершённые договоры в месяце (учитываем, что end_date может быть DATE без времени)
        $contractsFinishedInMonth = 0;

        if ($contractsEndCol) {
            if ($this->isDateOnlyColumnName($contractsEndCol)) {
                $startDate = $monthStartTz->toDateString();
                $endDate = $monthEndTz->toDateString();

                $contractsFinishedInMonth = (clone $contractsQuery)
                    ->where($contractsEndCol, '>=', $startDate)
                    ->where($contractsEndCol, '<', $endDate)
                    ->count();
            } else {
                $contractsFinishedInMonth = (clone $contractsQuery)
                    ->where($contractsEndCol, '>=', $monthStartUtc)
                    ->where($contractsEndCol, '<', $monthEndUtc)
                    ->count();
            }
        }

        return [
            Stat::make('Новых обращений', $requestsCreatedInMonth)->description($periodLabel),
            Stat::make('Открытых обращений', $openRequestsAtMonthEnd)->description($periodLabel),
            Stat::make('Решённых обращений', $resolvedInMonth)->description($periodLabel),
            Stat::make('Новых договоров', $contractsCreatedInMonth)->description($periodLabel),
            Stat::make('Завершённых договоров', $contractsFinishedInMonth)->description($periodLabel),
        ];
    }

    private function resolveTimezone(?string $marketTimezone): string
    {
        $tz = trim((string) $marketTimezone);

        if ($tz === '') {
            $tz = (string) config('app.timezone', 'UTC');
        }

        try {
            CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $tz = (string) config('app.timezone', 'UTC');
        }

        return $tz;
    }

    /**
     * Возвращает:
     * - startOfMonth (TZ рынка)
     * - startOfNextMonth (TZ рынка)
     * - label "MM.YYYY (TZ: ...)"
     */
    private function resolveMonthRange(string $tz): array
    {
        $raw = null;

        if (is_array($this->filters ?? null)) {
            $raw = $this->filters['month'] ?? $this->filters['period'] ?? $this->filters['dashboard_month'] ?? null;
        }

        $raw = $raw ?: session('dashboard_month') ?: session('dashboard_period');

        $monthYm = is_string($raw) && preg_match('/^\d{4}-\d{2}$/', $raw)
            ? $raw
            : CarbonImmutable::now($tz)->format('Y-m');

        $start = CarbonImmutable::createFromFormat('Y-m', $monthYm, $tz)->startOfMonth();
        $end   = $start->addMonth();

        $label = $start->format('m.Y') . ' (TZ: ' . $tz . ')';

        return [$start, $end, $label];
    }

    private function pickFirstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isDateOnlyColumnName(string $column): bool
    {
        $c = strtolower($column);

        if (str_ends_with($c, '_at')) {
            return false;
        }

        // end_date / start_date / ...date
        return str_contains($c, 'date');
    }

    /**
     * @return array<int, Stat>
     */
    private function zeroStats(?string $note = null): array
    {
        $stats = [
            Stat::make('Новых обращений', 0),
            Stat::make('Открытых обращений', 0),
            Stat::make('Решённых обращений', 0),
            Stat::make('Новых договоров', 0),
            Stat::make('Завершённых договоров', 0),
        ];

        if ($note) {
            $stats[0] = $stats[0]->description($note);
        }

        return $stats;
    }
}
