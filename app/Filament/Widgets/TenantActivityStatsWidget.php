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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantActivityStatsWidget extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    protected ?string $pollingInterval = null;

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

        $requestsQuery = TenantRequest::query()->where('market_id', $marketId);
        $requestsTable = (new TenantRequest())->getTable();
        $requestsStatusCol = $this->pickFirstExistingColumn($requestsTable, ['status']) ?? 'status';
        $closedStatuses = ['resolved', 'closed'];

        $openRequests = (clone $requestsQuery)
            ->whereNotIn($requestsStatusCol, $closedStatuses)
            ->count();

        [$financialContourContracts, $financialContourWithoutSpace] = $this->resolveFinancialContourStats($marketId);

        $stats = [
            Stat::make('Открытых обращений', $openRequests)
                ->description('Текущее количество незакрытых обращений'),
            Stat::make('Договоров в финансовом контуре', $financialContourContracts)
                ->description('Есть в долгах 1С или связаны с начислениями'),
        ];

        if ($isSuperAdmin) {
            $stats[] = Stat::make('Без привязки к месту', $financialContourWithoutSpace)
                ->description('Требуют разбора в договорах');
        }

        return $stats;
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
     * @return array{0: int, 1: int}
     */
    private function resolveFinancialContourStats(int $marketId): array
    {
        $contractIds = [];

        if (Schema::hasTable('contract_debts')) {
            try {
                $debtContractIds = DB::table('tenant_contracts as tc')
                    ->join('contract_debts as d', function ($join): void {
                        $join->on('d.market_id', '=', 'tc.market_id')
                            ->on('d.contract_external_id', '=', 'tc.external_id');
                    })
                    ->where('tc.market_id', $marketId)
                    ->distinct()
                    ->pluck('tc.id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($debtContractIds as $contractId) {
                    $contractIds[$contractId] = true;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (Schema::hasTable('tenant_accruals') && Schema::hasColumn('tenant_accruals', 'tenant_contract_id')) {
            try {
                $accrualContractIds = DB::table('tenant_accruals')
                    ->where('market_id', $marketId)
                    ->whereNotNull('tenant_contract_id')
                    ->distinct()
                    ->pluck('tenant_contract_id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                foreach ($accrualContractIds as $contractId) {
                    $contractIds[$contractId] = true;
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($contractIds === []) {
            return [0, 0];
        }

        $baseQuery = TenantContract::query()
            ->where('market_id', $marketId)
            ->whereIn('id', array_keys($contractIds));

        $total = (clone $baseQuery)->count();
        $withoutSpace = (clone $baseQuery)->whereNull('market_space_id')->count();

        return [$total, $withoutSpace];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
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
        $end = $start->addMonth();

        return [$start, $end];
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

    /**
     * @return array<int, Stat>
     */
    private function zeroStats(?string $note = null): array
    {
        $stats = [
            Stat::make('Открытых обращений', 0),
            Stat::make('Договоров в финансовом контуре', 0),
        ];

        $user = Filament::auth()->user();
        $isSuperAdmin = $user && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if ($isSuperAdmin) {
            $stats[] = Stat::make('Без привязки к месту', 0);
        }

        if ($note) {
            $stats[0] = $stats[0]->description($note);
        }

        return $stats;
    }
}
