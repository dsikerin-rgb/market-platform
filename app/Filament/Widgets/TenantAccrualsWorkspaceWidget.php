<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

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

        $query = TenantAccrualResource::getEloquentQuery();
        $total = (clone $query)->count();
        $oneC = (clone $query)->where('source', '1c')->count();
        $withoutContract = (clone $query)->whereNull('tenant_contract_id')->count();
        $history = (clone $query)->where('source', '!=', '1c')->count();
        $latestPeriod = (clone $query)->max('period');
        $latestPeriodLabel = $latestPeriod
            ? \Illuminate\Support\Carbon::parse($latestPeriod)->translatedFormat('m.Y')
            : 'Нет данных';

        return [
            'marketName' => $market?->name,
            'total' => $total,
            'oneC' => $oneC,
            'withoutContract' => $withoutContract,
            'history' => $history,
            'latestPeriodLabel' => $latestPeriodLabel,
            'allUrl' => TenantAccrualResource::getUrl('index'),
            'oneCUrl' => $this->tabUrl('one_c'),
            'withoutContractUrl' => $this->tabUrl('without_contract'),
            'historyUrl' => $this->tabUrl('history'),
        ];
    }

    private function tabUrl(string $tab): string
    {
        return TenantAccrualResource::getUrl('index') . '?activeTab=' . urlencode($tab);
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
        $value = session("filament_{$panelId}_market_id");

        return filled($value) ? (int) $value : 0;
    }
}
