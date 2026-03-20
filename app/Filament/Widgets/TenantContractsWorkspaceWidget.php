<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\TenantContractResource;
use Filament\Widgets\Widget;

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
        return [];
    }
}
