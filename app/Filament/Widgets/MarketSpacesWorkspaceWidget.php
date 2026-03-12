<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use App\Models\Market;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

class MarketSpacesWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.market-spaces-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return MarketSpaceResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $marketId = $this->resolveMarketId();
        $market = $marketId > 0
            ? Market::query()->select(['id', 'name'])->find($marketId)
            : null;

        $spacesQuery = MarketSpaceResource::getEloquentQuery();

        $total = (clone $spacesQuery)->count();
        $occupied = (clone $spacesQuery)->where('status', 'occupied')->count();
        $vacant = (clone $spacesQuery)->where('status', 'vacant')->count();
        $maintenance = (clone $spacesQuery)->where('status', 'maintenance')->count();
        $grouped = (clone $spacesQuery)->whereNotNull('space_group_token')->count();

        return [
            'marketName' => $market?->name,
            'total' => $total,
            'occupied' => $occupied,
            'vacant' => $vacant,
            'maintenance' => $maintenance,
            'grouped' => $grouped,
            'allUrl' => MarketSpaceResource::getUrl('index'),
            'createUrl' => MarketSpaceResource::canCreate() ? MarketSpaceResource::getUrl('create') : null,
            'contractsUrl' => TenantContractResource::getUrl('index'),
            'tenantsUrl' => TenantResource::getUrl('index'),
        ];
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
