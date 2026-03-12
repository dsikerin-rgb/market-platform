<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Market;
use App\Models\TenantAccrual;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            'latestPeriodLabel' => $this->resolveLatestPeriodLabel($oneCQuery, $baseQuery),
            'issues' => $this->topIssueNotes($oneCQuery),
            'oneCUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => 'one_c']),
            'linkedUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => 'linked']),
            'withoutContractUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => 'without_contract']),
            'ambiguousUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => 'ambiguous']),
            'historyUrl' => TenantAccrualResource::getUrl('index', ['activeTab' => 'history']),
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
