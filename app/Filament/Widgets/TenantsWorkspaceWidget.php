<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Requests;
use App\Filament\Resources\TenantAccruals\TenantAccrualResource;
use App\Filament\Resources\TenantContractResource;
use App\Filament\Resources\TenantResource;
use Filament\Widgets\Widget;

class TenantsWorkspaceWidget extends Widget
{
    protected string $view = 'filament.widgets.tenants-workspace-widget';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -100;

    public static function canView(): bool
    {
        return TenantResource::canViewAny();
    }

    protected function getViewData(): array
    {
        return [
            'createUrl' => TenantResource::canCreate() ? TenantResource::getUrl('create') : null,
            'contractsUrl' => TenantContractResource::getUrl('index'),
            'accrualsUrl' => TenantAccrualResource::getUrl('index'),
            'requestsUrl' => Requests::getUrl(),
        ];
    }
}
