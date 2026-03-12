<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantContractResource;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class TenantContractsWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tenant-contracts-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return TenantContractResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $baseQuery = TenantContractResource::getEloquentQuery();
        $operationalQuery = TenantContractResource::applyOperationalContractsScope((clone $baseQuery), true);
        $latestDebtQuery = TenantContractResource::applyLatestDebtSnapshotScope((clone $baseQuery), true);
        $latestAccrualQuery = TenantContractResource::applyAccrualHistoryScope((clone $baseQuery), true);

        $operationalCount = (clone $operationalQuery)->count();
        $withoutSpaceCount = (clone $operationalQuery)->whereNull('market_space_id')->count();
        $manualCount = (clone $operationalQuery)
            ->where('space_mapping_mode', \App\Models\TenantContract::SPACE_MAPPING_MODE_MANUAL)
            ->count();
        $excludedCount = (clone $baseQuery)
            ->where('space_mapping_mode', \App\Models\TenantContract::SPACE_MAPPING_MODE_EXCLUDED)
            ->count();
        $latestDebtCount = (clone $latestDebtQuery)->count();
        $latestAccrualCount = (clone $latestAccrualQuery)->count();

        return [
            'marketName' => $market?->name,
            'operationalCount' => $operationalCount,
            'withoutSpaceCount' => $withoutSpaceCount,
            'manualCount' => $manualCount,
            'excludedCount' => $excludedCount,
            'latestDebtCount' => $latestDebtCount,
            'latestAccrualCount' => $latestAccrualCount,
            'operationalUrl' => $this->tabUrl('operational'),
            'withoutSpaceUrl' => $this->tabUrl('operational_unmapped'),
            'allUrl' => TenantContractResource::getUrl('index'),
            'latestDebtUrl' => $this->tabUrl('financial'),
            'accrualsUrl' => $this->tabUrl('accruals'),
            'mappingUrl' => $this->tabUrl('mapping_candidates'),
            'overlapsUrl' => $this->tabUrl('overlaps'),
            'reviewUrl' => $this->tabUrl('review'),
            'canSeeTechnicalTabs' => $this->canSeeTechnicalTabs(),
        ];
    }

    private function tabUrl(string $tab): string
    {
        return TenantContractResource::getUrl('index') . '?activeTab=' . urlencode($tab);
    }

    private function canSeeTechnicalTabs(): bool
    {
        $user = Filament::auth()->user();

        return (bool) $user
            && method_exists($user, 'isSuperAdmin')
            && $user->isSuperAdmin();
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
