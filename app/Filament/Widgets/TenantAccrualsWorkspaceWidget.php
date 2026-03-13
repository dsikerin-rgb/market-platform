<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\OneCFinance;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Market;
use App\Models\TenantAccrual;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantAccrualsWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tenant-accruals-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return TenantAccrualResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $baseQuery = TenantAccrualResource::getEloquentQuery();
        $oneCQuery = (clone $baseQuery)->where('source', '1c');

        $total = (clone $baseQuery)->count();
        $oneC = (clone $oneCQuery)->count();
        $history = (clone $baseQuery)->where('source', '!=', '1c')->count();
        $exact = (clone $oneCQuery)->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_EXACT)->count();
        $resolved = (clone $oneCQuery)->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_RESOLVED)->count();
        $linked = $exact + $resolved;
        $ambiguous = (clone $oneCQuery)->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS)->count();
        $unmatched = (clone $oneCQuery)->where('contract_link_status', TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED)->count();
        $unchecked = (clone $oneCQuery)->whereNull('contract_link_status')->count();
        $operationalSnapshot = $this->resolveOperationalSnapshot($marketId);
        $detailState = $this->resolveDetailState($oneCQuery, $baseQuery, $operationalSnapshot['period']);
        $primaryDetailTab = $this->resolvePrimaryDetailTab($oneC, $history);

        return [
            'marketName' => $market?->name,
            'total' => $total,
            'oneC' => $oneC,
            'history' => $history,
            'exact' => $exact,
            'resolved' => $resolved,
            'linked' => $linked,
            'ambiguous' => $ambiguous,
            'unmatched' => $unmatched,
            'unchecked' => $unchecked,
            'hasOneCDetails' => $oneC > 0,
            'hasHistoryDetails' => $history > 0,
            'latestPeriodLabel' => $detailState['latestPeriodLabel'],
            'detailPeriodLabel' => $detailState['latestPeriodLabel'],
            'detailSourceLabel' => $detailState['sourceLabel'],
            'detailNeedsRefresh' => $detailState['needsRefresh'],
            'detailStatusLabel' => $detailState['statusLabel'],
            'operationalPeriodLabel' => $operationalSnapshot['periodLabel'],
            'operationalSnapshotAtLabel' => $operationalSnapshot['snapshotAtLabel'],
            'operationalRows' => $operationalSnapshot['rows'],
            'operationalAccrued' => $operationalSnapshot['accrued'],
            'operationalPaid' => $operationalSnapshot['paid'],
            'operationalDebt' => $operationalSnapshot['debt'],
            'operationalReady' => $operationalSnapshot['ready'],
            'issues' => $this->topIssueNotes($oneCQuery),
            'oneCUrl' => OneCFinance::getUrl(),
            'linkedUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => $linked > 0 ? 'linked' : $primaryDetailTab]),
            'withoutContractUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => ($unmatched + $unchecked) > 0 ? 'without_contract' : $primaryDetailTab]),
            'ambiguousUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => $ambiguous > 0 ? 'ambiguous' : $primaryDetailTab]),
            'historyUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => $history > 0 ? 'history' : 'all']),
            'allUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => 'all']),
        ];
    }

    /**
     * @return list<array{note:string,count:int}>
     */
    private function topIssueNotes(Builder $oneCQuery): array
    {
        return (clone $oneCQuery)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('contract_link_status')
                    ->orWhereIn('contract_link_status', [
                        TenantAccrual::CONTRACT_LINK_STATUS_UNMATCHED,
                        TenantAccrual::CONTRACT_LINK_STATUS_AMBIGUOUS,
                    ]);
            })
            ->selectRaw("COALESCE(contract_link_note, 'Без комментария') as note, COUNT(*) as rows_count")
            ->groupBy('note')
            ->orderByDesc('rows_count')
            ->limit(3)
            ->get()
            ->map(static fn (object $row): array => [
                'note' => (string) $row->note,
                'count' => (int) $row->rows_count,
            ])
            ->all();
    }

    private function resolveLatestPeriodLabel(Builder $oneCQuery, Builder $baseQuery): string
    {
        $latestPeriod = (clone $oneCQuery)->max('period');

        if (! filled($latestPeriod)) {
            $latestPeriod = (clone $baseQuery)->max('period');
        }

        if (! filled($latestPeriod)) {
            return 'Нет данных';
        }

        try {
            return Carbon::parse((string) $latestPeriod)->format('m.Y');
        } catch (\Throwable) {
            return (string) $latestPeriod;
        }
    }

    /**
     * @return array{
     *     latestPeriodLabel: string,
     *     sourceLabel: string,
     *     needsRefresh: bool,
     *     statusLabel: string
     * }
     */
    private function resolveDetailState(Builder $oneCQuery, Builder $baseQuery, ?string $latestOperationalPeriod): array
    {
        $latestOneCPeriod = (clone $oneCQuery)->max('period');
        $latestAnyPeriod = (clone $baseQuery)->max('period');
        $latestLabel = $this->resolveLatestPeriodLabel($oneCQuery, $baseQuery);
        $sourceLabel = filled($latestOneCPeriod) ? 'детализация начислений' : 'исторический слой';
        $needsRefresh = false;
        $statusLabel = 'Актуальна';

        if (filled($latestOneCPeriod) && is_string($latestOneCPeriod)) {
            try {
                $latestDetailMonth = Carbon::parse((string) $latestOneCPeriod)->startOfMonth();
                $targetMonth = filled($latestOperationalPeriod)
                    ? Carbon::parse((string) $latestOperationalPeriod . '-01')->startOfMonth()
                    : Carbon::now()->startOfMonth();

                $needsRefresh = $latestDetailMonth->lt($targetMonth);
            } catch (\Throwable) {
                $needsRefresh = false;
            }
        } elseif (filled($latestAnyPeriod) && is_string($latestAnyPeriod)) {
            $sourceLabel = 'исторический слой';
            $needsRefresh = filled($latestOperationalPeriod);
        } elseif (filled($latestOperationalPeriod)) {
            $needsRefresh = true;
        }

        if ($needsRefresh) {
            $statusLabel = filled($latestOneCPeriod) ? 'Требует обновления' : '1С-детализация не загружена';
        }

        return [
            'latestPeriodLabel' => $latestLabel,
            'sourceLabel' => $sourceLabel,
            'needsRefresh' => $needsRefresh,
            'statusLabel' => $statusLabel,
        ];
    }

    /**
     * @return array{
     *     ready: bool,
     *     period: string|null,
     *     periodLabel: string,
     *     snapshotAtLabel: string,
     *     rows: int,
     *     accrued: string,
     *     paid: string,
     *     debt: string
     * }
     */
    private function resolveOperationalSnapshot(int $marketId): array
    {
        $empty = [
            'ready' => false,
            'period' => null,
            'periodLabel' => 'Нет данных',
            'snapshotAtLabel' => 'Нет обмена',
            'rows' => 0,
            'accrued' => '—',
            'paid' => '—',
            'debt' => '—',
        ];

        if ($marketId <= 0 || ! Schema::hasTable('contract_debts')) {
            return $empty;
        }

        try {
            $latestPeriod = DB::table('contract_debts')
                ->where('market_id', $marketId)
                ->max('period');
        } catch (\Throwable) {
            return $empty;
        }

        if (! is_string($latestPeriod) || ! preg_match('/^\d{4}-\d{2}$/', $latestPeriod)) {
            return $empty;
        }

        try {
            $rows = DB::table('contract_debts')
                ->where('market_id', $marketId)
                ->where('period', $latestPeriod)
                ->orderBy('contract_external_id')
                ->orderByDesc('calculated_at')
                ->get([
                    'contract_external_id',
                    'accrued_amount',
                    'paid_amount',
                    'debt_amount',
                    'calculated_at',
                ]);
        } catch (\Throwable) {
            return $empty;
        }

        $latestByContract = [];

        foreach ($rows as $row) {
            $contractExternalId = trim((string) ($row->contract_external_id ?? ''));

            if ($contractExternalId === '' || array_key_exists($contractExternalId, $latestByContract)) {
                continue;
            }

            $latestByContract[$contractExternalId] = $row;
        }

        if ($latestByContract === []) {
            return $empty;
        }

        $accrued = 0.0;
        $paid = 0.0;
        $debt = 0.0;
        $latestCalculatedAt = null;

        foreach ($latestByContract as $row) {
            $accrued += (float) ($row->accrued_amount ?? 0);
            $paid += (float) ($row->paid_amount ?? 0);
            $debt += (float) ($row->debt_amount ?? 0);

            $candidate = $row->calculated_at ?? null;

            if ($candidate && ($latestCalculatedAt === null || (string) $candidate > (string) $latestCalculatedAt)) {
                $latestCalculatedAt = (string) $candidate;
            }
        }

        return [
            'ready' => true,
            'period' => $latestPeriod,
            'periodLabel' => $this->formatMonthLabel($latestPeriod),
            'snapshotAtLabel' => $this->formatDateTimeLabel($latestCalculatedAt),
            'rows' => count($latestByContract),
            'accrued' => $this->formatMoney($accrued),
            'paid' => $this->formatMoney($paid),
            'debt' => $this->formatMoney($debt),
        ];
    }

    private function resolvePrimaryDetailTab(int $oneC, int $history): string
    {
        if ($oneC > 0) {
            return 'one_c';
        }

        if ($history > 0) {
            return 'history';
        }

        return 'all';
    }

    private function formatMonthLabel(?string $value): string
    {
        if (! filled($value)) {
            return 'Нет данных';
        }

        try {
            return Carbon::parse((string) $value . '-01')->format('m.Y');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatDateTimeLabel(?string $value): string
    {
        if (! filled($value)) {
            return 'Нет обмена';
        }

        try {
            return Carbon::parse((string) $value)->format('d.m.Y H:i');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 0, ',', ' ') . ' ₽';
    }

    private function resolveMarketId(): int
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return 0;
        }

        if (! $user->isSuperAdmin()) {
            return (int) ($user->market_id ?: 0);
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

        $fallback = DB::table('markets')->orderBy('name')->value('id');

        return $fallback ? (int) $fallback : 0;
    }
}
