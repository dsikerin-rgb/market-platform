<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\MarketSpaceResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
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
        return [
            'createUrl' => MarketSpaceResource::canCreate() ? MarketSpaceResource::getUrl('create') : null,
            'contractsUrl' => TenantContractResource::getUrl('index'),
            'tenantsUrl' => TenantResource::getUrl('index'),
        ];
    }
}
