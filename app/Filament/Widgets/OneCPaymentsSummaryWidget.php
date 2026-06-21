<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Market;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OneCPaymentsSummaryWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = [
        'md' => 2,
        'xl' => 1,
    ];

    protected ?string $pollingInterval = null;

    protected ?string $heading = null;

    protected function getStats(): array
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return $this->buildEmptyStats('Нет пользователя');
        }

        $marketId = $this->resolveMarketIdForWidget($user);

        if (! $marketId) {
            return $this->buildEmptyStats('Выберите рынок');
        }

        if (! Schema::hasTable('tenant_payments')) {
            return $this->buildEmptyStats('Нет таблицы tenant_payments');
        }

        $market = Market::query()
            ->select(['id', 'timezone'])
            ->find($marketId);

        $tz = $this->resolveTimezone($market?->timezone);
        $summary = $this->loadPaymentsSummary($marketId);

        if ((int) ($summary['rows_all'] ?? 0) === 0) {
            return $this->buildEmptyStats('Оплаты из 1С ещё не загружались');
        }

        $latestPeriod = $summary['latest_period'];
        $latestPeriodLabel = $latestPeriod !== null
            ? $this->formatMonthLabel($latestPeriod, $tz)
            : '—';

        $latestImportedAt = $summary['latest_imported_at'];
        $latestImportedLabel = $latestImportedAt !== null
            ? $this->formatDateTimeLabel($latestImportedAt, $tz)
            : '—';

        $unlinked = (int) ($summary['unlinked_contracts_latest'] ?? 0);
        $unlinkedColor = $unlinked > 0 ? 'warning' : 'success';
        $unlinkedDescription = $unlinked > 0
            ? 'Нужно проверить привязку к договорам'
            : 'Все платежи периода привязаны к договорам';

        return [
            Stat::make('Последний период оплат', $latestPeriodLabel)
                ->description('Импорт: ' . $latestImportedLabel)
                ->icon('heroicon-o-calendar-days')
                ->color('primary'),

            Stat::make('Оплачено за период', $this->formatMoney((float) ($summary['sum_latest'] ?? 0.0)) . ' ₽')
                ->description('Всего загружено: ' . $this->formatMoney((float) ($summary['sum_all'] ?? 0.0)) . ' ₽')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Платежи / арендаторы', $this->formatInteger((int) ($summary['rows_latest'] ?? 0)) . ' / ' . $this->formatInteger((int) ($summary['tenants_latest'] ?? 0)))
                ->description('За последний загруженный период')
                ->icon('heroicon-o-users')
                ->color('info'),

            Stat::make('Без договора', $this->formatInteger($unlinked))
                ->description($unlinkedDescription)
                ->icon('heroicon-o-link-slash')
                ->color($unlinkedColor),
        ];
    }

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user && (
            (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
            || (bool) ($user->market_id ?? null)
        );
    }

    /**
     * @return array{
     *     latest_period: string|null,
     *     latest_imported_at: string|null,
     *     rows_all: int,
     *     sum_all: float,
     *     rows_latest: int,
     *     sum_latest: float,
     *     tenants_latest: int,
     *     unlinked_contracts_latest: int
     * }
     */
    private function loadPaymentsSummary(int $marketId): array
    {
        $base = DB::table('tenant_payments')
            ->where('market_id', $marketId);

        $rowsAll = (int) ((clone $base)->count());
        $sumAll = (float) ((clone $base)->sum('amount'));
        $latestPeriod = (clone $base)->max('period');
        $latestImportedAt = (clone $base)->max('imported_at');

        if ($latestPeriod === null) {
            return [
                'latest_period' => null,
                'latest_imported_at' => $latestImportedAt,
                'rows_all' => $rowsAll,
                'sum_all' => $sumAll,
                'rows_latest' => 0,
                'sum_latest' => 0.0,
                'tenants_latest' => 0,
                'unlinked_contracts_latest' => 0,
            ];
        }

        $latestBase = (clone $base)->where('period', $latestPeriod);

        return [
            'latest_period' => (string) $latestPeriod,
            'latest_imported_at' => $latestImportedAt !== null ? (string) $latestImportedAt : null,
            'rows_all' => $rowsAll,
            'sum_all' => $sumAll,
            'rows_latest' => (int) ((clone $latestBase)->count()),
            'sum_latest' => (float) ((clone $latestBase)->sum('amount')),
            'tenants_latest' => (int) ((clone $latestBase)->distinct()->count('tenant_id')),
            'unlinked_contracts_latest' => (int) ((clone $latestBase)->whereNull('tenant_contract_id')->count()),
        ];
    }

    private function resolveMarketIdForWidget($user): ?int
    {
        $isSuperAdmin = method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin();

        if (! $isSuperAdmin) {
            return $user->market_id ? (int) $user->market_id : null;
        }

        $panelId = Filament::getCurrentPanel()?->getId() ?? 'admin';

        $value =
            session('dashboard_market_id')
            ?? session("filament.{$panelId}.selected_market_id")
            ?? session("filament_{$panelId}_market_id")
            ?? session('filament.admin.selected_market_id');

        if (filled($value)) {
            return (int) $value;
        }

        $marketId = Market::query()
            ->orderBy('id')
            ->value('id');

        return $marketId ? (int) $marketId : null;
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

    private function formatMonthLabel(string $period, string $tz): string
    {
        try {
            return CarbonImmutable::parse($period, $tz)
                ->locale('ru')
                ->translatedFormat('F Y');
        } catch (\Throwable) {
            return $period;
        }
    }

    private function formatDateTimeLabel(string $value, string $tz): string
    {
        try {
            return CarbonImmutable::parse($value)->setTimezone($tz)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, abs($value - round($value)) < 0.01 ? 0 : 2, ',', ' ');
    }

    private function formatInteger(int $value): string
    {
        return number_format($value, 0, ',', ' ');
    }

    /**
     * @return array<int, Stat>
     */
    private function buildEmptyStats(string $description): array
    {
        return [
            Stat::make('Оплаты из 1С', '—')
                ->description($description)
                ->icon('heroicon-o-banknotes')
                ->color('gray'),
        ];
    }
}
